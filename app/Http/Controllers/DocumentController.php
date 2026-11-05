<?php

namespace App\Http\Controllers;

use App\Actions\IngestDocument;
use App\Models\Document;
use App\Services\Rag\Answerer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class DocumentController extends Controller
{
	public function index(): View
	{
		return view('documents.index', [
			'documents' => Document::latest()->get(),
			'result' => null,
		]);
	}

	public function ask(Request $request): View|RedirectResponse
	{
		$request->validate([
			'question' => ['required', 'string', 'max:1000'],
		]);

		try {
			$result = Answerer::fromConfig()->ask((string) $request->input('question'));
		} catch (RuntimeException $e) {
			return back()->withInput()->withErrors(['question' => $e->getMessage()]);
		}

		return view('documents.index', [
			'documents' => Document::latest()->get(),
			'result' => $result,
		]);
	}

	public function store(Request $request): RedirectResponse
	{
		$request->validate([
			'file' => ['required', 'file', 'max:20480', 'mimetypes:application/pdf,text/plain,text/markdown'],
		]);

		$file = $request->file('file');

		try {
			$result = IngestDocument::make()->handle(
				$file->getRealPath(),
				$file->getClientOriginalName(),
				(string) $file->getMimeType(),
			);
		} catch (RuntimeException $e) {
			return back()->withErrors(['file' => $e->getMessage()]);
		}

		$document = $result['document'];

		return redirect()->route('documents.index')->with(
			'status',
			"Ingested {$document->name}: {$document->chunk_count} ".Str::plural("chunk", $document->chunk_count).", {$result['tokens']} embedding tokens."
		);
	}
}
