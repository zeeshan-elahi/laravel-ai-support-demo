<?php

namespace App\Invoices;

/**
 * What came back from one extraction call. `output` is the decoded JSON, and is
 * only set when the model finished normally (end_turn): a refused or truncated
 * reply is never parsed.
 */
final readonly class ExtractionResult
{
	/**
	 * @param  array<string, mixed>|null  $output
	 */
	public function __construct(
		public string $model,
		public ?string $stopReason,
		public ?array $output,
		public int $inputTokens,
		public int $outputTokens,
		public ?string $refusalCategory = null,
	) {}
}
