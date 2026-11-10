<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help-center search for the agent, kept apart from the RAG documents, plus two guards
 * on support tickets: one ticket per order and reason (enforced by the database, so a
 * repeated or parallel request cannot duplicate it), and a record that the customer
 * asked for it.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('documents', function (Blueprint $table) {
			// 'general' = documents for the cited Q&A; 'support' = help-center articles for the agent.
			$table->string('collection', 30)->default('general')->after('name')->index();
		});

		Schema::table('chunks', function (Blueprint $table) {
			$table->fullText('content')->language('english');
		});

		Schema::table('support_tickets', function (Blueprint $table) {
			$table->boolean('requested_by_user')->default(false)->after('status');
			$table->unique(['order_id', 'reason']);
		});
	}

	public function down(): void
	{
		Schema::table('support_tickets', function (Blueprint $table) {
			$table->dropUnique(['order_id', 'reason']);
			$table->dropColumn('requested_by_user');
		});

		Schema::table('chunks', function (Blueprint $table) {
			$table->dropFullText(['content']);
		});

		Schema::table('documents', function (Blueprint $table) {
			$table->dropIndex(['collection']);
			$table->dropColumn('collection');
		});
	}
};
