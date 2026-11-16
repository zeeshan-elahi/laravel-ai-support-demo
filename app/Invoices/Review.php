<?php

namespace App\Invoices;

use App\Enums\InvoiceStatus;

/**
 * The code's verdict on one extraction. $data is the validated, normalised output
 * (decimal amounts, TRNs as digits); null when the document was rejected or failed
 * validation.
 */
final readonly class Review
{
	/**
	 * @param  list<string>  $failedChecks
	 * @param  array<string, mixed>|null  $data
	 */
	public function __construct(
		public InvoiceStatus $status,
		public array $failedChecks,
		public ?array $data,
	)
	{}
}
