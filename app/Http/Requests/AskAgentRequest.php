<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskAgentRequest extends FormRequest
{
	public function authorize(): bool
	{
		// Public demo endpoint: rate limited, no login.
		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function rules(): array
	{
		return [
			'question' => ['required', 'string', 'min:3', 'max:500'],
			'model' => ['sometimes', 'string', Rule::in(array_keys(config('agent.models')))],
		];
	}
}
