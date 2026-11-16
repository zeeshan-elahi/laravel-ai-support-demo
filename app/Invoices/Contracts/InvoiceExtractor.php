<?php

namespace App\Invoices\Contracts;

use App\Invoices\ExtractionResult;

/**
 * One extraction call. Behind an interface so tests use a scripted fake and never
 * reach the API.
 */
interface InvoiceExtractor
{
	/**
	 * @param  string  $pdf  raw PDF bytes
	 *
	 * @throws \Anthropic\Core\Exceptions\APIException on API or transport errors
	 */
	public function extract(string $pdf, string $model): ExtractionResult;
}
