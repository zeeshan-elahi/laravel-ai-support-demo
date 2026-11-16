<?php

namespace Tests\Feature\Invoices;

use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Enums\InvoiceStatus;
use App\Invoices\Money;
use App\Models\Invoice;
use App\Models\InvoiceExtraction;
use App\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
	use RefreshDatabase;

	public function test_money_round_trips_through_integer_minor_units(): void
	{
		$invoice = $this->invoice(['total' => Money::fromDecimal(1853.15)]);

		$this->assertSame(185315, DB::table('invoices')->where('id', $invoice->id)->value('total'));
		$this->assertEquals(new Money(185315), $invoice->fresh()->total);
		$this->assertNull($invoice->fresh()->discount);
	}

	public function test_a_bare_number_cannot_be_assigned_to_a_money_column(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('total must be set as a Money instance, got float.');

		$this->invoice(['total' => 1853.15]);
	}

	public function test_enums_dates_and_failed_checks_are_cast(): void
	{
		$invoice = $this->invoice([
			'status' => InvoiceStatus::NeedsReview,
			'document_type' => DocumentType::Invoice,
			'failed_checks' => ['total'],
			'issue_date' => '2026-11-10',
		])->fresh();

		$this->assertSame(InvoiceStatus::NeedsReview, $invoice->status);
		$this->assertSame(DocumentType::Invoice, $invoice->document_type);
		$this->assertSame(['total'], $invoice->failed_checks);
		$this->assertSame('2026-11-10', $invoice->issue_date->toDateString());
	}

	public function test_lines_come_back_in_printed_order(): void
	{
		$invoice = $this->invoice();
		foreach ([3, 1, 2] as $position) {
			$invoice->lines()->create([
				'position' => $position,
				'description' => "Line {$position}",
				'quantity' => 7.5,
				'unit_price' => new Money(18000),
				'line_total' => new Money(135000),
			]);
		}

		$lines = $invoice->fresh()->lines;

		$this->assertSame(['Line 1', 'Line 2', 'Line 3'], $lines->pluck('description')->all());
		$this->assertSame('7.500', $lines[0]->quantity);
		$this->assertEquals(new Money(135000), $lines[0]->line_total);
	}

	public function test_deleting_an_invoice_removes_its_lines_and_extractions(): void
	{
		$invoice = $this->invoice();
		$invoice->lines()->create(['position' => 1, 'description' => 'x', 'quantity' => 1, 'unit_price' => new Money(100), 'line_total' => new Money(100)]);
		$invoice->extractions()->create(['model' => 'claude-haiku-5-5', 'status' => ExtractionStatus::Completed, 'output' => ['document_type' => 'invoice']]);

		$invoice->delete();

		$this->assertSame(0, InvoiceLine::count());
		$this->assertSame(0, InvoiceExtraction::count());
	}

	/**
	 * @param  array<string, mixed>  $attributes
	 */
	private function invoice(array $attributes = []): Invoice
	{
		return Invoice::create($attributes + [
			'source_path' => 'invoices/test.pdf',
			'original_name' => 'test.pdf',
			'source_sha256' => str_repeat('a', 64),
			'status' => InvoiceStatus::Pending,
		]);
	}
}
