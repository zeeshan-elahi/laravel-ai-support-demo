<?php

namespace App\Console\Commands;

use App\Invoices\InvoiceIntake;
use App\Jobs\ExtractInvoice;
use App\Models\Invoice;
use Illuminate\Console\Command;

class InvoiceExtractCommand extends Command
{
	protected $signature = 'invoice:extract
		{path : A PDF file}
		{--model= : Model to use instead of the configured default}
		{--sync : Extract now instead of queueing the job}';

	protected $description = 'Extract an invoice PDF into structured data; the code rechecks the arithmetic and TRNs';

	public function handle(InvoiceIntake $intake): int
	{
		$path = (string) $this->argument('path');

		if (! is_file($path) || ! is_readable($path)) {
			$this->error("Not a readable file: {$path}");

			return self::FAILURE;
		}

		if (mime_content_type($path) !== 'application/pdf') {
			$this->error('Not a PDF: ' . mime_content_type($path));

			return self::FAILURE;
		}

		if (filesize($path) > config('invoices.max_upload_kb') * 1024) {
			$this->error('Larger than ' . config('invoices.max_upload_kb') . ' KB.');

			return self::FAILURE;
		}

		$invoice = $intake->fromPath($path);
		$model = $this->option('model') ?: null;

		if (! $this->option('sync')) {
			ExtractInvoice::dispatch($invoice->id, $model);
			$this->line("Invoice #{$invoice->id} queued. Run: php artisan queue:work --once");

			return self::SUCCESS;
		}

		ExtractInvoice::dispatchSync($invoice->id, $model);
		$this->report($invoice->fresh(['lines', 'extractions']));

		return self::SUCCESS;
	}

	private function report(Invoice $invoice): void
	{
		$checks = $invoice->failed_checks === [] ? 'all checks passed' : 'failed: ' . implode(', ', $invoice->failed_checks);
		$this->line("Invoice #{$invoice->id} · {$invoice->original_name} · {$invoice->status->value} · {$checks}");

		if ($invoice->invoice_number !== null) {
			$cur = $invoice->currency;
			$this->newLine();
			$this->line("  {$invoice->invoice_number} · issued {$invoice->issue_date?->toDateString()} · due " . ($invoice->due_date?->toDateString() ?? '—'));
			$this->line("  From {$invoice->supplier_name} (TRN " . ($invoice->supplier_trn ?? '—') . ')');
			$this->line("  To   {$invoice->customer_name} (TRN " . ($invoice->customer_trn ?? '—') . ')');
			$this->newLine();
			$this->table(
				['#', 'Description', 'Qty', 'Unit price', 'Line total'],
				$invoice->lines->map(fn ($line) => [
					$line->position,
					$line->description,
					rtrim(rtrim($line->quantity, '0'), '.'),
					$line->unit_price->format($cur),
					$line->line_total->format($cur),
				])->all(),
			);
			$this->line('  Subtotal ' . $invoice->subtotal?->format($cur)
				. ' · discount ' . $invoice->discount?->format($cur)
				. " · VAT {$invoice->vat_rate}% " . $invoice->vat_amount?->format($cur)
				. ' · total ' . $invoice->total?->format($cur));
		}

		$extraction = $invoice->extractions->last();
		$this->newLine();
		$this->line(sprintf(
			'Extraction #%d · %s · %s · stop %s · %d in / %d out tokens · %d ms · ~$%s%s',
			$extraction->id,
			$extraction->model,
			$extraction->status->value,
			$extraction->stop_reason ?? '—',
			$extraction->input_tokens,
			$extraction->output_tokens,
			$extraction->latency_ms,
			$extraction->cost_usd,
			$extraction->error ? ' · ' . $extraction->error : '',
		));
	}
}
