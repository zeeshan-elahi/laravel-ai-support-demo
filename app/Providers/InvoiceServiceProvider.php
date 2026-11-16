<?php

namespace App\Providers;

use Anthropic\Client;
use App\Invoices\ClaudeInvoiceExtractor;
use App\Invoices\Contracts\InvoiceExtractor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class InvoiceServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->singleton(InvoiceExtractor::class, function () {
			$key = (string) config('services.anthropic.key');

			if ($key === '') {
				throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
			}

			return new ClaudeInvoiceExtractor(
				client: new Client(apiKey: $key),
				effort: (string) config('invoices.effort'),
				maxTokens: (int) config('invoices.max_tokens'),
			);
		});
	}

	public function boot(): void
	{
		// Every upload triggers a paid model call, so uploads are limited per client IP.
		RateLimiter::for('invoices', fn (Request $request) => Limit::perMinute((int) config('invoices.rate_limit_per_minute'))
			->by($request->ip()));
	}
}
