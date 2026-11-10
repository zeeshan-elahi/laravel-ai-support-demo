<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('agent_runs', function (Blueprint $table) {
			$table->id();
			$table->text('question');
			$table->string('model', 60);
			$table->string('status', 20)->index();
			$table->text('answer')->nullable();
			$table->string('refusal_category', 40)->nullable();
			$table->unsignedTinyInteger('turns')->default(0);
			$table->unsignedInteger('input_tokens')->default(0);
			$table->unsignedInteger('output_tokens')->default(0);
			$table->unsignedInteger('latency_ms')->default(0);
			$table->decimal('cost_usd', 10, 6)->default(0);
			$table->timestamps();
		});

		Schema::create('agent_steps', function (Blueprint $table) {
			$table->id();
			$table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
			$table->unsignedTinyInteger('turn');
			$table->string('stop_reason', 30)->nullable();
			$table->text('text')->nullable();
			$table->json('tool_calls')->nullable();
			$table->unsignedInteger('input_tokens')->default(0);
			$table->unsignedInteger('output_tokens')->default(0);
			$table->unsignedInteger('latency_ms')->default(0);
			$table->timestamps();

			$table->unique(['agent_run_id', 'turn']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('agent_steps');
		Schema::dropIfExists('agent_runs');
	}
};
