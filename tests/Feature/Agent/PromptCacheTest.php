<?php

namespace Tests\Feature\Agent;

use App\Agent\AgentReply;
use App\Agent\AgentRunner;
use App\Agent\Contracts\LlmClient;
use App\Listeners\RecordAgentStep;
use App\Models\AgentRun;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class PromptCacheTest extends TestCase
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

	public function test_the_system_block_carries_the_cache_breakpoint(): void
	{
		$this->llm->push(FakeLlmClient::text('Which order number is it?'));

		$this->app->make(AgentRunner::class)->run('Where is my parcel?');

		$this->assertSame(['type' => 'ephemeral'], $this->llm->requests[0]['system'][0]['cacheControl']);
	}

	public function test_no_breakpoint_when_caching_is_off(): void
	{
		config(['agent.cache' => false]);
		$this->llm->push(FakeLlmClient::text('Which order number is it?'));

		$this->app->make(AgentRunner::class)->run('Where is my parcel?');

		$this->assertArrayNotHasKey('cacheControl', $this->llm->requests[0]['system'][0]);
	}

	public function test_the_cached_prefix_is_byte_identical_across_runs(): void
	{
		$this->llm->push(FakeLlmClient::text('First.'), FakeLlmClient::text('Second.'));

		$this->app->make(AgentRunner::class)->run('Where is ORD-10001?');
		$this->app->make(AgentRunner::class)->run('A different question about ORD-10002.');

		[$first, $second] = $this->llm->requests;
		$this->assertSame(json_encode($first['tools']), json_encode($second['tools']));
		$this->assertSame(json_encode($first['system']), json_encode($second['system']));
	}

	public function test_the_run_totals_cache_tokens_from_every_turn(): void
	{
		$this->llm->push(
			new AgentReply('claude-haiku-5-5', 'tool_use', [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_order', 'input' => ['order_number' => 'ORD-10003']]], '', [['id' => 'toolu_1', 'name' => 'get_order', 'input' => ['order_number' => 'ORD-10003']]], 20, 40, cacheWriteTokens: 900, cacheReadTokens: 0),
			new AgentReply('claude-haiku-5-5', 'end_turn', [['type' => 'text', 'text' => 'Shipped.']], 'Shipped.', [], 180, 60, cacheWriteTokens: 100, cacheReadTokens: 900),
		);

		$run = $this->app->make(AgentRunner::class)->run('Where is ORD-10003?');

		$this->assertSame(1000, $run->cache_write_tokens);
		$this->assertSame(900, $run->cache_read_tokens);
		$this->assertSame(900, $run->steps[1]->cache_read_tokens);
	}

	public function test_cost_prices_cache_writes_and_reads_separately(): void
	{
		$run = new AgentRun([
			'model' => 'claude-haiku-5-5',
			'input_tokens' => 200,
			'cache_write_tokens' => 1000,
			'cache_read_tokens' => 2000,
			'output_tokens' => 100,
		]);

		// 200 x $0.10 + 1000 x $0.125 + 2000 x $0.01 + 100 x $0.50 = $215 per million = $0.000215
		$this->assertSame('0.000215', RecordAgentStep::cost($run));
	}
}
