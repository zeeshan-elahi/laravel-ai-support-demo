<?php

namespace App\Services\Rag;

use InvalidArgumentException;
use Smalot\PdfParser\Parser;

/**
 * Turns an uploaded file into a list of pages: [['page' => int|null, 'text' => string], ...].
 * PDFs keep their page numbers so answers can cite them; text files are one page with no number.
 */
class TextExtractor
{
	public const SUPPORTED_MIME_TYPES = [
		'application/pdf',
		'text/plain',
		'text/markdown',
	];

	/**
	 * @return array<int, array{page: int|null, text: string}>
	 */
	public function extract(string $path, string $mimeType): array
	{
		$pages = match (true) {
			$mimeType === 'application/pdf' => $this->fromPdf($path),
			str_starts_with($mimeType, 'text/') => [['page' => null, 'text' => (string) file_get_contents($path)]],
			default => throw new InvalidArgumentException("Unsupported file type: {$mimeType}"),
		};

		return array_values(array_filter(
			array_map(fn (array $page) => ['page' => $page['page'], 'text' => $this->clean($page['text'])], $pages),
			fn (array $page) => $page['text'] !== ''
		));
	}

	/**
	 * @return array<int, array{page: int, text: string}>
	 */
	private function fromPdf(string $path): array
	{
		$pdf = (new Parser)->parseFile($path);

		$pages = [];
		foreach ($pdf->getPages() as $index => $page) {
			$pages[] = ['page' => $index + 1, 'text' => $page->getText()];
		}

		return $pages;
	}

	private function clean(string $text): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}
}
