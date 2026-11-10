<?php

namespace App\Agent\Contracts;

use App\Agent\ToolContext;
use App\Agent\ToolResult;

interface AgentTool
{
	/**
	 * Tool name the model calls.
	 */
	public function name(): string;

	/**
	 * Tool definition sent to the Messages API.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array;

	/**
	 * Validate the model's input and run the tool. The context comes from the
	 * customer's message, never from the model.
	 *
	 * @param  array<string, mixed>  $input
	 */
	public function run(array $input, ToolContext $context): ToolResult;
}
