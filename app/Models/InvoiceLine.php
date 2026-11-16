<?php

namespace App\Models;

use App\Casts\MinorUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
	protected $fillable = [
		'position',
		'description',
		'quantity',
		'unit_price',
		'line_total',
	];

	protected function casts(): array
	{
		return [
			'position' => 'integer',
			'quantity' => 'decimal:3',
			'unit_price' => MinorUnits::class,
			'line_total' => MinorUnits::class,
		];
	}

	public function invoice(): BelongsTo
	{
		return $this->belongsTo(Invoice::class);
	}
}
