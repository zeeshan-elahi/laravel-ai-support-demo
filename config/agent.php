<?php

return [

	/*
	| Support agent (tool use over the orders data). Haiku 5.5 is the default;
	| Opus 5.5 is the comparison model in the eval. Thinking stays at the model
	| default (adaptive); effort is set explicitly.
	*/
	'model' => env('AGENT_MODEL', 'claude-haiku-5-5'),
	'comparison_model' => env('AGENT_COMPARISON_MODEL', 'claude-opus-5-5'),
	'effort' => env('AGENT_EFFORT', 'medium'),
	'max_turns' => (int) env('AGENT_MAX_TURNS', 6),
	'max_tokens' => (int) env('AGENT_MAX_TOKENS', 8000),

	/*
	| Requests per minute per IP on POST /api/agent/ask.
	*/
	'rate_limit_per_minute' => (int) env('AGENT_RATE_LIMIT', 10),

	/*
	| Prompt caching: an explicit breakpoint after the tools and system prompt (the
	| part every request shares) plus automatic caching of the growing conversation.
	| Off only to measure the difference.
	*/
	'cache' => (bool) env('AGENT_CACHE', true),

	/*
	| Per-model settings. `fallbacks` turns on the server-side refusal fallback,
	| which Haiku 5.5 does not have (a refusal there is handled in code).
	| Prices are USD per million tokens (pricing page, 2026-11-10): base input,
	| 5-minute cache write, cache read, output. Haiku 5.5 prices are for prompts up
	| to 100,000 tokens.
	*/
	'models' => [
		'claude-haiku-5-5' => [
			'fallbacks' => false,
			'price' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
		],
		'claude-opus-5-5' => [
			'fallbacks' => true,
			'price' => ['input' => 4.00, 'cache_write' => 5.00, 'cache_read' => 0.20, 'output' => 20.00],
		],
	],

];
