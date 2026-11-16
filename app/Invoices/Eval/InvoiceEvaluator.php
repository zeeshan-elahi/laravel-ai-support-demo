<?php

namespace App\Invoices\Eval;

use App\Invoices\InvoiceIntake;
use App\Invoices\Money;
use App\Jobs\ExtractInvoice;
use App\Models\Invoice;
use App\Models\InvoiceExtraction;
use App\Models\InvoiceLine;

/**
 * Runs one sample through the real pipeline (intake, extraction job, review) and
 * scores what was stored against the ground truth: the status, the failed checks,
 * and every field and line. Scoring is on the stored invoice, i.e. what a user of
 * the app would get, after the code's checks and normalisation.
 *
 * Comparison rules: names and descriptions ignore case and repeated whitespace
 * (the same name can be printed in capitals in a header and mixed case in a
 * footer); amounts must match to the fil/cent; a missing discount equals 0.
 */
class InvoiceEvaluator
{
	public function __construct(
		private readonly InvoiceIntake $intake,
	) {}

	/**
	 * @return array{
	 *   id: string, passed: bool, status_ok: bool, fields_correct: int, fields_total: int,
	 *   failures: list<string>, invoice: Invoice, extraction: ?InvoiceExtraction
	 * }
	 */
	public function evaluate(string $truthFile, string $model): array
	{
		$truth = json_decode((string) file_get_contents($truthFile), true, flags: JSON_THROW_ON_ERROR);
		$id = basename($truthFile, '.json');

		$invoice = $this->intake->fromPath(resource_path('invoice-eval/samples/' . $truth['file']));
		ExtractInvoice::dispatchSync($invoice->id, $model);
		$invoice = $invoice->fresh(['lines', 'extractions']);

		$failures = [];

		if ($invoice->status->value !== $truth['expected_status']) {
			$failures[] = "status: expected {$truth['expected_status']}, got {$invoice->status->value}";
		}
		if ($invoice->failed_checks !== $truth['expected_failed_checks']) {
			$failures[] = 'failed checks: expected ' . json_encode($truth['expected_failed_checks']) . ', got ' . json_encode($invoice->failed_checks);
		}
		$statusOk = $failures === [];

		[$correct, $total, $fieldFailures] = $truth['expected']['document_type'] === 'invoice'
			? $this->compareFields($truth['expected'], $invoice)
			: [0, 0, []];

		$failures = [...$failures, ...$fieldFailures];

		return [
			'id' => $id,
			'passed' => $failures === [],
			'status_ok' => $statusOk,
			'fields_correct' => $correct,
			'fields_total' => $total,
			'failures' => $failures,
			'invoice' => $invoice,
			'extraction' => $invoice->extractions->last(),
		];
	}

	/**
	 * @param  array<string, mixed>  $expected
	 * @return array{int, int, list<string>}
	 */
	public function compareFields(array $expected, Invoice $invoice): array
	{
		$checks = [
			'invoice_number' => [$expected['invoice_number'], $invoice->invoice_number, 'exact'],
			'issue_date' => [$expected['issue_date'], $invoice->issue_date?->toDateString(), 'exact'],
			'due_date' => [$expected['due_date'], $invoice->due_date?->toDateString(), 'exact'],
			'supplier.name' => [$expected['supplier']['name'], $invoice->supplier_name, 'text'],
			'supplier.trn' => [$expected['supplier']['trn'], $invoice->supplier_trn, 'exact'],
			'customer.name' => [$expected['customer']['name'], $invoice->customer_name, 'text'],
			'customer.trn' => [$expected['customer']['trn'], $invoice->customer_trn, 'exact'],
			'currency' => [$expected['currency'], $invoice->currency, 'exact'],
			'subtotal' => [$expected['subtotal'], $invoice->subtotal, 'money'],
			'discount' => [$expected['discount'], $invoice->discount ?? new Money(0), 'money'],
			'vat_rate' => [$expected['vat_rate'], $invoice->vat_rate, 'number'],
			'vat_amount' => [$expected['vat_amount'], $invoice->vat_amount, 'money'],
			'total' => [$expected['total'], $invoice->total, 'money'],
			'line count' => [count($expected['lines']), $invoice->lines->count(), 'exact'],
		];

		$lines = $invoice->lines->values();
		foreach ($expected['lines'] as $i => $line) {
			/** @var InvoiceLine|null $got */
			$got = $lines[$i] ?? null;
			$n = $i + 1;
			$checks["line {$n} description"] = [$line['description'], $got?->description, 'text'];
			$checks["line {$n} quantity"] = [$line['quantity'], $got?->quantity, 'number'];
			$checks["line {$n} unit_price"] = [$line['unit_price'], $got?->unit_price, 'money'];
			$checks["line {$n} line_total"] = [$line['line_total'], $got?->line_total, 'money'];
		}

		$correct = 0;
		$failures = [];

		foreach ($checks as $field => [$want, $got, $rule]) {
			if ($this->matches($want, $got, $rule)) {
				$correct++;
			} else {
				$failures[] = sprintf('%s: expected %s, got %s', $field, $this->show($want), $this->show($got));
			}
		}

		return [$correct, count($checks), $failures];
	}

	private function matches(mixed $want, mixed $got, string $rule): bool
	{
		if ($want === null || $got === null) {
			return $want === null && $got === null;
		}

		return match ($rule) {
			'exact' => (string) $want === (string) $got,
			'text' => $this->normalise((string) $want) === $this->normalise((string) $got),
			'number' => abs((float) $want - (float) $got) < 0.0005,
			'money' => $got instanceof Money && Money::fromDecimal($want)->minor === $got->minor,
		};
	}

	private function normalise(string $text): string
	{
		return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
	}

	private function show(mixed $value): string
	{
		return match (true) {
			$value === null => 'null',
			$value instanceof Money => number_format($value->minor / 100, 2, '.', ''),
			default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		};
	}
}
