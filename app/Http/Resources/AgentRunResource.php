<?php

namespace App\Http\Resources;

use App\Models\AgentRun;
use App\Models\AgentStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentRun
 */
class AgentRunResource extends JsonResource
{
	/**
	 * @return array<string, mixed>
	 */
	public function toArray(Request $request): array
	{
		return [
			'id' => $this->id,
			'status' => $this->status->value,
			'answer' => $this->answer,
			'model' => $this->model,
			'turns' => $this->turns,
			'tools' => $this->steps
				->flatMap(fn (AgentStep $step) => collect($step->tool_calls ?? [])->map(fn (array $call) => [
					'name' => $call['name'],
					'ok' => ! $call['is_error'],
				]))
				->values(),
			'usage' => [
				'input_tokens' => $this->input_tokens,
				'cache_write_tokens' => $this->cache_write_tokens,
				'cache_read_tokens' => $this->cache_read_tokens,
				'output_tokens' => $this->output_tokens,
				'cost_usd' => (float) $this->cost_usd,
				'latency_ms' => $this->latency_ms,
			],
		];
	}
}
