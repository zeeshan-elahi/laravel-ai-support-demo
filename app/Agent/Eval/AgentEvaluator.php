<?php

namespace App\Agent\Eval;

use App\Agent\AgentRunner;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\SupportTicket;
use Throwable;

/**
 * Runs the eval tasks through the real agent and checks each run against its
 * expectations. Tickets a task creates are removed afterwards, so tasks stay
 * independent and the eval can be repeated on another model.
 */
class AgentEvaluator
{
	public function __construct(
		private readonly AgentRunner $runner,
	) {}

	/**
	 * @param  array<string, mixed>  $task
	 * @return array{id: string, passed: bool, failures: list<string>, run: ?AgentRun}
	 */
	public function evaluate(array $task, string $model): array
	{
		$lastTicketId = (int) SupportTicket::max('id');

		try {
			$run = $this->runner->run($task['question'], $model);
		} catch (Throwable $e) {
			return ['id' => $task['id'], 'passed' => false, 'failures' => ['error: '.$e->getMessage()], 'run' => null];
		}

		$newTickets = SupportTicket::with('order:id,order_number')->where('id', '>', $lastTicketId)->get();
		$failures = $this->check($task, $run, $newTickets->map(fn (SupportTicket $t) => [
			'order' => $t->order->order_number,
			'reason' => $t->reason->value,
		])->all());

		SupportTicket::whereIn('id', $newTickets->pluck('id'))->delete();

		return ['id' => $task['id'], 'passed' => $failures === [], 'failures' => $failures, 'run' => $run];
	}

	/**
	 * @param  array<string, mixed>  $task
	 * @param  list<array{order: string, reason: string}>  $tickets
	 * @return list<string>
	 */
	public function check(array $task, AgentRun $run, array $tickets): array
	{
		$failures = [];
		$answer = (string) $run->answer;
		$tools = $run->steps
			->flatMap(fn (AgentStep $step) => array_column($step->tool_calls ?? [], 'name'))
			->unique()->values()->all();

		if ($run->status->value !== 'completed') {
			$failures[] = "run ended as {$run->status->value}";
		}

		foreach ($task['tools_used'] ?? [] as $tool) {
			if (! in_array($tool, $tools, true)) {
				$failures[] = "did not call {$tool}";
			}
		}

		foreach ($task['tools_not_used'] ?? [] as $tool) {
			if (in_array($tool, $tools, true)) {
				$failures[] = "called {$tool}";
			}
		}

		if (array_key_exists('ticket', $task)) {
			if ($task['ticket'] === false && $tickets !== []) {
				$failures[] = 'created a ticket: '.json_encode($tickets);
			} elseif (is_array($task['ticket']) && ! in_array($task['ticket'], $tickets, true)) {
				$failures[] = 'expected ticket '.json_encode($task['ticket']).', got '.json_encode($tickets);
			}
		}

		foreach ($task['contains'] ?? [] as $pattern) {
			if (preg_match('/'.$pattern.'/i', $answer) !== 1) {
				$failures[] = "answer lacks /{$pattern}/";
			}
		}

		foreach ($task['not_contains'] ?? [] as $pattern) {
			if (preg_match('/'.$pattern.'/i', $answer) === 1) {
				$failures[] = "answer contains /{$pattern}/";
			}
		}

		return $failures;
	}
}
