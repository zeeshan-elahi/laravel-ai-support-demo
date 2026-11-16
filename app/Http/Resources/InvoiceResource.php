<?php

namespace App\Http\Resources;

use App\Invoices\Money;
use App\Models\Invoice;
use App\Models\InvoiceExtraction;
use App\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts are decimal numbers in the invoice currency; storage keeps minor units.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
	/**
	 * @return array<string, mixed>
	 */
	public function toArray(Request $request): array
	{
		$amount = fn (?Money $money) => $money?->toDecimal();

		return [
			'id' => $this->id,
			'status' => $this->status->value,
			'failed_checks' => $this->failed_checks ?? [],
			'original_name' => $this->original_name,
			'document_type' => $this->document_type?->value,
			'invoice_number' => $this->invoice_number,
			'issue_date' => $this->issue_date?->toDateString(),
			'due_date' => $this->due_date?->toDateString(),
			'supplier' => ['name' => $this->supplier_name, 'trn' => $this->supplier_trn],
			'customer' => ['name' => $this->customer_name, 'trn' => $this->customer_trn],
			'currency' => $this->currency,
			'subtotal' => $amount($this->subtotal),
			'discount' => $amount($this->discount),
			'vat_rate' => $this->vat_rate !== null ? (float) $this->vat_rate : null,
			'vat_amount' => $amount($this->vat_amount),
			'total' => $amount($this->total),
			'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (InvoiceLine $line) => [
				'position' => $line->position,
				'description' => $line->description,
				'quantity' => (float) $line->quantity,
				'unit_price' => $amount($line->unit_price),
				'line_total' => $amount($line->line_total),
			])),
			'extraction' => $this->whenLoaded('extractions', function () {
				/** @var InvoiceExtraction|null $last */
				$last = $this->extractions->last();

				return $last === null ? null : [
					'model' => $last->model,
					'status' => $last->status->value,
					'input_tokens' => $last->input_tokens,
					'output_tokens' => $last->output_tokens,
					'cost_usd' => (float) $last->cost_usd,
					'latency_ms' => $last->latency_ms,
				];
			}),
			'links' => ['self' => route('invoices.show', $this->id)],
		];
	}
}
