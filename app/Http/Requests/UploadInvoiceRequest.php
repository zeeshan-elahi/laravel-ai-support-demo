<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadInvoiceRequest extends FormRequest
{
	public function authorize(): bool
	{
		// Public demo endpoint: rate limited, no login.
		return true;
	}

	/**
	 * `mimes` checks the extension guessed from the content and `mimetypes` the
	 * detected type, so a renamed text file is refused.
	 *
	 * @return array<string, mixed>
	 */
	public function rules(): array
	{
		return [
			'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:' . config('invoices.max_upload_kb')],
			'model' => ['sometimes', 'string', Rule::in(array_keys(config('agent.models')))],
		];
	}
}
