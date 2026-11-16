<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Invoice extraction. Money columns hold integer minor units (fils, cents), never
| floats. An invoice row exists from upload (status pending) so a queued
| extraction has something to update; every model call is logged in
| invoice_extractions, including the failed ones.
*/
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('invoices', function (Blueprint $table) {
			$table->id();
			$table->string('source_path');
			$table->string('original_name');
			$table->char('source_sha256', 64)->index();
			$table->string('status', 20)->index();
			$table->json('failed_checks')->nullable();
			$table->string('document_type', 20)->nullable();
			$table->string('invoice_number', 60)->nullable();
			$table->date('issue_date')->nullable();
			$table->date('due_date')->nullable();
			$table->string('supplier_name')->nullable();
			$table->string('supplier_trn', 30)->nullable();
			$table->string('customer_name')->nullable();
			$table->string('customer_trn', 30)->nullable();
			$table->char('currency', 3)->nullable();
			$table->bigInteger('subtotal')->nullable();
			$table->bigInteger('discount')->nullable();
			$table->decimal('vat_rate', 5, 2)->nullable();
			$table->bigInteger('vat_amount')->nullable();
			$table->bigInteger('total')->nullable();
			$table->timestamps();

			$table->index(['supplier_trn', 'invoice_number']);
		});

		Schema::create('invoice_lines', function (Blueprint $table) {
			$table->id();
			$table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
			$table->unsignedSmallInteger('position');
			$table->string('description', 500);
			$table->decimal('quantity', 12, 3);
			$table->bigInteger('unit_price');
			$table->bigInteger('line_total');
			$table->timestamps();
		});

		Schema::create('invoice_extractions', function (Blueprint $table) {
			$table->id();
			$table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
			$table->string('model', 60);
			$table->string('effort', 10)->nullable();
			$table->string('status', 20);
			$table->string('stop_reason', 30)->nullable();
			$table->unsignedInteger('input_tokens')->default(0);
			$table->unsignedInteger('output_tokens')->default(0);
			$table->unsignedInteger('latency_ms')->nullable();
			$table->decimal('cost_usd', 10, 6)->default(0);
			$table->json('output')->nullable();
			$table->text('error')->nullable();
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('invoice_extractions');
		Schema::dropIfExists('invoice_lines');
		Schema::dropIfExists('invoices');
	}
};
