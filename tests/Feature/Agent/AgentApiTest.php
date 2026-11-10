<?php

namespace Tests\Feature\Agent;

use App\Agent\Contracts\LlmClient;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class AgentApiTest extends TestCase
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

	public function test_creates_a_run_and_returns_the_answer_with_tools_and_usage(): void
	{
		$this->llm->push(
			FakeLlmClient::toolUse('get_order', ['order_number' => 'ORD-10003']),
			FakeLlmClient::text('ORD-10003 shipped on 12 October.'),
		);

		$this->postJson('/api/agent/ask', ['question' => 'Where is ORD-10003?'])
			->assertCreated()
			->assertJsonPath('data.status', 'completed')
			->assertJsonPath('data.answer', 'ORD-10003 shipped on 12 October.')
			->assertJsonPath('data.tools.0', ['name' => 'get_order', 'ok' => true])
			->assertJsonPath('data.usage.input_tokens', 2000)
			->assertJsonStructure(['data' => ['id', 'model', 'turns', 'usage' => ['cost_usd', 'latency_ms', 'cache_read_tokens']]]);
	}

	public function test_validates_the_question_and_model(): void
	{
		$this->postJson('/api/agent/ask', [])->assertUnprocessable()->assertJsonValidationErrors('question');
		$this->postJson('/api/agent/ask', ['question' => 'Where is ORD-10003?', 'model' => 'gpt-something'])
			->assertUnprocessable()
			->assertJsonValidationErrors('model');
		$this->assertSame([], $this->llm->requests);
	}

	public function test_is_rate_limited_per_client(): void
	{
		config(['agent.rate_limit_per_minute' => 2]);
		$this->llm->push(FakeLlmClient::text('One.'), FakeLlmClient::text('Two.'));

		$this->postJson('/api/agent/ask', ['question' => 'First question?'])->assertCreated();
		$this->postJson('/api/agent/ask', ['question' => 'Second question?'])->assertCreated();
		$this->postJson('/api/agent/ask', ['question' => 'Third question?'])->assertTooManyRequests();
	}
}
