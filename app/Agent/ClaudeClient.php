<?php

namespace App\Agent;

use Anthropic\Client;
use App\Agent\Contracts\LlmClient;

class ClaudeClient implements LlmClient
{
	public function __construct(
		private readonly Client $client,
		private readonly string $effort,
		private readonly int $maxTokens,
		private readonly bool $cache = true,
	) {}

	public function send(string $model, array $system, array $tools, array $messages): AgentReply
	{
		$params = [
			'model' => $model,
			'maxTokens' => $this->maxTokens,
			'system' => $system,
			'tools' => $tools,
			'messages' => $messages,
			'outputConfig' => ['effort' => $this->effort],
		];

		if ($this->cache) {
			// Automatic breakpoint on the last cacheable block: each turn reads the turns before it.
			$params['cacheControl'] = ['type' => 'ephemeral'];
		}

		// Server-side refusal fallback, on models that have it (beta endpoint).
		$message = config("agent.models.{$model}.fallbacks", false)
			? $this->client->beta->messages->create(...$params, fallbacks: 'default', betas: ['server-side-fallback-2026-07-01'])
			: $this->client->messages->create(...$params);

		$text = '';
		$toolUses = [];

		foreach ($message->content as $block) {
			if ($block->type === 'text') {
				$text .= $block->text;
			} elseif ($block->type === 'tool_use') {
				$toolUses[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
			}
		}

		return new AgentReply(
			model: $message->model,
			stopReason: $message->stopReason,
			content: $message->content,
			text: trim($text),
			toolUses: $toolUses,
			inputTokens: $message->usage->inputTokens,
			outputTokens: $message->usage->outputTokens,
			cacheWriteTokens: (int) $message->usage->cacheCreationInputTokens,
			cacheReadTokens: (int) $message->usage->cacheReadInputTokens,
			refusalCategory: $message->stopReason === 'refusal' ? $message->stopDetails?->category : null,
		);
	}
}
