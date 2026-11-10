<?php

namespace App\Agent\Contracts;

use App\Agent\AgentReply;

/**
 * One call to the model. Behind an interface so tests use a scripted fake and the
 * agent loop never knows which SDK, endpoint or model sits underneath.
 */
interface LlmClient
{
	/**
	 * @param  list<array<string, mixed>>  $system
	 * @param  list<array<string, mixed>>  $tools
	 * @param  list<array<string, mixed>>  $messages
	 */
	public function send(string $model, array $system, array $tools, array $messages): AgentReply;
}
