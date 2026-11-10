<?php

namespace App\Models;

use App\Enums\TicketReason;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
	use HasFactory;

	protected $fillable = [
		'reason',
		'summary',
		'status',
		'requested_by_user',
	];

	protected $attributes = [
		'status' => 'open',
	];

	protected function casts(): array
	{
		return [
			'reason' => TicketReason::class,
			'status' => TicketStatus::class,
			'requested_by_user' => 'boolean',
		];
	}

	public function order(): BelongsTo
	{
		return $this->belongsTo(Order::class);
	}
}
