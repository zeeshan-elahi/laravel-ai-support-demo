<?php

namespace Tests\Feature\Invoices;

use App\Invoices\Contracts\InvoiceExtractor;
use App\Jobs\ExtractInvoice;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeInvoiceExtractor;
use Tests\TestCase;

class InvoiceExtractCommandTest extends TestCase
{
	use RefreshDatabase;

	private const SAMPLE = 'resources/invoice-eval/samples/inv-06-coral-spindle-wrong-total.pdf';

	protected function setUp(): void
	{
		parent::setUp();

		Storage::fake('local');
	}

	public function test_sync_run_prints_the_review_lines_and_cost(): void
	{
		$this->app->instance(InvoiceExtractor::class, (new FakeInvoiceExtractor())
			->push(FakeInvoiceExtractor::groundTruth('inv-06-coral-spindle-wrong-total.json')));

		$this->artisan('invoice:extract', ['path' => base_path(self::SAMPLE), '--sync' => true])
			->expectsOutputToContain('needs_review · failed: total')
			->expectsOutputToContain('CSP/26/0921')
			->expectsOutputToContain('AED 3,700.00')
			->expectsOutputToContain('total AED 6,846.25')
			->expectsOutputToContain('claude-haiku-5-5 · completed · stop end_turn · 2400 in / 600 out tokens')
			->assertSuccessful();
	}

	public function test_without_sync_the_job_is_queued(): void
	{
		Queue::fake();

		$this->artisan('invoice:extract', ['path' => base_path(self::SAMPLE), '--model' => 'claude-opus-5-5'])
			->expectsOutputToContain('queued')
			->assertSuccessful();

		Queue::assertPushed(ExtractInvoice::class, fn (ExtractInvoice $job) => $job->model === 'claude-opus-5-5'
			&& $job->invoiceId === Invoice::sole()->id);
	}

	public function test_refuses_a_file_that_is_not_a_pdf(): void
	{
		$this->artisan('invoice:extract', ['path' => base_path('resources/invoice-eval/expected/inv-01-qamar-grove-office-supplies.json')])
			->expectsOutputToContain('Not a PDF')
			->assertFailed();

		$this->assertSame(0, Invoice::count());
	}

	public function test_refuses_a_missing_file(): void
	{
		$this->artisan('invoice:extract', ['path' => '/nope.pdf'])
			->expectsOutputToContain('Not a readable file')
			->assertFailed();
	}
}
