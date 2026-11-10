<?php

namespace App\Events;

use App\Agent\AgentReply;
use App\Models\AgentRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once per model turn. Logging listens to this, so the agent loop holds no
 * logging code of its own.
 */
class AgentStepCompleted
{
	use Dispatchable;

	/**
	 * @param  list<array{name: string, input: array<string, mixed>, is_error: bool}>  $toolCalls
	 */
	public function __construct(
		public readonly AgentRun $run,
		public readonly int $turn,
		public readonly AgentReply $reply,
		public readonly array $toolCalls,
		public readonly int $latencyMs,
	) {}
}
