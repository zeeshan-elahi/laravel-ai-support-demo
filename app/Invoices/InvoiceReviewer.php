<?php

namespace App\Invoices;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Decides what an extraction is worth. The model's JSON is never trusted: it is
 * validated with Laravel rules, then every printed amount is rechecked in integer
 * minor units, and TRNs are format-checked. The model gives no confidence score;
 * these checks are the confidence signal.
 *
 * Failed check codes:
 *   invalid:<field>       a Validator rule failed (e.g. invalid:issue_date, invalid:lines.0.quantity)
 *   line_total:<n>        quantity × unit price ≠ line total on line n (1-based)
 *   subtotal              Σ line totals ≠ subtotal
 *   vat_amount            (subtotal − discount) × rate ≠ VAT amount
 *   total                 subtotal − discount + VAT ≠ total
 *   supplier_trn          supplier TRN present but not 15 digits
 *   supplier_trn_missing  VAT charged (rate > 0) but no supplier TRN
 *   customer_trn          customer TRN present but not 15 digits
 */
final class InvoiceReviewer
{
	/** Rounding conventions differ (per line vs per invoice, half-up vs half-even): allow 0.01. */
	public const TOLERANCE_MINOR = 1;

	/**
	 * @param  array<string, mixed>  $output  the model's decoded JSON
	 */
	public function review(array $output): Review
	{
		$type = DocumentType::tryFrom((string) ($output['document_type'] ?? ''));

		if ($type === null) {
			return new Review(InvoiceStatus::NeedsReview, ['invalid:document_type'], null);
		}

		if ($type === DocumentType::NotInvoice) {
			return new Review(InvoiceStatus::Rejected, [], null);
		}

		$validator = Validator::make($output, $this->rules());

		if ($validator->fails()) {
			$failed = array_map(fn (string $field) => "invalid:{$field}", array_keys($validator->errors()->toArray()));

			return new Review(InvoiceStatus::NeedsReview, $failed, null);
		}

		$data = $validator->validated();
		$data['supplier']['trn'] = $this->normaliseTrn($data['supplier']['trn'] ?? null);
		$data['customer']['trn'] = $this->normaliseTrn($data['customer']['trn'] ?? null);
		$data['discount'] ??= 0;

		$failed = [...$this->arithmeticChecks($data), ...$this->trnChecks($data)];

		return new Review(
			$failed === [] ? InvoiceStatus::Accepted : InvoiceStatus::NeedsReview,
			$failed,
			$data,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rules(): array
	{
		return [
			'document_type' => ['required', Rule::enum(DocumentType::class)],
			'invoice_number' => ['required', 'string', 'max:60'],
			'issue_date' => ['required', 'date_format:Y-m-d'],
			'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
			'supplier' => ['required', 'array'],
			'supplier.name' => ['required', 'string', 'max:255'],
			'supplier.trn' => ['nullable', 'string', 'max:30'],
			'customer' => ['required', 'array'],
			'customer.name' => ['nullable', 'string', 'max:255'],
			'customer.trn' => ['nullable', 'string', 'max:30'],
			'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
			'lines' => ['required', 'array', 'min:1', 'max:200'],
			'lines.*.description' => ['required', 'string', 'max:500'],
			'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
			'lines.*.unit_price' => ['required', 'numeric'],
			'lines.*.line_total' => ['required', 'numeric'],
			'subtotal' => ['required', 'numeric'],
			'discount' => ['nullable', 'numeric', 'gte:0'],
			'vat_rate' => ['required', 'numeric', 'between:0,100'],
			'vat_amount' => ['required', 'numeric', 'gte:0'],
			'total' => ['required', 'numeric'],
		];
	}

	/**
	 * Each check compares printed values with each other, so one wrong line flags that
	 * line and the subtotal, not everything below it.
	 *
	 * @param  array<string, mixed>  $data
	 * @return list<string>
	 */
	private function arithmeticChecks(array $data): array
	{
		$failed = [];
		$sumOfLines = new Money(0);

		foreach ($data['lines'] as $i => $line) {
			$unit = Money::fromDecimal($line['unit_price']);
			$lineTotal = Money::fromDecimal($line['line_total']);
			$expected = new Money((int) round((float) $line['quantity'] * $unit->minor));

			if (! $expected->equalsWithin($lineTotal, self::TOLERANCE_MINOR)) {
				$failed[] = 'line_total:' . ($i + 1);
			}

			$sumOfLines = $sumOfLines->plus($lineTotal);
		}

		$subtotal = Money::fromDecimal($data['subtotal']);
		$discount = Money::fromDecimal($data['discount']);
		$vat = Money::fromDecimal($data['vat_amount']);
		$total = Money::fromDecimal($data['total']);
		$taxable = $subtotal->minus($discount);

		if (! $sumOfLines->equalsWithin($subtotal, self::TOLERANCE_MINOR)) {
			$failed[] = 'subtotal';
		}

		$expectedVat = new Money((int) round($taxable->minor * (float) $data['vat_rate'] / 100));
		if (! $expectedVat->equalsWithin($vat, self::TOLERANCE_MINOR)) {
			$failed[] = 'vat_amount';
		}

		if (! $taxable->plus($vat)->equalsWithin($total, self::TOLERANCE_MINOR)) {
			$failed[] = 'total';
		}

		return $failed;
	}

	/**
	 * @param  array<string, mixed>  $data
	 * @return list<string>
	 */
	private function trnChecks(array $data): array
	{
		$failed = [];
		$supplierTrn = $data['supplier']['trn'];
		$customerTrn = $data['customer']['trn'];

		if ($supplierTrn === null && (float) $data['vat_rate'] > 0) {
			$failed[] = 'supplier_trn_missing';
		} elseif ($supplierTrn !== null && ! $this->isTrn($supplierTrn)) {
			$failed[] = 'supplier_trn';
		}

		if ($customerTrn !== null && ! $this->isTrn($customerTrn)) {
			$failed[] = 'customer_trn';
		}

		return $failed;
	}

	private function normaliseTrn(?string $trn): ?string
	{
		if ($trn === null) {
			return null;
		}

		$trn = preg_replace('/[\s-]+/', '', $trn);

		return $trn === '' ? null : $trn;
	}

	private function isTrn(string $trn): bool
	{
		return preg_match('/^\d{15}$/', $trn) === 1;
	}
}
