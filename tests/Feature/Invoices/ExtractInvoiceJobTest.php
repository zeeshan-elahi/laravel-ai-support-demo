<?php

namespace Tests\Feature\Invoices;

use Anthropic\Core\Exceptions\APIConnectionException;
use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Enums\InvoiceStatus;
use App\Invoices\Contracts\InvoiceExtractor;
use App\Invoices\ExtractionResult;
use App\Invoices\InvoiceIntake;
use App\Invoices\Money;
use App\Jobs\ExtractInvoice;
use App\Models\Invoice;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeInvoiceExtractor;
use Tests\TestCase;

class ExtractInvoiceJobTest extends TestCase
{
	use RefreshDatabase;

	private FakeInvoiceExtractor $extractor;

	protected function setUp(): void
	{
		parent::setUp();

		Storage::fake('local');
		$this->extractor = new FakeInvoiceExtractor();
		$this->app->instance(InvoiceExtractor::class, $this->extractor);
	}

	public function test_intake_stores_the_pdf_by_hash_and_creates_a_pending_invoice(): void
	{
		$invoice = $this->upload('inv-01-qamar-grove-office-supplies.pdf');
		$sha = hash_file('sha256', $this->sample('inv-01-qamar-grove-office-supplies.pdf'));

		$this->assertSame(InvoiceStatus::Pending, $invoice->status);
		$this->assertSame($sha, $invoice->source_sha256);
		$this->assertSame('inv-01-qamar-grove-office-supplies.pdf', $invoice->original_name);
		Storage::disk('local')->assertExists("invoices/{$sha}.pdf");
	}

	public function test_an_accepted_invoice_stores_fields_lines_and_the_logged_call(): void
	{
		$invoice = $this->upload('inv-01-qamar-grove-office-supplies.pdf');
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('inv-01-qamar-grove-office-supplies.json'));

		ExtractInvoice::dispatchSync($invoice->id);

		$invoice = $invoice->fresh(['lines', 'extractions']);
		$this->assertSame(InvoiceStatus::Accepted, $invoice->status);
		$this->assertSame([], $invoice->failed_checks);
		$this->assertSame(DocumentType::Invoice, $invoice->document_type);
		$this->assertSame('QG-2026-0418', $invoice->invoice_number);
		$this->assertSame('2026-11-03', $invoice->due_date->toDateString());
		$this->assertSame('100234567800003', $invoice->supplier_trn);
		$this->assertEquals(new Money(176138), $invoice->total);
		$this->assertEquals(new Money(0), $invoice->discount);
		$this->assertCount(4, $invoice->lines);
		$this->assertSame('Ballpoint pens, blue (pack of 50)', $invoice->lines[1]->description);
		$this->assertEquals(new Money(17000), $invoice->lines[1]->line_total);

		$extraction = $invoice->extractions->sole();
		$this->assertSame(ExtractionStatus::Completed, $extraction->status);
		$this->assertSame('claude-haiku-5-5', $extraction->model);
		$this->assertSame(2400, $extraction->input_tokens);
		$this->assertSame('0.000540', $extraction->cost_usd);
		$this->assertSame('invoice', $extraction->output['document_type']);

