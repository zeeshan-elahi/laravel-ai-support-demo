<?php

namespace App\Listeners;

use App\Events\AgentStepCompleted;
use App\Models\AgentRun;

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
	 * Estimated cost from the model's configured prices (USD per million tokens).
	 * With caching, `input_tokens` is only the uncached remainder; cache writes and
	 * reads are billed at their own prices.
	 */
	public static function cost(AgentRun $run): string
	{
		$price = config("agent.models.{$run->model}.price", []) + [
			'input' => 0, 'cache_write' => 0, 'cache_read' => 0, 'output' => 0,
		];

		$usd = ($run->input_tokens * $price['input']
			+ $run->cache_write_tokens * $price['cache_write']
			+ $run->cache_read_tokens * $price['cache_read']
			+ $run->output_tokens * $price['output']) / 1_000_000;

		return number_format($usd, 6, '.', '');
	}
}
