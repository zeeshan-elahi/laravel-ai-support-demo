<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Services\Rag\Chunker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the help-center articles in database/seeders/help-center into the 'support'
 * collection. They are searched by full text, so no embeddings are needed. Five of the
 * articles carry planted prompt injections for the agent's defence tests.
 */
class HelpCenterSeeder extends Seeder
{
	public function run(): void
	{
		$chunker = new Chunker(400, 40);

		foreach (glob(database_path('seeders/help-center/*.md')) as $path) {
			$name = basename($path);
			$text = trim((string) file_get_contents($path));
			$chunks = $chunker->chunk([['page' => null, 'text' => $text]]);

			DB::transaction(function () use ($name, $path, $chunks) {
				Document::where('collection', 'support')->where('name', $name)->delete();

				$document = Document::create([
					'name' => $name,
					'collection' => 'support',
					'mime_type' => 'text/markdown',
					'path' => 'database/seeders/help-center/'.basename($path),
					'chunk_count' => count($chunks),
				]);

				foreach ($chunks as $index => $chunk) {
					$document->chunks()->create([
						'chunk_index' => $index,
						'content' => $chunk['content'],
						'token_estimate' => $chunk['token_estimate'],
					]);
				}
			});
		}
	}
}
