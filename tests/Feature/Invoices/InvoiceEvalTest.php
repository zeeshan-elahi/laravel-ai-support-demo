<?php

namespace Tests\Feature\Invoices;

use App\Invoices\Contracts\InvoiceExtractor;
use App\Invoices\Eval\InvoiceEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeInvoiceExtractor;
use Tests\TestCase;

class InvoiceEvalTest extends TestCase
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

	public function test_a_perfect_extraction_passes_with_every_field_right(): void
	{
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('inv-04-dune-lantern-multi-page.json'));

		$result = $this->evaluate('inv-04-dune-lantern-multi-page');

		$this->assertTrue($result['passed']);
		// 14 header fields + 36 lines × 4.
		$this->assertSame(158, $result['fields_total']);
		$this->assertSame(158, $result['fields_correct']);
	}

	public function test_the_wrong_total_sample_passes_when_the_check_flags_it(): void
	{
		$this->extractor->push(FakeInvoiceExtractor::groundTruth('inv-06-coral-spindle-wrong-total.json'));

		$this->assertTrue($this->evaluate('inv-06-coral-spindle-wrong-total')['passed']);
	}

	public function test_names_ignore_case_and_spacing_but_amounts_must_be_exact(): void
	{
		$output = FakeInvoiceExtractor::groundTruth('inv-03-dune-lantern-it-services.json')->output;
		$output['supplier']['name'] = 'DUNE  LANTERN IT SERVICES FZE';
		$output['lines'][0]['description'] = 'on-site network support (hours)';
		$this->extractor->push(FakeInvoiceExtractor::returning($output));

		$this->assertTrue($this->evaluate('inv-03-dune-lantern-it-services')['passed']);
	}

	public function test_a_dropped_line_on_page_two_is_reported(): void
	{
		$output = FakeInvoiceExtractor::groundTruth('inv-04-dune-lantern-multi-page.json')->output;
		array_splice($output['lines'], 20, 1);
		$this->extractor->push(FakeInvoiceExtractor::returning($output));

		$result = $this->evaluate('inv-04-dune-lantern-multi-page');

		$this->assertFalse($result['passed']);
		$this->assertContains('status: expected accepted, got needs_review', $result['failures']);
		$this->assertContains('line count: expected 36, got 35', $result['failures']);
		$this->assertContains('line 21 description: expected "Laptop setup and imaging, asset SW-121", got "Printer driver deployment, asset SW-122"', $result['failures']);
	}

	public function test_a_guessed_due_date_is_a_failure(): void
	{
		$output = FakeInvoiceExtractor::groundTruth('inv-10-dune-lantern-no-due-date.json')->output;
		$output['due_date'] = '2026-12-16';
		$this->extractor->push(FakeInvoiceExtractor::returning($output));

		$result = $this->evaluate('inv-10-dune-lantern-no-due-date');

		$this->assertTrue($result['status_ok']);
		$this->assertSame(['due_date: expected null, got "2026-12-16"'], $result['failures']);
	}

	public function test_a_quotation_read_as_an_invoice_fails_on_status(): void
	{
		$output = FakeInvoiceExtractor::groundTruth('inv-01-qamar-grove-office-supplies.json')->output;
		$this->extractor->push(FakeInvoiceExtractor::returning($output));

		$result = $this->evaluate('quote-09-qamar-grove-quotation');

		$this->assertFalse($result['passed']);
		$this->assertSame(['status: expected rejected, got accepted'], $result['failures']);
	}

	public function test_the_command_runs_selected_samples_and_writes_a_report(): void
	{
		$this->extractor->push(
			FakeInvoiceExtractor::groundTruth('inv-01-qamar-grove-office-supplies.json'),
			FakeInvoiceExtractor::groundTruth('quote-09-qamar-grove-quotation.json'),
		);

		$this->artisan('invoice:eval', ['--only' => 'inv-01-qamar-grove-office-supplies,quote-09-qamar-grove-quotation'])
			->expectsOutputToContain('Evaluating claude-haiku-5-5 on 2 documents')
			// One summary row: each expectation consumes the output line it matches.
			// inv-01: 14 header fields + 4 lines × 4; the quotation has no fields.
			->expectsOutputToContain('30/30 (100%)')
			->assertSuccessful();

		$reports = Storage::disk('local')->files('invoice-eval');
		$this->assertCount(1, $reports);
		$report = json_decode(Storage::disk('local')->get($reports[0]), true);
		$this->assertSame(2, $report['summary']['passed']);
		$this->assertSame('rejected', $report['results'][1]['status']);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function evaluate(string $id): array
	{
		return app(InvoiceEvaluator::class)->evaluate(resource_path("invoice-eval/expected/{$id}.json"), 'claude-haiku-5-5');
	}
}
