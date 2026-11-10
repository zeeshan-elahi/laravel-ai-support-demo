<?php

namespace App\Agent;

/**
 * The parts of a model response the agent loop needs. `content` is kept as the
 * API returned it so it can be sent back unchanged on the next turn (thinking
 * blocks must not be rebuilt).
 */
final readonly class AgentReply
{
	/**
	 * @param  list<mixed>  $content
	 * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolUses
	 */
	public function __construct(
		public string $model,
		public ?string $stopReason,
		public array $content,
		public string $text,
		public array $toolUses,
		public int $inputTokens,
		public int $outputTokens,
		public int $cacheWriteTokens = 0,
		public int $cacheReadTokens = 0,
		public ?string $refusalCategory = null,
	) {}
}
