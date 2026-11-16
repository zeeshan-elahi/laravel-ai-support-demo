<?php

namespace App\Listeners;

use App\Events\AgentStepCompleted;
use App\Models\AgentRun;
use App\Support\TokenCost;

class RecordAgentStep
{
	public function handle(AgentStepCompleted $event): void
	{
		$reply = $event->reply;

		$event->run->steps()->create([
			'turn' => $event->turn,
			'stop_reason' => $reply->stopReason,
			'text' => $reply->text !== '' ? $reply->text : null,
			'tool_calls' => $event->toolCalls !== [] ? $event->toolCalls : null,
			'input_tokens' => $reply->inputTokens,
			'output_tokens' => $reply->outputTokens,
			'cache_write_tokens' => $reply->cacheWriteTokens,
			'cache_read_tokens' => $reply->cacheReadTokens,
			'latency_ms' => $event->latencyMs,
		]);

		$run = $event->run;
		$run->turns = $event->turn;
		$run->input_tokens += $reply->inputTokens;
		$run->output_tokens += $reply->outputTokens;
		$run->cache_write_tokens += $reply->cacheWriteTokens;
		$run->cache_read_tokens += $reply->cacheReadTokens;
		$run->latency_ms += $event->latencyMs;
		$run->cost_usd = self::cost($run);
		$run->save();
	}

	/**
	 * Estimated cost of the run so far (see TokenCost).
	 */
	public static function cost(AgentRun $run): string
	{
		return TokenCost::usd(
			$run->model,
			$run->input_tokens,
			$run->output_tokens,
			$run->cache_write_tokens,
			$run->cache_read_tokens,
		);
	}
}
