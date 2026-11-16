<?php

namespace Tests\Feature\Invoices;

use App\Invoices\Contracts\InvoiceExtractor;
use App\Jobs\ExtractInvoice;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeInvoiceExtractor;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
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

	public function test_upload_answers_202_with_a_location_and_queues_the_extraction(): void
	{
		Queue::fake();

		$response = $this->post('/api/invoices', ['file' => $this->pdf('inv-01-qamar-grove-office-supplies')], ['Accept' => 'application/json']);

		$invoice = Invoice::sole();
		$response->assertStatus(202)
			->assertHeader('Location', route('invoices.show', $invoice->id))
			->assertJsonPath('data.status', 'pending')
			->assertJsonPath('data.original_name', 'inv-01-qamar-grove-office-supplies.pdf');
		Queue::assertPushed(ExtractInvoice::class, fn (ExtractInvoice $job) => $job->invoiceId === $invoice->id && $job->model === null);
		Storage::disk('local')->assertExists($invoice->source_path);
	}

	public function test_with_the_sync_queue_the_response_carries_the_result(): void
	{
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('inv-06-coral-spindle-wrong-total.json'));

		$this->post('/api/invoices', ['file' => $this->pdf('inv-06-coral-spindle-wrong-total')], ['Accept' => 'application/json'])
			->assertStatus(202)
			->assertJsonPath('data.status', 'needs_review')
			->assertJsonPath('data.failed_checks', ['total'])
			->assertJsonPath('data.total', 6846.25)
			->assertJsonPath('data.lines.0.line_total', 3700);
	}

	public function test_show_returns_fields_lines_and_the_last_extraction(): void
	{
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('inv-02-qamar-grove-discount.json'));
		$this->post('/api/invoices', ['file' => $this->pdf('inv-02-qamar-grove-discount')], ['Accept' => 'application/json']);
		$invoice = Invoice::sole();

		$this->getJson("/api/invoices/{$invoice->id}")
			->assertOk()
			->assertJsonPath('data.status', 'accepted')
			->assertJsonPath('data.supplier', ['name' => 'Qamar Grove Trading LLC', 'trn' => '100234567800003'])
			->assertJsonPath('data.discount', 196.1)
			->assertJsonPath('data.vat_rate', 5)
			->assertJsonCount(6, 'data.lines')
			->assertJsonPath('data.extraction.model', 'claude-haiku-5-5')
			->assertJsonPath('data.extraction.cost_usd', 0.00054)
			->assertJsonPath('data.links.self', route('invoices.show', $invoice->id));
	}

	public function test_passes_a_chosen_model_to_the_job(): void
	{
		Queue::fake();

		$this->post('/api/invoices', ['file' => $this->pdf('inv-01-qamar-grove-office-supplies'), 'model' => 'claude-opus-5-5'], ['Accept' => 'application/json'])
			->assertStatus(202);

		Queue::assertPushed(ExtractInvoice::class, fn (ExtractInvoice $job) => $job->model === 'claude-opus-5-5');
	}

	public function test_refuses_missing_non_pdf_oversized_and_unknown_model_uploads(): void
	{
		Queue::fake();
		config(['invoices.max_upload_kb' => 50]);
		$json = ['Accept' => 'application/json'];

		$this->post('/api/invoices', [], $json)->assertUnprocessable()->assertJsonValidationErrors('file');
		// A real file: UploadedFile::fake() reports a MIME type from the name, not the content.
		$renamed = tempnam(sys_get_temp_dir(), 'inv');
		file_put_contents($renamed, 'just text, renamed to .pdf');
		$this->post('/api/invoices', ['file' => new UploadedFile($renamed, 'invoice.pdf', 'application/pdf', null, true)], $json)
			->assertUnprocessable()->assertJsonValidationErrors('file');
		unlink($renamed);
		$this->post('/api/invoices', ['file' => $this->pdf('inv-04-dune-lantern-multi-page')], $json)
			->assertUnprocessable()->assertJsonValidationErrors('file');
		$this->post('/api/invoices', ['file' => $this->pdf('inv-01-qamar-grove-office-supplies'), 'model' => 'gpt-something'], $json)
			->assertUnprocessable()->assertJsonValidationErrors('model');

		$this->assertSame(0, Invoice::count());
		Queue::assertNothingPushed();
	}

	public function test_uploads_are_rate_limited_per_client(): void
	{
		Queue::fake();
		config(['invoices.rate_limit_per_minute' => 2]);
		$json = ['Accept' => 'application/json'];

		$this->post('/api/invoices', ['file' => $this->pdf('inv-01-qamar-grove-office-supplies')], $json)->assertStatus(202);
		$this->post('/api/invoices', ['file' => $this->pdf('inv-01-qamar-grove-office-supplies')], $json)->assertStatus(202);
		$this->post('/api/invoices', ['file' => $this->pdf('inv-01-qamar-grove-office-supplies')], $json)->assertTooManyRequests();
	}

	public function test_an_unknown_invoice_is_404(): void
	{
		$this->getJson('/api/invoices/999')->assertNotFound();
	}

	private function pdf(string $id): UploadedFile
	{
		return new UploadedFile(base_path("resources/invoice-eval/samples/{$id}.pdf"), "{$id}.pdf", 'application/pdf', null, true);
	}
}
