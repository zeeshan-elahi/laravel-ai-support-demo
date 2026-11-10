<?php

namespace Tests\Fakes;

use App\Agent\AgentReply;
use App\Agent\Contracts\LlmClient;
use RuntimeException;

/**
 * Scripted stand-in for the model: returns queued replies in order and records every
 * request, so tests can check what the agent sent back (tool results, history).
 */
class FakeLlmClient implements LlmClient
{
	/**
	 * @var list<AgentReply>
	 */
	private array $queue = [];

	/**
	 * @var list<array{model: string, system: array, tools: array, messages: array}>
	 */
	public array $requests = [];

	public function push(AgentReply ...$replies): self
	{
		array_push($this->queue, ...$replies);

		return $this;
	}

	public function send(string $model, array $system, array $tools, array $messages): AgentReply
	{
		$this->requests[] = compact('model', 'system', 'tools', 'messages');

		return array_shift($this->queue) ?? throw new RuntimeException('FakeLlmClient: no reply queued.');
	}

	public static function text(string $text, string $stopReason = 'end_turn'): AgentReply
	{
		return new AgentReply(
			model: 'claude-haiku-5-5',
			stopReason: $stopReason,
			content: [['type' => 'text', 'text' => $text]],
			text: $text,
			toolUses: [],
			inputTokens: 1000,
			outputTokens: 50,
		);
	}

	/**
	 * @param  array<string, mixed>  $input
	 */
	public static function toolUse(string $name, array $input, string $id = 'toolu_1'): AgentReply
	{
		return new AgentReply(
			model: 'claude-haiku-5-5',
			stopReason: 'tool_use',
			content: [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
			text: '',
			toolUses: [['id' => $id, 'name' => $name, 'input' => $input]],
			inputTokens: 1000,
			outputTokens: 40,
		);
	}

	public static function refusal(?string $category = 'cyber'): AgentReply
	{
		return new AgentReply(
			model: 'claude-haiku-5-5',
			stopReason: 'refusal',
			content: [],
			text: '',
			toolUses: [],
			inputTokens: 900,
			outputTokens: 5,
			refusalCategory: $category,
		);
	}
}
