<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::ensureVectorExtensionExists();

		Schema::create('documents', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->string('mime_type', 100);
			$table->string('path');
			$table->unsignedInteger('page_count')->nullable();
			$table->unsignedInteger('chunk_count')->default(0);
			$table->timestamps();
		});

		Schema::create('chunks', function (Blueprint $table) {
			$table->id();
			$table->foreignId('document_id')->constrained()->cascadeOnDelete();
			$table->unsignedInteger('chunk_index');
			$table->unsignedInteger('page_number')->nullable();
			$table->text('content');
			$table->unsignedInteger('token_estimate');
			$table->vector('embedding', dimensions: config('rag.embeddings.dimensions'))->nullable();
			$table->timestamps();

			$table->unique(['document_id', 'chunk_index']);
			$table->vectorIndex('embedding');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('chunks');
		Schema::dropIfExists('documents');
	}
};
