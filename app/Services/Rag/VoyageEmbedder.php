<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Voyage AI embeddings over REST (POST /v1/embeddings).
 * Documents and questions use different input_type values, which Voyage
 * recommends for retrieval.
 */
class VoyageEmbedder
{
	/** Voyage allows up to 1,000 texts per request. */
	private const MAX_BATCH_TEXTS = 128;

	/** On a 429, wait long enough for a per-minute window to move on (free accounts get 3 requests a minute). */
	private const RATE_LIMIT_WAIT_MS = 21_000;

	private const MAX_ATTEMPTS = 4;

	/**
	 * @param  int  $maxBatchTokens  Estimated tokens per request. Free Voyage accounts (no payment
	 *                               method) are limited to 10K tokens a minute, so the default stays under that.
	 */
	public function __construct(
		private readonly string $apiKey,
		private readonly string $url,
		private readonly string $model,
		private readonly int $dimensions,
		private readonly int $maxBatchTokens = 9_000,
	) {}

	public static function fromConfig(): self
	{
		$key = (string) config('services.voyage.key');

		if ($key === '') {
			throw new RuntimeException('VOYAGE_API_KEY is not set.');
		}

		return new self(
			apiKey: $key,
			url: (string) config('services.voyage.url'),
			model: (string) config('rag.embeddings.model'),
			dimensions: (int) config('rag.embeddings.dimensions'),
			maxBatchTokens: (int) config('rag.embeddings.max_batch_tokens', 9_000),
		);
	}

	/**
	 * @param  array<int, string>  $texts
	 * @return array{embeddings: array<int, array<int, float>>, tokens: int}
	 */
	public function embedDocuments(array $texts): array
	{
		$embeddings = [];
		$tokens = 0;

		foreach ($this->batches($texts) as $batch) {
			$result = $this->request($batch, 'document');
			array_push($embeddings, ...$result['embeddings']);
			$tokens += $result['tokens'];
		}

		return ['embeddings' => $embeddings, 'tokens' => $tokens];
	}

	/**
	 * Groups texts so each request stays under both the text-count and the token budget.
	 *
	 * @param  array<int, string>  $texts
	 * @return array<int, array<int, string>>
	 */
	private function batches(array $texts): array
	{
		$batches = [];
		$current = [];
		$currentTokens = 0;

		foreach ($texts as $text) {
			$tokens = Chunker::estimateTokens($text);

			if ($current !== [] && ($currentTokens + $tokens > $this->maxBatchTokens || count($current) >= self::MAX_BATCH_TEXTS)) {
				$batches[] = $current;
				$current = [];
				$currentTokens = 0;
			}

			$current[] = $text;
			$currentTokens += $tokens;
		}

		if ($current !== []) {
			$batches[] = $current;
		}

		return $batches;
	}

	/**
	 * @return array<int, float>
	 */
	public function embedQuery(string $question): array
	{
		return $this->request([$question], 'query')['embeddings'][0];
	}

	/**
	 * @param  array<int, string>  $input
	 * @return array{embeddings: array<int, array<int, float>>, tokens: int}
	 */
	private function request(array $input, string $inputType): array
	{
		try {
			$response = Http::withToken($this->apiKey)
				->acceptJson()
				->timeout(60)
				->retry(
					self::MAX_ATTEMPTS,
					fn (int $attempt, mixed $exception) => $this->isRateLimited($exception) ? self::RATE_LIMIT_WAIT_MS : 2_000 * $attempt,
					fn ($exception) => $this->isRetryable($exception),
				)
				->post($this->url, [
					'input' => $input,
					'model' => $this->model,
					'input_type' => $inputType,
					'output_dimension' => $this->dimensions,
				])
				->throw();
		} catch (RequestException $e) {
			$detail = $e->response->json('detail') ?? $e->getMessage();

			throw new RuntimeException("Voyage embeddings request failed ({$e->response->status()}): {$detail}", 0, $e);
		}

		$data = collect($response->json('data', []))->sortBy('index')->values();

		if ($data->count() !== count($input)) {
			throw new RuntimeException('Voyage returned '.$data->count().' embeddings for '.count($input).' inputs.');
		}

		$embeddings = $data->map(function (array $item) {
			if (count($item['embedding']) !== $this->dimensions) {
				throw new RuntimeException('Voyage returned an embedding of '.count($item['embedding'])." numbers, expected {$this->dimensions}.");
			}

			return array_map('floatval', $item['embedding']);
		})->all();

		return [
			'embeddings' => $embeddings,
			'tokens' => (int) $response->json('usage.total_tokens', 0),
		];
	}

	private function isRateLimited(mixed $exception): bool
	{
		return $exception instanceof RequestException && $exception->response->status() === 429;
	}

	private function isRetryable(mixed $exception): bool
	{
		if (! $exception instanceof RequestException) {
			return true; // connection errors
		}

		$status = $exception->response->status();

		return $status === 429 || $status >= 500;
	}
}
