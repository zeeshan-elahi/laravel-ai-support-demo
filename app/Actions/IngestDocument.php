<?php

namespace App\Actions;

use App\Models\Document;
use App\Services\Rag\Chunker;
use App\Services\Rag\TextExtractor;
use App\Services\Rag\VoyageEmbedder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stores a file, extracts its text, chunks it, embeds the chunks and saves everything.
 * The database write happens in one transaction, so a failed embedding call leaves
 * no half-ingested document behind.
 */
class IngestDocument
{
	public function __construct(
		private readonly TextExtractor $extractor,
		private readonly Chunker $chunker,
		private readonly VoyageEmbedder $embedder,
	) {}

	public static function make(): self
	{
		return new self(
			new TextExtractor,
			new Chunker((int) config('rag.chunk_tokens'), (int) config('rag.chunk_overlap_tokens')),
			VoyageEmbedder::fromConfig(),
		);
	}

	/**
	 * @return array{document: Document, tokens: int}
	 */
	public function handle(string $sourcePath, string $originalName, string $mimeType): array
	{
		if (! in_array($mimeType, TextExtractor::SUPPORTED_MIME_TYPES, true)) {
			throw new RuntimeException("Unsupported file type: {$mimeType}. Upload a PDF or a text file.");
		}

		$storedPath = Storage::disk('local')->putFile('documents', $sourcePath);

		try {
			$pages = $this->extractor->extract(Storage::disk('local')->path($storedPath), $mimeType);

			if ($pages === []) {
				throw new RuntimeException("No text found in {$originalName}. Scanned PDFs need OCR, which this demo does not do.");
			}

			$chunks = $this->chunker->chunk($pages);
			$embedded = $this->embedder->embedDocuments(array_column($chunks, 'content'));

			$document = DB::transaction(function () use ($originalName, $mimeType, $storedPath, $pages, $chunks, $embedded) {
				$document = Document::create([
					'name' => $originalName,
					'mime_type' => $mimeType,
					'path' => $storedPath,
					'page_count' => $mimeType === 'application/pdf' ? count($pages) : null,
					'chunk_count' => count($chunks),
				]);

				foreach ($chunks as $index => $chunk) {
					$document->chunks()->create([
						'chunk_index' => $index,
						'page_number' => $chunk['page'],
						'content' => $chunk['content'],
						'token_estimate' => $chunk['token_estimate'],
						'embedding' => $embedded['embeddings'][$index],
					]);
				}

				return $document;
			});
		} catch (\Throwable $e) {
			Storage::disk('local')->delete($storedPath);

			throw $e;
		}

		return ['document' => $document, 'tokens' => $embedded['tokens']];
	}
}
