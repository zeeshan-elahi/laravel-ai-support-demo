<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
	use HasFactory;

	protected $fillable = [
		'order_number',
		'customer_name',
		'customer_email',
		'status',
		'currency',
		'total_cents',
		'tracking_number',
		'placed_at',
		'shipped_at',
		'delivered_at',
	];

	protected function casts(): array
	{
		return [
			'status' => OrderStatus::class,
			'total_cents' => 'integer',
			'placed_at' => 'datetime',
			'shipped_at' => 'datetime',
			'delivered_at' => 'datetime',
		];
	}

	public function items(): HasMany
	{
		return $this->hasMany(OrderItem::class);
	}

	public function supportTickets(): HasMany
	{
		return $this->hasMany(SupportTicket::class);
	}

	/**
	 * Recalculate the order total from its items and save it.
	 */
	public function recalculateTotal(): void
	{
		$this->total_cents = (int) $this->items()->sum(DB::raw('quantity * unit_price_cents'));
		$this->save();
	}
}
