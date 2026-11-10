<?php

namespace App\Agent\Tools;

use App\Agent\Contracts\AgentTool;
use App\Agent\ToolContext;
use App\Agent\ToolResult;
use Illuminate\Support\Facades\Validator;

/**
 * Base tool: a strict JSON schema for the model, plus Laravel validation on whatever
 * input actually arrives. The model's input is never trusted on the schema alone.
 */
abstract class Tool implements AgentTool
{
	abstract protected function description(): string;

	/**
	 * JSON schema properties for the tool input.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function properties(): array;

	/**
	 * Laravel validation rules for the tool input.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function rules(): array;

	/**
	 * Run the tool on input that has passed validation.
	 *
	 * @param  array<string, mixed>  $input
	 */
	abstract protected function handle(array $input, ToolContext $context): ToolResult;

	public function definition(): array
	{
		return [
			'name' => $this->name(),
			'description' => $this->description(),
			'inputSchema' => [
				'type' => 'object',
				'properties' => $this->properties(),
				'required' => array_keys($this->properties()),
				'additionalProperties' => false,
			],
			'strict' => true,
		];
	}

	public function run(array $input, ToolContext $context): ToolResult
	{
		$validator = Validator::make($input, $this->rules());

		if ($validator->fails()) {
			return ToolResult::error('Invalid input.', $validator->errors()->toArray());
		}

		return $this->handle($validator->validated(), $context);
	}
}
