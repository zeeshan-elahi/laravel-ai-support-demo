<?php

namespace App\Services\Rag;

use Anthropic\Client;
use App\Models\Chunk;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Answers a question from the stored documents.
 *
 * Retrieval: the question is embedded and the closest chunks are pulled by cosine distance.
 * Answering: those chunks go to Claude as plain-text documents with citations enabled, so
 * every claim in the answer comes back tied to the passage it was drawn from.
 * An answer with no citations is treated as unsupported and not shown as an answer.
 */
class Answerer
{
	private const SYSTEM_PROMPT = <<<'PROMPT'
		You answer questions using only the documents provided in the user's message.
		Each document is one excerpt from a file the user uploaded; its title names the file and, for PDFs, the page.

		Answer in plain, direct sentences. Base every statement on the documents and nothing else.
		If the documents do not contain the answer, say that the documents don't cover it, in one sentence,
		and do not answer from general knowledge. If the documents only partly answer the question,
		answer the part they cover and say what is missing.
		PROMPT;

	public function __construct(
		private readonly Client $client,
		private readonly VoyageEmbedder $embedder,
		private readonly string $model,
		private readonly int $topK,
	) {}

	public static function fromConfig(): self
	{
		$key = (string) config('services.anthropic.key');

		if ($key === '') {
			throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
		}

		return new self(
			client: new Client(apiKey: $key),
			embedder: VoyageEmbedder::fromConfig(),
			model: (string) config('rag.answer_model'),
			topK: (int) config('rag.top_k'),
		);
	}

	/**
	 * @return array{
	 *     question: string,
	 *     supported: bool,
	 *     refused: bool,
	 *     segments: array<int, array{text: string, sources: array<int, int>}>,
	 *     sources: array<int, array{number: int, document: string, page: int|null, quote: string}>,
	 *     retrieved: array<int, array{document: string, page: int|null, distance: float}>,
	 *     model: string,
	 *     input_tokens: int,
	 *     output_tokens: int,
	 * }
	 */
	public function ask(string $question): array
	{
		$question = trim($question);

		if ($question === '') {
			throw new RuntimeException('Ask a question first.');
		}

		$chunks = $this->retrieve($question);

		if ($chunks->isEmpty()) {
			throw new RuntimeException('No documents have been ingested yet. Upload one first.');
		}

		$message = $this->client->beta->messages->create(
			maxTokens: 8000,
			model: $this->model,
			system: self::SYSTEM_PROMPT,
			messages: [[
				'role' => 'user',
				'content' => [
					...$chunks->map(fn (Chunk $chunk) => [
						'type' => 'document',
						'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => $chunk->content],
						'title' => $this->title($chunk),
						'citations' => ['enabled' => true],
					])->all(),
					['type' => 'text', 'text' => $question],
				],
			]],
			outputConfig: ['effort' => 'medium'],
			fallbacks: 'default',
			betas: ['server-side-fallback-2026-07-01'],
		);

		$result = [
			'question' => $question,
			'supported' => false,
			'refused' => $message->stopReason === 'refusal',
			'segments' => [],
			'sources' => [],
			'retrieved' => $chunks->map(fn (Chunk $chunk) => [
				'document' => $chunk->document->name,
				'page' => $chunk->page_number,
				'distance' => round((float) $chunk->distance, 4),
			])->all(),
			'model' => $message->model,
			'input_tokens' => $message->usage->inputTokens,
			'output_tokens' => $message->usage->outputTokens,
		];

		if ($result['refused']) {
			return $result;
		}

		// Number each distinct cited passage once, in order of first use.
		$sourceNumbers = [];

		foreach ($message->content as $block) {
			if ($block->type !== 'text') {
				continue;
			}

			$numbers = [];

			foreach ($block->citations ?? [] as $citation) {
				$chunk = $chunks->get($citation->documentIndex);

				if ($chunk === null) {
					continue;
				}

				$key = $citation->documentIndex.'|'.$citation->citedText;

				if (! isset($sourceNumbers[$key])) {
					$sourceNumbers[$key] = count($sourceNumbers) + 1;
					$result['sources'][] = [
						'number' => $sourceNumbers[$key],
						'document' => $chunk->document->name,
						'page' => $chunk->page_number,
						'quote' => trim($citation->citedText),
					];
				}

				$numbers[] = $sourceNumbers[$key];
			}

			$result['segments'][] = ['text' => $block->text, 'sources' => array_values(array_unique($numbers))];
		}

		$result['supported'] = $result['sources'] !== [];

		return $result;
	}

	/**
	 * @return Collection<int, Chunk>
	 */
	private function retrieve(string $question): Collection
	{
		$vector = $this->embedder->embedQuery($question);

		return Chunk::query()
			->with('document')
			->select(['id', 'document_id', 'chunk_index', 'page_number', 'content'])
			->selectVectorDistance('embedding', $vector, 'distance')
			->orderByVectorDistance('embedding', $vector)
			->limit($this->topK)
			->get()
			->values();
	}

	private function title(Chunk $chunk): string
	{
		return $chunk->page_number === null
			? $chunk->document->name
			: "{$chunk->document->name}, page {$chunk->page_number}";
	}
}
