<?php

namespace Tests\Live;

use App\Agent\AgentRunner;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Calls the real API (a fraction of a cent on Haiku 5.5). Excluded from the default
 * run; run it with `php artisan test --group=live` after changing how prompts are built.
 */
#[Group('live')]
class PromptCacheLiveTest extends TestCase
{
	use RefreshDatabase;

	public function test_a_repeated_request_reads_the_shared_prefix_from_the_cache(): void
	{
		if ((string) config('services.anthropic.key') === '') {
			$this->markTestSkipped('ANTHROPIC_API_KEY is not set.');
		}

		$this->seed(OrderSeeder::class);
		$agent = $this->app->make(AgentRunner::class);

		$first = $agent->run('Where is my order ORD-10003?');
		$second = $agent->run('Where is my order ORD-10003?');

		// The first run may itself hit a warm cache, so only require that something was cached.
		$this->assertGreaterThan(0, $first->steps[0]->cache_write_tokens + $first->steps[0]->cache_read_tokens);
		$this->assertGreaterThan(0, $second->steps[0]->cache_read_tokens, 'The second run read nothing from the cache: something in the prefix changed between requests.');
	}
}
