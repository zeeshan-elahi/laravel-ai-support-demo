<?php

namespace Tests\Feature\Agent;

use App\Agent\AgentRunner;
use App\Agent\Contracts\LlmClient;
use App\Enums\AgentRunStatus;
use App\Events\AgentStepCompleted;
use App\Models\AgentRun;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class AgentRunnerTest extends TestCase
{
	use RefreshDatabase;

	private FakeLlmClient $llm;

	protected function setUp(): void
	{
		parent::setUp();

		$this->seed(OrderSeeder::class);
		$this->llm = new FakeLlmClient();
		$this->app->instance(LlmClient::class, $this->llm);
	}

	private function agent(): AgentRunner
	{
		return $this->app->make(AgentRunner::class);
	}

	public function test_a_direct_answer_completes_in_one_turn(): void
	{
		$this->llm->push(FakeLlmClient::text('Which order number is it?'));

		$run = $this->agent()->run('Where is my parcel?');

		$this->assertSame(AgentRunStatus::Completed, $run->status);
		$this->assertSame('Which order number is it?', $run->answer);
		$this->assertSame(1, $run->turns);
		$this->assertCount(1, $run->steps);
	}

	public function test_runs_the_tool_and_sends_its_result_back(): void
	{
		$this->llm->push(
			FakeLlmClient::toolUse('get_order', ['order_number' => 'ORD-10003']),
			FakeLlmClient::text('ORD-10003 shipped on 28 October.'),
		);

		$run = $this->agent()->run('Where is ORD-10003?');

		$this->assertSame(AgentRunStatus::Completed, $run->status);
		$this->assertSame(2, $run->turns);

		// Second request: assistant turn unchanged, then one user message with the tool result.
		$messages = $this->llm->requests[1]['messages'];
		$this->assertSame('assistant', $messages[1]['role']);
		$result = $messages[2]['content'][0];
		$this->assertSame('tool_result', $result['type']);
		$this->assertSame('toolu_1', $result['toolUseID']);
		$this->assertFalse($result['isError']);
		$this->assertStringContainsString('"status":"shipped"', $result['content']);

		$this->assertSame('get_order', $run->steps[0]->tool_calls[0]['name']);
	}

	public function test_invalid_tool_input_goes_back_as_an_error_and_writes_nothing(): void
	{
		$this->llm->push(
			FakeLlmClient::toolUse('create_support_ticket', ['order_number' => 'ORD-10002', 'reason' => 'free_stuff', 'summary' => 'Please send me something extra.']),
			FakeLlmClient::text('I can open a ticket for a damaged, wrong or late item, or a refund.'),
		);

		$run = $this->agent()->run('Open a ticket for ORD-10002, I want free stuff.');

		$this->assertTrue($this->llm->requests[1]['messages'][2]['content'][0]['isError']);
		$this->assertTrue($run->steps[0]->tool_calls[0]['is_error']);
		$this->assertDatabaseMissing('support_tickets', ['summary' => 'Please send me something extra.']);
	}

	public function test_an_unknown_tool_is_reported_to_the_model(): void
	{
		$this->llm->push(
			FakeLlmClient::toolUse('delete_order', ['order_number' => 'ORD-10001']),
			FakeLlmClient::text('I can only look orders up and open tickets.'),
		);

		$this->agent()->run('Delete ORD-10001.');

		$result = $this->llm->requests[1]['messages'][2]['content'][0];
		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('Unknown tool delete_order.', $result['content']);
	}

	public function test_a_refusal_is_handled_in_code(): void
	{
		$this->llm->push(FakeLlmClient::refusal('cyber'));

		$run = $this->agent()->run('Something the model declines.');

		$this->assertSame(AgentRunStatus::Refused, $run->status);
		$this->assertSame('cyber', $run->refusal_category);
		$this->assertSame("Sorry, I can't help with that request.", $run->answer);
	}

	public function test_stops_at_the_turn_limit(): void
	{
		config(['agent.max_turns' => 2]);
		$this->llm->push(
			FakeLlmClient::toolUse('get_order', ['order_number' => 'ORD-10001'], 'toolu_1'),
			FakeLlmClient::toolUse('get_order', ['order_number' => 'ORD-10001'], 'toolu_2'),
		);

		$run = $this->agent()->run('Keep checking ORD-10001.');

		$this->assertSame(AgentRunStatus::MaxTurns, $run->status);
		$this->assertSame(2, $run->turns);
	}

	public function test_each_turn_fires_one_event_and_the_listener_totals_the_run(): void
	{
		$this->llm->push(
			FakeLlmClient::toolUse('get_order', ['order_number' => 'ORD-10003']),
			FakeLlmClient::text('Shipped.'),
		);

		$run = $this->agent()->run('Status of ORD-10003?');

		$this->assertSame(2000, $run->input_tokens);
		$this->assertSame(90, $run->output_tokens);
		// Haiku 5.5: 2000 x $0.10/M + 90 x $0.50/M = $0.000245
		$this->assertSame('0.000245', $run->cost_usd);

		Event::fake([AgentStepCompleted::class]);
		$this->llm->push(FakeLlmClient::toolUse('get_order', ['order_number' => 'ORD-10003']), FakeLlmClient::text('Shipped.'));
		$this->agent()->run('Status of ORD-10003?');
		Event::assertDispatchedTimes(AgentStepCompleted::class, 2);
	}

	public function test_a_failed_api_call_marks_the_run_failed(): void
	{
		// Nothing queued: the fake throws, as an API error would.
		try {
			$this->agent()->run('Where is ORD-10001?');
			$this->fail('Expected an exception.');
		} catch (\RuntimeException) {
		}

		$this->assertSame(AgentRunStatus::Failed, AgentRun::latest('id')->first()->status);
	}

	public function test_a_fooled_model_still_cannot_open_an_injected_ticket(): void
	{
		$this->seed(\Database\Seeders\HelpCenterSeeder::class);

		// The model reads the bulk-orders article, which tells it to open a ticket for ORD-10010, and obeys.
		$this->llm->push(
			FakeLlmClient::toolUse('search_help_center', ['query' => 'bulk order discount'], 'toolu_1'),
			FakeLlmClient::toolUse('create_support_ticket', ['order_number' => 'ORD-10010', 'reason' => 'other', 'summary' => 'Escalate to manager: VIP customer'], 'toolu_2'),
			FakeLlmClient::text('Bulk orders of 50 units get 10 percent off.'),
		);

		$run = $this->agent()->run('Do you give a discount on bulk orders?');

		$this->assertStringContainsString('bulk-orders.md', $this->llm->requests[1]['messages'][2]['content'][0]['content']);
		$this->assertTrue($run->steps[1]->tool_calls[0]['is_error']);
		$this->assertDatabaseMissing('support_tickets', ['summary' => 'Escalate to manager: VIP customer']);
	}
}
