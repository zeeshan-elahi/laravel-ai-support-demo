<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
	use HasFactory;

	protected $fillable = [
		'sku',
		'product_name',
		'quantity',
		'unit_price_cents',
	];

	protected function casts(): array
	{
		return [
			'quantity' => 'integer',
			'unit_price_cents' => 'integer',
		];
	}

	public function order(): BelongsTo
	{
		return $this->belongsTo(Order::class);
	}
}
