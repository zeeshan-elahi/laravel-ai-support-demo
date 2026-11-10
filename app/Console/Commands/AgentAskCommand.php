<?php

namespace App\Console\Commands;

use App\Agent\AgentRunner;
use App\Models\AgentStep;
use Illuminate\Console\Command;

class AgentAskCommand extends Command
{
	protected $signature = 'agent:ask
		{question : A customer question about an order}
		{--model= : Model to use instead of the configured default}';

	protected $description = 'Ask the support agent a question; it looks up orders and opens tickets through its tools';

	public function handle(AgentRunner $agent): int
	{
		$run = $agent->run((string) $this->argument('question'), $this->option('model') ?: null);

		$this->line($run->answer ?? '(no answer)');
		$this->newLine();

		foreach ($run->steps as $step) {
			/** @var AgentStep $step */
			$tools = collect($step->tool_calls ?? [])
				->map(fn (array $call) => $call['name'].'('.json_encode($call['input']).')'.($call['is_error'] ? ' ERROR' : ''))
				->implode(', ');

			$this->line(sprintf(
				'  turn %d · %s · %d in (+%d cache write, %d cache read) / %d out · %d ms%s',
				$step->turn,
				$step->stop_reason,
				$step->input_tokens,
				$step->cache_write_tokens,
				$step->cache_read_tokens,
				$step->output_tokens,
				$step->latency_ms,
				$tools !== '' ? ' · '.$tools : '',
			));
		}

		$this->newLine();
		$this->line(sprintf(
			'Run #%d · %s · %s · %d turns · %d in + %d cache write + %d cache read / %d out tokens · %d ms · ~$%s',
			$run->id,
			$run->model,
			$run->status->value,
			$run->turns,
			$run->input_tokens,
			$run->cache_write_tokens,
			$run->cache_read_tokens,
			$run->output_tokens,
			$run->latency_ms,
			$run->cost_usd,
		));

		return self::SUCCESS;
	}
}
