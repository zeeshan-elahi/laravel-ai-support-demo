<?php

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Models\Chunk;

/**
 * Full-text search over the help-center articles (the 'support' collection). What it
 * returns is reference text written by someone else, so the agent treats it as data.
 */
class SearchHelpCenterTool extends Tool
{
	public function name(): string
	{
		return 'search_help_center';
	}

	protected function description(): string
	{
		return 'Search the shop\'s help-center articles about returns, refunds, shipping, cancellations, warranty and '
			.'other policies. Pass two to five keywords, for example "return damaged item". Returns article excerpts.';
	}

	protected function properties(): array
	{
		return [
			'query' => [
				'type' => 'string',
				'description' => 'Two to five keywords.',
			],
		];
	}

	protected function rules(): array
	{
		return [
			'query' => ['required', 'string', 'min:3', 'max:200'],
		];
	}

	protected function handle(array $input, ToolContext $context): ToolResult
	{
		// Any keyword may match (websearch syntax with "or"); the best-ranked excerpts come first.
		$words = array_slice(preg_split('/[^\pL\pN]+/u', mb_strtolower($input['query']), -1, PREG_SPLIT_NO_EMPTY), 0, 8);

		if ($words === []) {
			return ToolResult::error('The search needs at least one keyword.');
		}

		$chunks = Chunk::query()
			->inCollection('support')
			->with('document:id,name')
			->whereFullText('content', implode(' or ', $words), ['language' => 'english', 'mode' => 'websearch'])
			->orderByRaw("ts_rank(to_tsvector('english', content), websearch_to_tsquery('english', ?)) desc", [implode(' or ', $words)])
			->limit(3)
			->get(['id', 'document_id', 'content']);

		return ToolResult::ok([
			'results' => $chunks->map(fn (Chunk $chunk) => [
				'article' => $chunk->document->name,
				'excerpt' => $chunk->content,
			])->all(),
		]);
	}
}
