<?php

namespace App\Jobs;

use Anthropic\Core\Exceptions\APIException;
use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Enums\InvoiceStatus;
use App\Invoices\Contracts\InvoiceExtractor;
use App\Invoices\ExtractionResult;
use App\Invoices\InvoiceReviewer;
use App\Invoices\Money;
use App\Invoices\Review;
use App\Models\Invoice;
use App\Support\TokenCost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Extracts one invoice: model call, log the call, review the output in code,
 * store the fields and lines. Every call is logged, failed ones included.
 *
 * One attempt only: the SDK already retries 429/5xx and connection errors, and a
 * queue retry on top would pay for the same call again.
 */
class ExtractInvoice implements ShouldQueue
{
	use Queueable;

	public int $tries = 1;

	public function __construct(
		public readonly int $invoiceId,
		public readonly ?string $model = null,
	) {}

	public function handle(InvoiceExtractor $extractor, InvoiceReviewer $reviewer): void
	{
		$invoice = Invoice::findOrFail($this->invoiceId);
		$model = $this->model ?? (string) config('invoices.model');
		$pdf = Storage::disk(config('invoices.disk'))->get($invoice->source_path)
			?? throw new RuntimeException("Stored PDF missing: {$invoice->source_path}");

		$started = hrtime(true);

		try {
			$result = $extractor->extract($pdf, $model);
		} catch (APIException $e) {
			$invoice->extractions()->create([
				'model' => $model,
				'effort' => config('invoices.effort'),
				'status' => ExtractionStatus::Failed,
				'latency_ms' => $this->elapsedMs($started),
				'error' => class_basename($e) . ': ' . $e->getMessage(),
			]);
			$this->markFailed($invoice, ExtractionStatus::Failed);

			return;
		}

		$status = match (true) {
			$result->stopReason === 'refusal' => ExtractionStatus::Refused,
			$result->stopReason === 'max_tokens' => ExtractionStatus::Truncated,
			$result->output !== null => ExtractionStatus::Completed,
			default => ExtractionStatus::Failed,
		};

		$invoice->extractions()->create([
			'model' => $result->model,
			'effort' => config('invoices.effort'),
			'status' => $status,
			'stop_reason' => $result->stopReason,
			'input_tokens' => $result->inputTokens,
			'output_tokens' => $result->outputTokens,
			'latency_ms' => $this->elapsedMs($started),
			'cost_usd' => TokenCost::usd($result->model, $result->inputTokens, $result->outputTokens),
			'output' => $result->output,
			'error' => match ($status) {
				ExtractionStatus::Refused => 'Refused' . ($result->refusalCategory ? " ({$result->refusalCategory})" : ''),
				ExtractionStatus::Failed => "No JSON output (stop reason {$result->stopReason}).",
				default => null,
			},
		]);

		if ($status !== ExtractionStatus::Completed) {
			$this->markFailed($invoice, $status);

			return;
		}

		$this->store($invoice, $result, $reviewer->review($result->output));
	}

	private function store(Invoice $invoice, ExtractionResult $result, Review $review): void
	{
		$data = $review->data;
		$money = fn (string $key) => $data === null ? null : Money::fromDecimal($data[$key]);

		DB::transaction(function () use ($invoice, $result, $review, $data, $money) {
			// A re-extraction replaces everything from the previous one.
			$invoice->lines()->delete();

			$invoice->fill([
				'status' => $review->status,
				'failed_checks' => $review->failedChecks,
				'document_type' => DocumentType::tryFrom((string) ($result->output['document_type'] ?? '')),
				'invoice_number' => $data['invoice_number'] ?? null,
				'issue_date' => $data['issue_date'] ?? null,
				'due_date' => $data['due_date'] ?? null,
				'supplier_name' => $data['supplier']['name'] ?? null,
				'supplier_trn' => $data['supplier']['trn'] ?? null,
				'customer_name' => $data['customer']['name'] ?? null,
				'customer_trn' => $data['customer']['trn'] ?? null,
				'currency' => $data['currency'] ?? null,
				'subtotal' => $money('subtotal'),
				'discount' => $money('discount'),
				'vat_rate' => $data['vat_rate'] ?? null,
				'vat_amount' => $money('vat_amount'),
				'total' => $money('total'),
			])->save();

			foreach ($data['lines'] ?? [] as $i => $line) {
				$invoice->lines()->create([
					'position' => $i + 1,
					'description' => $line['description'],
					'quantity' => $line['quantity'],
					'unit_price' => Money::fromDecimal($line['unit_price']),
					'line_total' => Money::fromDecimal($line['line_total']),
				]);
			}
		});
	}

	private function markFailed(Invoice $invoice, ExtractionStatus $status): void
	{
		$invoice->update([
			'status' => InvoiceStatus::Failed,
			'failed_checks' => ['extraction:' . $status->value],
		]);
	}

	private function elapsedMs(int|float $started): int
	{
		return (int) round((hrtime(true) - $started) / 1_000_000);
	}
}
