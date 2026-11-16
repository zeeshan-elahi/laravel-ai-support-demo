<?php

namespace App\Support;

/**
 * Estimated cost of a model call from the prices in config/agent.php `models`
 * (USD per million tokens). With caching, input tokens are only the uncached
 * remainder; cache writes and reads are billed at their own prices. A model
 * without configured prices costs 0 rather than failing the run.
 */
final class TokenCost
{
	public static function usd(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): string
	{
		$price = config("agent.models.{$model}.price", []) + [
			'input' => 0, 'cache_write' => 0, 'cache_read' => 0, 'output' => 0,
		];

		$usd = ($input * $price['input']
			+ $cacheWrite * $price['cache_write']
			+ $cacheRead * $price['cache_read']
			+ $output * $price['output']) / 1_000_000;

		return number_format($usd, 6, '.', '');
	}
}
