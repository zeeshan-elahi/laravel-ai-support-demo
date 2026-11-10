<?php

namespace App\Providers;

use Anthropic\Client;
use App\Agent\AgentRunner;
use App\Agent\ClaudeClient;
use App\Agent\Contracts\LlmClient;
use App\Agent\Tools\CreateSupportTicketTool;
use App\Agent\Tools\GetOrderTool;
use App\Agent\Tools\SearchHelpCenterTool;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AgentServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		// Every tool the agent may call is registered here and nowhere else.
		$this->app->tag([GetOrderTool::class, SearchHelpCenterTool::class, CreateSupportTicketTool::class], 'agent.tools');

		$this->app->singleton(LlmClient::class, function () {
			$key = (string) config('services.anthropic.key');

			if ($key === '') {
				throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
			}

			return new ClaudeClient(
				client: new Client(apiKey: $key),
				effort: (string) config('agent.effort'),
				maxTokens: (int) config('agent.max_tokens'),
				cache: (bool) config('agent.cache'),
			);
		});

		$this->app->bind(AgentRunner::class, fn (Application $app) => new AgentRunner(
			llm: $app->make(LlmClient::class),
			tools: $app->tagged('agent.tools'),
			defaultModel: (string) config('agent.model'),
			maxTurns: (int) config('agent.max_turns'),
			cache: (bool) config('agent.cache'),
		));
	}

	public function boot(): void
	{
		// Every call to the endpoint costs money, so it is limited per client IP.
		RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('agent.rate_limit_per_minute'))
			->by($request->ip()));
	}
}
