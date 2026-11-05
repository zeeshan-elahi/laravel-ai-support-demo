<?php

namespace App\Services\Rag;

/**
 * Splits page text into overlapping chunks of roughly N tokens.
 *
 * Tokens are estimated at 4 characters each, which is close enough for sizing chunks;
 * the embedding API reports the real count. Splits fall on paragraph, then sentence,
 * boundaries so a chunk reads as whole text. A chunk never spans two PDF pages, so each
 * chunk carries one page number for citations.
 */
class Chunker
{
	private const CHARS_PER_TOKEN = 4;

	public function __construct(
		private readonly int $chunkTokens = 800,
		private readonly int $overlapTokens = 100,
	) {}

	/**
	 * @param  array<int, array{page: int|null, text: string}>  $pages
	 * @return array<int, array{page: int|null, content: string, token_estimate: int}>
	 */
	public function chunk(array $pages): array
	{
		$chunks = [];

		foreach ($pages as $page) {
			foreach ($this->splitText($page['text']) as $content) {
				$chunks[] = [
					'page' => $page['page'],
					'content' => $content,
					'token_estimate' => self::estimateTokens($content),
				];
			}
		}

		return $chunks;
	}

	public static function estimateTokens(string $text): int
	{
		return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
	}

	/**
	 * @return array<int, string>
	 */
	private function splitText(string $text): array
	{
		$maxChars = $this->chunkTokens * self::CHARS_PER_TOKEN;
		$overlapChars = $this->overlapTokens * self::CHARS_PER_TOKEN;

		$units = $this->units($text, $maxChars);

		$chunks = [];
		$current = '';

		foreach ($units as $unit) {
			$candidate = $current === '' ? $unit : $current."\n\n".$unit;

			if (mb_strlen($candidate) <= $maxChars) {
				$current = $candidate;

				continue;
			}

			if ($current !== '') {
				$chunks[] = $current;
				$overlap = $this->tail($current, $overlapChars);
				$withOverlap = $overlap === '' ? $unit : $overlap."\n\n".$unit;
				$current = mb_strlen($withOverlap) <= $maxChars ? $withOverlap : $unit;
			} else {
				$current = $unit;
			}
		}

		if (trim($current) !== '') {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Paragraphs, with any paragraph longer than a chunk broken into sentences,
	 * and any sentence still too long hard-split by length.
	 *
	 * @return array<int, string>
	 */
	private function units(string $text, int $maxChars): array
	{
		$units = [];

		foreach (preg_split("/\n\s*\n/", $text) ?: [] as $paragraph) {
			$paragraph = trim($paragraph);

			if ($paragraph === '') {
				continue;
			}

			if (mb_strlen($paragraph) <= $maxChars) {
				$units[] = $paragraph;

				continue;
			}

			foreach (preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [] as $sentence) {
				foreach (mb_str_split($sentence, $maxChars) as $piece) {
					if (trim($piece) !== '') {
						$units[] = trim($piece);
					}
				}
			}
		}

		return $units;
	}

	/**
	 * The last ~N characters of a chunk, starting at a word boundary, to carry into the next one.
	 */
	private function tail(string $text, int $chars): string
	{
		if ($chars <= 0 || mb_strlen($text) <= $chars) {
			return $chars <= 0 ? '' : $text;
		}

		$tail = mb_substr($text, -$chars);
		$space = mb_strpos($tail, ' ');

		return trim($space === false ? $tail : mb_substr($tail, $space));
	}
}
