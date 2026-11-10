<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
	protected $fillable = [
		'question',
		'model',
		'status',
		'answer',
		'refusal_category',
		'turns',
		'input_tokens',
		'output_tokens',
		'cache_write_tokens',
		'cache_read_tokens',
		'latency_ms',
		'cost_usd',
	];

	protected function casts(): array
	{
		return [
			'status' => AgentRunStatus::class,
			'turns' => 'integer',
			'input_tokens' => 'integer',
			'output_tokens' => 'integer',
			'cache_write_tokens' => 'integer',
			'cache_read_tokens' => 'integer',
			'latency_ms' => 'integer',
			'cost_usd' => 'decimal:6',
		];
	}

	public function steps(): HasMany
	{
		return $this->hasMany(AgentStep::class)->orderBy('turn');
	}
}
