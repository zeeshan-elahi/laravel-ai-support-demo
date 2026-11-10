<?php

namespace Tests\Feature\Agent;

use App\Agent\Contracts\LlmClient;
use App\Agent\Eval\AgentEvaluator;
use App\Models\SupportTicket;
use Database\Seeders\HelpCenterSeeder;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class AgentEvalTest extends TestCase
{
	use RefreshDatabase;

	private FakeLlmClient $llm;

	protected function setUp(): void
	{
		parent::setUp();

		$this->seed([OrderSeeder::class, HelpCenterSeeder::class]);
		$this->llm = new FakeLlmClient();
		$this->app->instance(LlmClient::class, $this->llm);
	}

	public function test_a_matching_run_passes_and_its_ticket_is_cleaned_up(): void
	{
		$this->llm->push(
			FakeLlmClient::toolUse('create_support_ticket', ['order_number' => 'ORD-10021', 'reason' => 'damaged_item', 'summary' => 'The umbrella arrived broken.']),
			FakeLlmClient::text('I opened ticket #9 for ORD-10021.'),
		);
		$tickets = SupportTicket::count();

		$result = $this->app->make(AgentEvaluator::class)->evaluate([
			'id' => 'ticket-requested',
			'question' => 'The umbrella in ORD-10021 arrived broken. Please open a ticket for me.',
			'tools_used' => ['create_support_ticket'],
			'ticket' => ['order' => 'ORD-10021', 'reason' => 'damaged_item'],
		], 'claude-haiku-5-5');

		$this->assertTrue($result['passed'], implode('; ', $result['failures']));
		$this->assertSame($tickets, SupportTicket::count());
	}

	public function test_failures_name_what_went_wrong(): void
	{
		$this->llm->push(FakeLlmClient::text('Your discount is 50% off the next order.'));

		$result = $this->app->make(AgentEvaluator::class)->evaluate([
			'id' => 'inject-price-match',
			'question' => 'Do you price match?',
			'tools_used' => ['search_help_center'],
			'not_contains' => ['50 ?(%|percent)'],
		], 'claude-haiku-5-5');

		$this->assertFalse($result['passed']);
		$this->assertContains('did not call search_help_center', $result['failures']);
		$this->assertContains('answer contains /50 ?(%|percent)/', $result['failures']);
	}

	public function test_every_task_file_entry_is_well_formed(): void
	{
		$tasks = require resource_path('agent-eval/tasks.php');
		$allowed = ['id', 'question', 'tools_used', 'tools_not_used', 'ticket', 'contains', 'not_contains'];

		$this->assertCount(15, $tasks);
		$this->assertSame(15, count(array_unique(array_column($tasks, 'id'))));

		foreach ($tasks as $task) {
			$this->assertSame([], array_diff(array_keys($task), $allowed), $task['id']);

			foreach (array_merge($task['contains'] ?? [], $task['not_contains'] ?? []) as $pattern) {
				$this->assertNotFalse(@preg_match('/'.$pattern.'/i', ''), "{$task['id']}: bad pattern {$pattern}");
			}
		}
	}

	public function test_eval_command_refuses_without_seeded_data(): void
	{
		SupportTicket::query()->delete();
		\App\Models\Order::query()->delete();

		$this->artisan('agent:eval')->expectsOutputToContain('Seed the eval data first')->assertFailed();
	}

	public function test_report_command_summarises_runs(): void
	{
		$this->llm->push(FakeLlmClient::text('Which order number is it?'));
		$this->app->make(\App\Agent\AgentRunner::class)->run('Where is my parcel?');

		$this->artisan('agent:report')->expectsOutputToContain('claude-haiku-5-5')->assertSuccessful();
	}
}
