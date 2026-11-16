<?php

return [

	/*
	| Invoice extraction: a PDF goes to Claude with a JSON schema (structured
	| outputs); the reply is validated and rechecked in code (InvoiceReviewer).
	| Haiku 5.5 is the default, Opus 5.5 the comparison in the eval. Prices and the
	| refusal-fallback switch come from config/agent.php `models`.
	*/
	'model' => env('INVOICE_MODEL', 'claude-haiku-5-5'),
	'comparison_model' => env('INVOICE_COMPARISON_MODEL', 'claude-opus-5-5'),
	'effort' => env('INVOICE_EFFORT', 'medium'),
	'max_tokens' => (int) env('INVOICE_MAX_TOKENS', 16000),

	/*
	| Where uploaded originals are kept, and the largest PDF accepted (KB). The API
	| itself takes up to 32 MB per request; 10 MB is plenty for invoices.
	*/
	'disk' => env('INVOICE_DISK', 'local'),
	'directory' => 'invoices',
	'max_upload_kb' => (int) env('INVOICE_MAX_UPLOAD_KB', 10240),

	/*
	| Uploads per minute per IP on POST /api/invoices (each one is a paid call).
	*/
	'rate_limit_per_minute' => (int) env('INVOICE_RATE_LIMIT', 10),

];
