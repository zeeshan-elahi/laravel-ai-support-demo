<?php

namespace Tests\Feature\Agent;

use App\Agent\ToolContext;
use App\Agent\Tools\SearchHelpCenterTool;
use App\Models\Chunk;
use App\Models\Document;
use Database\Seeders\HelpCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
	use RefreshDatabase;

	private function search(string $query): array
	{
		return (new SearchHelpCenterTool())->run(['query' => $query], new ToolContext('A policy question.'))->payload;
	}

	public function test_seeder_loads_the_eight_articles_once(): void
	{
		$this->seed(HelpCenterSeeder::class);
		$this->seed(HelpCenterSeeder::class);

		$this->assertSame(8, Document::where('collection', 'support')->count());
		$this->assertSame(0, Chunk::inCollection('support')->whereNotNull('embedding')->count());
	}

	public function test_finds_the_returns_policy(): void
	{
		$this->seed(HelpCenterSeeder::class);

		$results = $this->search('return damaged item')['results'];

		$this->assertSame('returns-and-refunds.md', $results[0]['article']);
		$this->assertStringContainsString('within 14 days of delivery', $results[0]['excerpt']);
	}

	public function test_never_returns_documents_from_the_general_collection(): void
	{
		$this->seed(HelpCenterSeeder::class);
		Document::create(['name' => 'spec.pdf', 'collection' => 'general', 'mime_type' => 'application/pdf', 'path' => 'x'])
			->chunks()->create(['chunk_index' => 0, 'content' => 'Return damaged item refund shipping warranty.', 'token_estimate' => 8]);

		$articles = array_column($this->search('return damaged item refund shipping warranty')['results'], 'article');

		$this->assertNotContains('spec.pdf', $articles);
	}

	public function test_the_cited_qa_scope_excludes_help_center_articles(): void
	{
		$this->seed(HelpCenterSeeder::class);

		$this->assertSame(0, Chunk::inCollection('general')->count());
		$this->assertGreaterThan(0, Chunk::inCollection('support')->count());
	}

	public function test_rejects_a_query_that_is_too_short(): void
	{
		$this->assertArrayHasKey('query', $this->search('x')['details']);
	}
}