		$this->assertSame('claude-haiku-5-5', $this->extractor->calls[0]['model']);
		$this->assertSame(filesize($this->sample('inv-01-qamar-grove-office-supplies.pdf')), $this->extractor->calls[0]['bytes']);
	}

	public function test_a_printed_total_that_does_not_add_up_needs_review(): void
	{
		$invoice = $this->upload('inv-06-coral-spindle-wrong-total.pdf');
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('inv-06-coral-spindle-wrong-total.json'));

		ExtractInvoice::dispatchSync($invoice->id);

		$invoice->refresh();
		$this->assertSame(InvoiceStatus::NeedsReview, $invoice->status);
		$this->assertSame(['total'], $invoice->failed_checks);
		$this->assertEquals(new Money(684625), $invoice->total);
	}

	public function test_a_quotation_is_rejected_without_fields_or_lines(): void
	{
		$invoice = $this->upload('quote-09-qamar-grove-quotation.pdf');
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('quote-09-qamar-grove-quotation.json'));

		ExtractInvoice::dispatchSync($invoice->id);

		$invoice = $invoice->fresh('lines');
		$this->assertSame(InvoiceStatus::Rejected, $invoice->status);
		$this->assertSame(DocumentType::NotInvoice, $invoice->document_type);
		$this->assertNull($invoice->invoice_number);
		$this->assertCount(0, $invoice->lines);
	}

	public function test_a_refusal_is_logged_and_never_parsed(): void
	{
		$invoice = $this->upload('inv-01-qamar-grove-office-supplies.pdf');
		$this->extractor->push(new ExtractionResult('claude-haiku-5-5', 'refusal', null, 2400, 5, refusalCategory: 'cyber'));

		ExtractInvoice::dispatchSync($invoice->id);

		$invoice = $invoice->fresh('extractions');
		$this->assertSame(InvoiceStatus::Failed, $invoice->status);
		$this->assertSame(['extraction:refused'], $invoice->failed_checks);
		$this->assertSame(ExtractionStatus::Refused, $invoice->extractions->sole()->status);
		$this->assertSame('Refused (cyber)', $invoice->extractions->sole()->error);
		$this->assertNull($invoice->extractions->sole()->output);
	}

	public function test_a_truncated_reply_fails_the_invoice(): void
	{
		$invoice = $this->upload('inv-04-dune-lantern-multi-page.pdf');
		$this->extractor->push(new ExtractionResult('claude-haiku-5-5', 'max_tokens', null, 5200, 16000));

		ExtractInvoice::dispatchSync($invoice->id);

		$this->assertSame(['extraction:truncated'], $invoice->fresh()->failed_checks);
	}

	public function test_an_api_error_is_logged_with_its_class_and_fails_the_invoice(): void
	{
		$invoice = $this->upload('inv-01-qamar-grove-office-supplies.pdf');
		$this->extractor->push(new APIConnectionException(new Request('POST', 'https://api.anthropic.com/v1/messages'), message: 'Connection refused'));

		ExtractInvoice::dispatchSync($invoice->id);

		$invoice = $invoice->fresh('extractions');
		$this->assertSame(InvoiceStatus::Failed, $invoice->status);
		$this->assertSame(['extraction:failed'], $invoice->failed_checks);
		// The SDK prefixes its own description to the message.
		$this->assertStringStartsWith('APIConnectionException: Anthropic API Connection Error', $invoice->extractions->sole()->error);
		$this->assertSame(0, $invoice->extractions->sole()->input_tokens);
	}

	public function test_a_second_extraction_replaces_the_first_and_keeps_both_logs(): void
	{
		$invoice = $this->upload('inv-01-qamar-grove-office-supplies.pdf');
		$output = FakeInvoiceExtractor::groundTruth('inv-01-qamar-grove-office-supplies.json')->output;
		$fewerLines = $output;
		$fewerLines['lines'] = array_slice($output['lines'], 0, 2);
		$this->extractor->push(
			FakeInvoiceExtractor::returning($output),
			FakeInvoiceExtractor::returning($fewerLines, model: 'claude-opus-5-5'),
		);

		ExtractInvoice::dispatchSync($invoice->id);
		ExtractInvoice::dispatchSync($invoice->id, 'claude-opus-5-5');

		$invoice = $invoice->fresh(['lines', 'extractions']);
		$this->assertCount(2, $invoice->lines);
		$this->assertSame(['subtotal'], $invoice->failed_checks);
		$this->assertSame(['claude-haiku-5-5', 'claude-opus-5-5'], $invoice->extractions->pluck('model')->all());
		$this->assertSame('claude-opus-5-5', $this->extractor->calls[1]['model']);
	}

	private function upload(string $file): Invoice
	{
		return app(InvoiceIntake::class)->fromPath($this->sample($file));
	}

	private function sample(string $file): string
	{
		return base_path("resources/invoice-eval/samples/{$file}");
	}
}
