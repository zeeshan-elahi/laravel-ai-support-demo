<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceExtraction extends Model
{
	protected $fillable = [
		'model',
		'effort',
		'status',
		'stop_reason',
		'input_tokens',
		'output_tokens',
		'latency_ms',
		'cost_usd',
		'output',
		'error',
	];

	protected function casts(): array
	{
		return [
			'status' => ExtractionStatus::class,
			'input_tokens' => 'integer',
			'output_tokens' => 'integer',
			'latency_ms' => 'integer',
			'cost_usd' => 'decimal:6',
			'output' => 'array',
		];
	}

	public function invoice(): BelongsTo
	{
		return $this->belongsTo(Invoice::class);
	}
}
