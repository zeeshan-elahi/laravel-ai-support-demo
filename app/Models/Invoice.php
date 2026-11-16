<?php

namespace App\Models;

use App\Casts\MinorUnits;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
	protected $fillable = [
		'source_path',
		'original_name',
		'source_sha256',
		'status',
		'failed_checks',
		'document_type',
		'invoice_number',
		'issue_date',
		'due_date',
		'supplier_name',
		'supplier_trn',
		'customer_name',
		'customer_trn',
		'currency',
		'subtotal',
		'discount',
		'vat_rate',
		'vat_amount',
		'total',
	];

	protected function casts(): array
	{
		return [
			'status' => InvoiceStatus::class,
			'document_type' => DocumentType::class,
			'failed_checks' => 'array',
			'issue_date' => 'date',
			'due_date' => 'date',
			'subtotal' => MinorUnits::class,
			'discount' => MinorUnits::class,
			'vat_rate' => 'decimal:2',
			'vat_amount' => MinorUnits::class,
			'total' => MinorUnits::class,
		];
	}

	public function lines(): HasMany
	{
		return $this->hasMany(InvoiceLine::class)->orderBy('position');
	}

	public function extractions(): HasMany
	{
		return $this->hasMany(InvoiceExtraction::class);
	}
}
