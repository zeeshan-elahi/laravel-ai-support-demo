<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt caching splits a request's input into three counts: uncached input, tokens
 * written to the cache and tokens read from it. Runs and steps record all three.
 */
return new class extends Migration
{
	public function up(): void
	{
		foreach (['agent_runs', 'agent_steps'] as $table) {
			Schema::table($table, function (Blueprint $table) {
				$table->unsignedInteger('cache_write_tokens')->default(0)->after('output_tokens');
				$table->unsignedInteger('cache_read_tokens')->default(0)->after('cache_write_tokens');
			});
		}
	}

	public function down(): void
	{
		foreach (['agent_runs', 'agent_steps'] as $table) {
			Schema::table($table, function (Blueprint $table) {
				$table->dropColumn(['cache_write_tokens', 'cache_read_tokens']);
			});
		}
	}
};
