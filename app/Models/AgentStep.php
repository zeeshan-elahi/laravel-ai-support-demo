<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStep extends Model
{
	protected $fillable = [
		'turn',
		'stop_reason',
		'text',
		'tool_calls',
		'input_tokens',
		'output_tokens',
		'cache_write_tokens',
		'cache_read_tokens',
		'latency_ms',
	];

	protected function casts(): array
	{
		return [
			'turn' => 'integer',
			'tool_calls' => 'array',
			'input_tokens' => 'integer',
			'output_tokens' => 'integer',
			'cache_write_tokens' => 'integer',
			'cache_read_tokens' => 'integer',
			'latency_ms' => 'integer',
		];
	}

	public function run(): BelongsTo
	{
		return $this->belongsTo(AgentRun::class, 'agent_run_id');
	}
}
