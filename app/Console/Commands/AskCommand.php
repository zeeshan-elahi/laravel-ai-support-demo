<?php

namespace App\Console\Commands;

use App\Services\Rag\Answerer;
use Illuminate\Console\Command;

class AskCommand extends Command
{
	protected $signature = 'rag:ask {question : The question to answer from the stored documents}';

	protected $description = 'Answer a question from the stored documents, with cited sources';

	public function handle(): int
	{
		$result = Answerer::fromConfig()->ask((string) $this->argument('question'));

		if ($result['refused']) {
			$this->warn('The model declined to answer this question.');
		} elseif (! $result['supported']) {
			$this->warn('No supported answer: the reply cited no passage from the documents.');
			$this->line(implode('', array_column($result['segments'], 'text')));
		} else {
			$answer = '';
			foreach ($result['segments'] as $segment) {
				$answer .= $segment['text'].($segment['sources'] ? '['.implode(',', $segment['sources']).']' : '');
			}

			$this->line($answer);
			$this->newLine();

			foreach ($result['sources'] as $source) {
				$where = $source['document'].($source['page'] ? ", page {$source['page']}" : '');
				$this->line("[{$source['number']}] {$where}: \"{$source['quote']}\"");
			}
		}

		$this->newLine();
		$this->line(sprintf(
			'Retrieved %d chunks (closest distance %.4f) · %s · %d in / %d out tokens',
			count($result['retrieved']),
			$result['retrieved'][0]['distance'] ?? 0,
			$result['model'],
			$result['input_tokens'],
			$result['output_tokens'],
		));

		return self::SUCCESS;
	}
}
