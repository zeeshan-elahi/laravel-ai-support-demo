<?php

namespace App\Invoices;

use App\Enums\DocumentType;

/**
 * JSON schema for structured outputs (output_config.format). The API guarantees the
 * reply matches it; it cannot express ranges or lengths (minimum, maxLength are not
 * supported), so those live in InvoiceReviewer's Validator rules instead.
 *
 * Every object lists all its properties as required with additionalProperties false;
 * "optional" means nullable, written as anyOf [type, null].
 */
final class InvoiceSchema
{
	/**
	 * @return array<string, mixed>
	 */
	public static function format(): array
	{
		return [
			'type' => 'json_schema',
			'schema' => self::schema(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function schema(): array
	{
		return self::object([
			'document_type' => [
				'type' => 'string',
				'enum' => array_column(DocumentType::cases(), 'value'),
				'description' => 'invoice for a tax invoice or any invoice requesting payment. not_invoice for anything else: quotation, estimate, receipt, credit note, statement, purchase order, delivery note. When not_invoice, set every other field to null and lines to an empty array.',
			],
			'invoice_number' => self::nullable('string', 'The invoice number exactly as printed.'),
			'issue_date' => self::nullableDate('Invoice date as YYYY-MM-DD. Read the printed date in its own format (dd/mm/yyyy is day first).'),
			'due_date' => self::nullableDate('Due date as YYYY-MM-DD, only when a date is printed. null when none is printed; never work it out from payment terms.'),
			'supplier' => self::party('The business issuing the invoice.'),
			'customer' => self::party('The business or person billed.'),
			'currency' => self::nullable('string', 'ISO 4217 code, e.g. AED (also for "Dhs" or "Dirham"), USD.'),
			'lines' => [
				'type' => 'array',
				'description' => 'Every line item, in printed order, across all pages. Do not include subtotal, discount, VAT or total rows.',
				'items' => self::object([
					'description' => ['type' => 'string', 'description' => 'The line description as printed.'],
					'quantity' => ['type' => 'number', 'description' => 'Quantity or hours.'],
					'unit_price' => ['type' => 'number', 'description' => 'Price per unit as a plain number, no currency or thousands separators.'],
					'line_total' => ['type' => 'number', 'description' => 'The line amount as printed.'],
				]),
			],
			'subtotal' => self::nullable('number', 'Sum of the lines before discount and VAT, as printed.'),
			'discount' => self::nullable('number', 'Discount deducted before VAT, as a positive number. null when none is printed.'),
			'vat_rate' => self::nullable('number', 'VAT percentage: 5 for 5%, 0 for zero-rated.'),
			'vat_amount' => self::nullable('number', 'VAT amount as printed.'),
			'total' => self::nullable('number', 'Total payable including VAT, exactly as printed, even if the arithmetic looks wrong.'),
		]);
	}

	/**
	 * @param  array<string, array<string, mixed>>  $properties
	 * @return array<string, mixed>
	 */
	private static function object(array $properties): array
	{
		return [
			'type' => 'object',
			'properties' => $properties,
			'required' => array_keys($properties),
			'additionalProperties' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function party(string $description): array
	{
		return self::object([
			'name' => self::nullable('string', 'Legal name as printed.'),
			'trn' => self::nullable('string', 'UAE Tax Registration Number (TRN), digits only. null when none is printed.'),
		]) + ['description' => $description];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function nullable(string $type, string $description): array
	{
		return [
			'anyOf' => [['type' => $type], ['type' => 'null']],
			'description' => $description,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function nullableDate(string $description): array
	{
		return [
			'anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']],
			'description' => $description,
		];
	}
}
