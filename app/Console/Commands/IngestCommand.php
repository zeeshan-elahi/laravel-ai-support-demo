<?php

namespace App\Console\Commands;

use App\Actions\IngestDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IngestCommand extends Command
{
	protected $signature = 'rag:ingest {file : Path to a PDF or text file}';

	protected $description = 'Extract, chunk, embed and store one document';

	public function handle(): int
	{
		$file = (string) $this->argument('file');

		if (! is_file($file)) {
			$this->error("File not found: {$file}");

			return self::FAILURE;
		}

		$mimeType = (string) mime_content_type($file);

		if ($mimeType === 'text/x-php' || str_ends_with(strtolower($file), '.md')) {
			$mimeType = 'text/plain';
		}

		$result = IngestDocument::make()->handle($file, basename($file), $mimeType);
		$document = $result['document'];

		$this->info("Ingested \"{$document->name}\" as document #{$document->id}: {$document->chunk_count} ".Str::plural("chunk", $document->chunk_count).", {$result['tokens']} embedding tokens.");

		return self::SUCCESS;
	}
}
