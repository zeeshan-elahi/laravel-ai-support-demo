<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('orders', function (Blueprint $table) {
			$table->id();
			$table->string('order_number', 20)->unique();
			$table->string('customer_name');
			$table->string('customer_email');
			$table->string('status', 20)->index();
			$table->char('currency', 3)->default('USD');
			$table->unsignedInteger('total_cents')->default(0);
			$table->string('tracking_number', 40)->nullable();
			$table->timestamp('placed_at');
			$table->timestamp('shipped_at')->nullable();
			$table->timestamp('delivered_at')->nullable();
			$table->timestamps();
		});

		Schema::create('order_items', function (Blueprint $table) {
			$table->id();
			$table->foreignId('order_id')->constrained()->cascadeOnDelete();
			$table->string('sku', 40);
			$table->string('product_name');
			$table->unsignedSmallInteger('quantity');
			$table->unsignedInteger('unit_price_cents');
			$table->timestamps();
		});

		Schema::create('support_tickets', function (Blueprint $table) {
			$table->id();
			$table->foreignId('order_id')->constrained()->cascadeOnDelete();
			$table->string('reason', 30);
			$table->text('summary');
			$table->string('status', 20)->default('open');
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('support_tickets');
		Schema::dropIfExists('order_items');
		Schema::dropIfExists('orders');
	}
};
