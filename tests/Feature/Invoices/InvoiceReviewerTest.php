<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Invoices\InvoiceReviewer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No database and no API: the reviewer is fed JSON shaped like the model's reply.
 * The ground-truth files double as fixtures, so the checks and the sample set
 * cannot disagree without a test failing.
 */
class InvoiceReviewerTest extends TestCase
{
	private const EXPECTED_DIR = __DIR__ . '/../../../resources/invoice-eval/expected';

	/**
	 * @return array<string, array{string}>
	 */
	public static function groundTruthFiles(): array
	{
		$files = glob(self::EXPECTED_DIR . '/*.json');
		sort($files);

		return array_combine(array_map('basename', $files), array_map(fn ($f) => [$f], $files));
	}

	#[DataProvider('groundTruthFiles')]
	public function test_ground_truth_reviews_to_its_expected_status(string $file): void
	{
		$truth = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

		$review = (new InvoiceReviewer())->review($truth['expected']);

		$this->assertSame($truth['expected_status'], $review->status->value);
		$this->assertSame($truth['expected_failed_checks'], $review->failedChecks);
	}

	public function test_there_are_ten_ground_truth_files(): void
	{
		$this->assertCount(10, self::groundTruthFiles());
	}

	public function test_flags_a_wrong_line_total_by_position_and_the_subtotal(): void
	{
		$output = $this->invoice();
		$output['lines'][1]['line_total'] = 171.00;

		$review = (new InvoiceReviewer())->review($output);

		$this->assertSame(InvoiceStatus::NeedsReview, $review->status);
		$this->assertSame(['line_total:2', 'subtotal'], $review->failedChecks);
	}

	public function test_flags_a_wrong_vat_amount(): void
	{
		$output = $this->invoice();
		$output['vat_amount'] = 90.00;
		$output['total'] = 1767.50;

		$this->assertSame(['vat_amount'], (new InvoiceReviewer())->review($output)->failedChecks);
	}

	public function test_tolerates_a_one_fils_rounding_difference(): void
	{
		$output = $this->invoice();
		$output['vat_amount'] = 83.87;
		$output['total'] = 1761.37;

		$this->assertSame(InvoiceStatus::Accepted, (new InvoiceReviewer())->review($output)->status);
	}

	public function test_a_null_discount_counts_as_zero(): void
	{
		$output = $this->invoice();
		$output['discount'] = null;

		$review = (new InvoiceReviewer())->review($output);

		$this->assertSame(InvoiceStatus::Accepted, $review->status);
		$this->assertSame(0, $review->data['discount']);
	}

	public function test_normalises_a_trn_printed_with_spaces(): void
	{
		$output = $this->invoice();
		$output['supplier']['trn'] = '100 2345 6780 0003';

		$review = (new InvoiceReviewer())->review($output);

		$this->assertSame(InvoiceStatus::Accepted, $review->status);
		$this->assertSame('100234567800003', $review->data['supplier']['trn']);
	}

	public function test_flags_malformed_trns(): void
	{
		$output = $this->invoice();
		$output['supplier']['trn'] = '10023456780';
		$output['customer']['trn'] = 'TRN100987654300003';

		$this->assertSame(['supplier_trn', 'customer_trn'], (new InvoiceReviewer())->review($output)->failedChecks);
	}

	public function test_flags_a_missing_supplier_trn_only_when_vat_is_charged(): void
	{
		$charged = $this->invoice();
		$charged['supplier']['trn'] = null;
		$this->assertSame(['supplier_trn_missing'], (new InvoiceReviewer())->review($charged)->failedChecks);

		$zeroRated = $this->invoice();
		$zeroRated['supplier']['trn'] = null;
		$zeroRated['vat_rate'] = 0;
		$zeroRated['vat_amount'] = 0;
		$zeroRated['total'] = 1677.50;
		$this->assertSame([], (new InvoiceReviewer())->review($zeroRated)->failedChecks);
	}

	public function test_validation_failures_become_invalid_checks(): void
	{
		$output = $this->invoice();
		$output['issue_date'] = '04/10/2026';
		$output['currency'] = 'aed';
		$output['lines'][0]['quantity'] = 0;

		$review = (new InvoiceReviewer())->review($output);

		$this->assertSame(InvoiceStatus::NeedsReview, $review->status);
		$this->assertNull($review->data);
		$this->assertContains('invalid:issue_date', $review->failedChecks);
		$this->assertContains('invalid:currency', $review->failedChecks);
		$this->assertContains('invalid:lines.0.quantity', $review->failedChecks);
	}

	public function test_a_due_date_before_the_issue_date_is_invalid(): void
	{
		$output = $this->invoice();
		$output['due_date'] = '2026-10-01';

		$this->assertSame(['invalid:due_date'], (new InvoiceReviewer())->review($output)->failedChecks);
	}

	public function test_an_invoice_without_lines_is_invalid(): void
	{
		$output = $this->invoice();
		$output['lines'] = [];

		$this->assertContains('invalid:lines', (new InvoiceReviewer())->review($output)->failedChecks);
	}

	public function test_an_unknown_document_type_needs_review(): void
	{
		$review = (new InvoiceReviewer())->review(['document_type' => 'receipt']);

		$this->assertSame(InvoiceStatus::NeedsReview, $review->status);
		$this->assertSame(['invalid:document_type'], $review->failedChecks);
	}

	public function test_a_not_invoice_is_rejected_whatever_else_it_contains(): void
	{
		$review = (new InvoiceReviewer())->review(['document_type' => 'not_invoice', 'total' => 'garbage']);

		$this->assertSame(InvoiceStatus::Rejected, $review->status);
		$this->assertSame([], $review->failedChecks);
	}

	/**
	 * inv-01 as the model should return it.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(): array
	{
		$truth = json_decode(file_get_contents(self::EXPECTED_DIR . '/inv-01-qamar-grove-office-supplies.json'), true, flags: JSON_THROW_ON_ERROR);

		return $truth['expected'];
	}
}
