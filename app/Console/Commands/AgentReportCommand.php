<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AgentReportCommand extends Command
{
	protected $signature = 'agent:report
		{--since= : Only runs on or after this date (e.g. 2026-11-10)}
		{--recent=10 : How many recent runs to list}';

	protected $description = 'Summarise agent runs by model: outcomes, turns, latency, cost and cache hits';

	public function handle(): int
	{
		$query = AgentRun::query()->when(
			$this->option('since'),
			fn ($q, $since) => $q->where('created_at', '>=', Carbon::parse($since)->startOfDay()),
		);

		$byModel = (clone $query)
			->selectRaw('model, count(*) as runs')
			->selectRaw("sum(case when status = 'completed' then 1 else 0 end) as completed")
			->selectRaw("sum(case when status = 'refused' then 1 else 0 end) as refused")
			->selectRaw('avg(turns) as avg_turns, avg(latency_ms) as avg_latency, sum(cost_usd) as cost')
			->selectRaw('sum(input_tokens) as input, sum(cache_write_tokens) as cache_write, sum(cache_read_tokens) as cache_read, sum(output_tokens) as output')
			->groupBy('model')
			->orderBy('model')
			->get();

		if ($byModel->isEmpty()) {
			$this->warn('No agent runs yet.');

			return self::SUCCESS;
		}

		$this->table(
			['Model', 'Runs', 'Completed', 'Refused', 'Avg turns', 'Avg latency', 'Total cost', 'Cost per run', 'Cache hits'],
			$byModel->map(function ($row) {
				$allInput = $row->input + $row->cache_write + $row->cache_read;

				return [
					$row->model,
					$row->runs,
					$row->completed,
					$row->refused,
					number_format((float) $row->avg_turns, 1),
					number_format((float) $row->avg_latency).' ms',
					'$'.number_format((float) $row->cost, 6),
					'$'.number_format((float) $row->cost / $row->runs, 6),
					$allInput ? round($row->cache_read / $allInput * 100, 1).'%' : '-',
				];
			})->all(),
		);

		$this->table(
			['Run', 'When', 'Model', 'Status', 'Turns', 'Cost', 'Question'],
			(clone $query)->latest('id')->limit((int) $this->option('recent'))->get()->map(fn (AgentRun $run) => [
				$run->id,
				$run->created_at->format('m-d H:i'),
				$run->model,
				$run->status->value,
				$run->turns,
				'$'.$run->cost_usd,
				mb_strimwidth($run->question, 0, 50, '…'),
			])->all(),
		);

		return self::SUCCESS;
	}
}
