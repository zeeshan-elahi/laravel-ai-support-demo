<?php

namespace Tests\Fakes;

use App\Invoices\Contracts\InvoiceExtractor;
use App\Invoices\ExtractionResult;
use RuntimeException;
use Throwable;

/**
 * Scripted stand-in for the model: returns queued results (or throws queued
 * exceptions) in order and records every call.
 */
class FakeInvoiceExtractor implements InvoiceExtractor
{
	/**
	 * @var list<ExtractionResult|Throwable>
	 */
	private array $queue = [];

	/**
	 * @var list<array{model: string, bytes: int}>
	 */
	public array $calls = [];

	public function push(ExtractionResult|Throwable ...$results): self
	{
		array_push($this->queue, ...$results);

		return $this;
	}

	public function extract(string $pdf, string $model): ExtractionResult
	{
		$this->calls[] = ['model' => $model, 'bytes' => strlen($pdf)];

		$next = array_shift($this->queue) ?? throw new RuntimeException('FakeInvoiceExtractor: nothing queued.');

		if ($next instanceof Throwable) {
			throw $next;
		}

		return $next;
	}

	/**
	 * A normal reply carrying the given output.
	 *
	 * @param  array<string, mixed>  $output
	 */
	public static function returning(array $output, string $model = 'claude-haiku-5-5'): ExtractionResult
	{
		return new ExtractionResult($model, 'end_turn', $output, inputTokens: 2400, outputTokens: 600);
	}

	/**
	 * A normal reply carrying a ground-truth file's expected output.
	 */
	public static function groundTruth(string $file): ExtractionResult
	{
		$truth = json_decode(file_get_contents(base_path("resources/invoice-eval/expected/{$file}")), true, flags: JSON_THROW_ON_ERROR);

		return self::returning($truth['expected']);
	}
}
