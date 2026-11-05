<?php

return [

	/*
	| Embeddings (Voyage AI). The dimension must match the vector column in the
	| chunks migration; changing it means a fresh migration and re-ingest.
	*/
	'embeddings' => [
		'model' => env('RAG_EMBEDDING_MODEL', 'voyage-4'),
		'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1024),
		// Estimated tokens per request. 9K suits a free Voyage account (10K tokens/minute); raise it after adding a payment method.
		'max_batch_tokens' => (int) env('RAG_EMBEDDING_MAX_BATCH_TOKENS', 9000),
	],

	/*
	| Answering model (Anthropic). Opus 5.5 is the default; Haiku 5.5 is the
	| cheaper option.
	*/
	'answer_model' => env('RAG_ANSWER_MODEL', 'claude-opus-5-5'),

	/*
	| USD per million tokens for the answering model, used by `rag:eval --budget`
	| to stop a run before it spends more than intended. Opus 5.5: $4 in / $20 out.
	*/
	'answer_price_per_mtok' => [
		'input' => (float) env('RAG_ANSWER_PRICE_INPUT', 4.00),
		'output' => (float) env('RAG_ANSWER_PRICE_OUTPUT', 20.00),
	],

	/*
	| Chunking and retrieval.
	*/
	'chunk_tokens' => (int) env('RAG_CHUNK_TOKENS', 800),
	'chunk_overlap_tokens' => (int) env('RAG_CHUNK_OVERLAP_TOKENS', 100),
	'top_k' => (int) env('RAG_TOP_K', 5),

];
