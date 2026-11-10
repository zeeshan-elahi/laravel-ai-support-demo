<?php

namespace App\Agent;

/**
 * What a tool returns to the agent loop: a payload for the model, or an error it can read.
 */
final readonly class ToolResult
{
	/**
	 * @param  array<string, mixed>  $payload
	 */
	private function __construct(
		public bool $isError,
		public array $payload,
	) {}

	/**
	 * @param  array<string, mixed>  $payload
	 */
	public static function ok(array $payload): self
	{
		return new self(false, $payload);
	}

	/**
	 * @param  array<string, list<string>>  $details
	 */
	public static function error(string $message, array $details = []): self
	{
		$payload = ['error' => $message];

		if ($details !== []) {
			$payload['details'] = $details;
		}

		return new self(true, $payload);
	}

	/**
	 * Text for the tool_result block.
	 */
	public function toContent(): string
	{
		return json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
