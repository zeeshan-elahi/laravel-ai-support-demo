<?php

namespace App\Console\Commands;

use App\Services\Rag\Answerer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs the evaluation set against the stored documents and reports:
 * - retrieval hit rate: an expected page of the expected document is among the retrieved chunks
 * - answer correctness: the answer is supported (cited), contains every expected keyword,
 *   and cites the expected document
 * - out-of-scope handling: questions the documents can't answer get "not covered", not an invented answer
 */
class EvalCommand extends Command
{
	protected $signature = 'rag:eval
		{--file=database/eval/questions.json : The evaluation set}
		{--only= : Comma-separated question ids to run}
		{--budget=1.00 : Stop once the estimated answering cost passes this many US dollars}
		{--pace=0 : Seconds to wait between questions (20 keeps a free Voyage account under 3 requests a minute)}';

	protected $description = 'Score retrieval and answers against the evaluation set';

	public function handle(): int
	{
		$set = json_decode((string) file_get_contents(base_path($this->option('file'))), true, flags: JSON_THROW_ON_ERROR);
		$only = $this->option('only') ? array_map('intval', explode(',', $this->option('only'))) : null;
		$keep = fn (array $q) => $only === null || in_array($q['id'], $only, true);
		$budget = (float) $this->option('budget');
		$pace = max(0, (int) $this->option('pace'));

		$queue = [
			...array_map(fn ($q) => ['answerable', $q], array_values(array_filter($set['questions'], $keep))),
			...array_map(fn ($q) => ['out_of_scope', $q], array_values(array_filter($set['out_of_scope'] ?? [], $keep))),
		];

		$answerer = Answerer::fromConfig();
		$rows = [];

		foreach ($queue as $i => [$type, $q]) {
			$spent = $this->estimatedCost($rows);

			if ($spent >= $budget) {
				$this->warn(sprintf('Stopped: estimated spend $%.2f reached the $%.2f budget. %d questions not run.', $spent, $budget, count($queue) - $i));

				break;
			}

			if ($i > 0 && $pace > 0) {
				sleep($pace);
			}

			$rows[] = $type === 'answerable'
				? $this->scoreAnswerable($answerer, $q)
				: $this->scoreOutOfScope($answerer, $q);
		}

		$this->report($rows);
		$this->line(sprintf('Estimated answering cost: $%.2f (budget $%.2f)', $this->estimatedCost($rows), $budget));

		$path = 'eval/results-'.now()->format('Ymd-His').'.json';
		Storage::disk('local')->put($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$this->line('Details saved to storage/app/private/'.$path);

		return self::SUCCESS;
	}

	private function scoreAnswerable(Answerer $answerer, array $q): array
	{
		$result = $answerer->ask($q['question']);
		$answer = $this->answerText($result);

		// A question expects one source ({document, pages}) or several ({sources: [...], match: all|any}).
		$expected = $q['sources'] ?? [['document' => $q['document'], 'pages' => $q['pages']]];
		$matchAll = ($q['match'] ?? 'all') === 'all';
		$check = fn (callable $test) => $matchAll
			? collect($expected)->every($test)
			: collect($expected)->contains($test);

		$retrievalHit = $check(fn ($e) => collect($result['retrieved'])->contains(
			fn ($r) => $r['document'] === $e['document'] && in_array($r['page'], $e['pages'], true)
		));

		$missing = array_values(array_filter(
			$q['keywords'],
			fn ($k) => ! str_contains(Str::lower($answer), Str::lower($k))
		));

		$citesExpected = $check(fn ($e) => collect($result['sources'])->contains(fn ($s) => $s['document'] === $e['document']));
		$correct = $result['supported'] && $missing === [] && $citesExpected;

		$this->line(sprintf('#%-2d %s retrieval %s answer  %s', $q['id'], $retrievalHit ? '✓' : '✗', $correct ? '✓' : '✗', Str::limit($q['question'], 70)));

		return [
			'id' => $q['id'],
			'type' => 'answerable',
			'question' => $q['question'],
			'expected' => ['sources' => $expected, 'match' => $matchAll ? 'all' : 'any', 'keywords' => $q['keywords']],
			'retrieval_hit' => $retrievalHit,
			'correct' => $correct,
			'supported' => $result['supported'],
			'cites_expected_document' => $citesExpected,
			'missing_keywords' => $missing,
			'answer' => $answer,
			'sources' => $result['sources'],
			'retrieved' => $result['retrieved'],
			'input_tokens' => $result['input_tokens'],
			'output_tokens' => $result['output_tokens'],
		];
	}

	private function scoreOutOfScope(Answerer $answerer, array $q): array
	{
		$result = $answerer->ask($q['question']);
		$answer = $this->answerText($result);

		$declined = ! $result['supported']
			|| (bool) preg_match("/(don['’]t|do not|does not|doesn['’]t) (cover|contain|include|mention|say|address|give|provide|state|list|specify)|not (covered|mentioned|included)|no information/i", $answer);

		$this->line(sprintf('#%-2d out-of-scope %s  %s', $q['id'], $declined ? '✓ declined' : '✗ answered', Str::limit($q['question'], 70)));

		return [
			'id' => $q['id'],
			'type' => 'out_of_scope',
			'question' => $q['question'],
			'declined' => $declined,
			'answer' => $answer,
			'sources' => $result['sources'],
			'input_tokens' => $result['input_tokens'],
			'output_tokens' => $result['output_tokens'],
		];
	}

	private function estimatedCost(array $rows): float
	{
		$price = config('rag.answer_price_per_mtok');

		return (collect($rows)->sum('input_tokens') * $price['input']
			+ collect($rows)->sum('output_tokens') * $price['output']) / 1_000_000;
	}

	private function answerText(array $result): string
	{
		return implode('', array_column($result['segments'], 'text'));
	}

	private function report(array $rows): void
	{
		$answerable = collect($rows)->where('type', 'answerable');
		$outOfScope = collect($rows)->where('type', 'out_of_scope');
		$pct = fn (int $n, int $of) => $of === 0 ? '–' : sprintf('%d/%d (%d%%)', $n, $of, round(100 * $n / $of));

		$this->newLine();
		$this->line('Retrieval hit rate:   '.$pct($answerable->where('retrieval_hit', true)->count(), $answerable->count()));
		$this->line('Answer correctness:   '.$pct($answerable->where('correct', true)->count(), $answerable->count()));
		$this->line('Out-of-scope handled: '.$pct($outOfScope->where('declined', true)->count(), $outOfScope->count()));
		$this->line(sprintf(
			'Tokens: %s in / %s out',
			number_format(collect($rows)->sum('input_tokens')),
			number_format(collect($rows)->sum('output_tokens')),
		));
	}
}
