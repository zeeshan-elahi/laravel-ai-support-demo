<?php

namespace App\Console\Commands;

use App\Agent\Eval\AgentEvaluator;
use App\Models\Document;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AgentEvalCommand extends Command
{
	protected $signature = 'agent:eval
		{--model= : Model to evaluate instead of the configured default}
		{--only= : Comma-separated task ids to run}';

	protected $description = 'Run the agent evaluation tasks and report pass rate, cost per task and cache hits';

	public function handle(AgentEvaluator $evaluator): int
	{
		if (Order::count() !== 50 || Document::where('collection', 'support')->count() === 0) {
			$this->error('Seed the eval data first: php artisan db:seed --class=OrderSeeder and --class=HelpCenterSeeder.');

			return self::FAILURE;
		}

		$model = $this->option('model') ?: (string) config('agent.model');
		$tasks = require resource_path('agent-eval/tasks.php');

		if ($only = $this->option('only')) {
			$ids = array_map('trim', explode(',', $only));
			$tasks = array_values(array_filter($tasks, fn (array $task) => in_array($task['id'], $ids, true)));
		}

		$this->info("Evaluating {$model} on ".count($tasks).' tasks');
		$results = [];

		foreach ($tasks as $task) {
			$result = $evaluator->evaluate($task, $model);
			$results[] = $result;
			$run = $result['run'];

			$this->line(sprintf(
				'  %s %-26s %s',
				$result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>',
				$task['id'],
				$run ? sprintf('%d turns · %d ms · $%s', $run->turns, $run->latency_ms, $run->cost_usd) : '',
			));

			foreach ($result['failures'] as $failure) {
				$this->line("       - {$failure}");
			}
		}

		$summary = $this->summarise($model, $results);
		$this->newLine();
		$this->table(['Model', 'Passed', 'Cost (total)', 'Cost per task', 'Cache hits', 'Avg latency'], [[
			$summary['model'],
			"{$summary['passed']}/{$summary['tasks']}",
			'$'.number_format($summary['cost_usd'], 6),
			'$'.number_format($summary['cost_per_task_usd'], 6),
			round($summary['cache_hit_ratio'] * 100, 1).'%',
			$summary['avg_latency_ms'].' ms',
		]]);

		$path = 'agent-eval/'.now()->format('Y-m-d-His').'-'.$model.'.json';
		Storage::put($path, json_encode([
			'summary' => $summary,
			'results' => array_map(fn (array $r) => [
				'id' => $r['id'],
				'passed' => $r['passed'],
				'failures' => $r['failures'],
				'run_id' => $r['run']?->id,
				'answer' => $r['run']?->answer,
			], $results),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$this->line("Report: storage/app/private/{$path}");

		return $summary['passed'] === $summary['tasks'] ? self::SUCCESS : self::FAILURE;
	}

	/**
	 * @param  list<array{id: string, passed: bool, failures: list<string>, run: mixed}>  $results
	 * @return array<string, mixed>
	 */
	private function summarise(string $model, array $results): array
	{
		$runs = collect($results)->pluck('run')->filter();
		$cost = (float) $runs->sum(fn ($run) => (float) $run->cost_usd);
		$read = $runs->sum('cache_read_tokens');
		$allInput = $runs->sum('input_tokens') + $runs->sum('cache_write_tokens') + $read;

		return [
			'model' => $model,
			'tasks' => count($results),
			'passed' => count(array_filter($results, fn (array $r) => $r['passed'])),
			'cost_usd' => round($cost, 6),
			'cost_per_task_usd' => count($results) ? round($cost / count($results), 6) : 0,
			'cache_hit_ratio' => $allInput ? round($read / $allInput, 4) : 0,
			'avg_latency_ms' => (int) round($runs->avg('latency_ms') ?? 0),
			'input_tokens' => $runs->sum('input_tokens'),
			'cache_write_tokens' => $runs->sum('cache_write_tokens'),
			'cache_read_tokens' => $read,
			'output_tokens' => $runs->sum('output_tokens'),
		];
	}
}
