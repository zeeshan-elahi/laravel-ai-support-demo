<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Laravel RAG Demo</title>
	<style>
		:root { --bg: #f7f7f5; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e3e3de; --accent: #b86e13; --error: #b42318; }
		* { box-sizing: border-box; }
		body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
		main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
		h1 { font-size: 1.6rem; margin: 0 0 4px; }
		h2 { font-size: 1.1rem; margin: 32px 0 12px; }
		p.lead { color: var(--muted); margin: 0 0 24px; }
		.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 20px; }
		form { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
		input[type=file] { flex: 1 1 260px; }
		button { background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-weight: 600; cursor: pointer; }
		.status { border-left: 4px solid var(--accent); padding: 10px 14px; background: #fdf6ec; margin-bottom: 16px; border-radius: 6px; }
		.error { border-left: 4px solid var(--error); padding: 10px 14px; background: #fdecea; margin-bottom: 16px; border-radius: 6px; color: var(--error); }
		table { width: 100%; border-collapse: collapse; }
		th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); }
		th { font-size: .85rem; color: var(--muted); font-weight: 600; }
		td.num { text-align: right; font-variant-numeric: tabular-nums; }
		.empty { color: var(--muted); }
		h3 { font-size: .95rem; margin: 20px 0 8px; }
		form.ask { align-items: stretch; }
		textarea { flex: 1 1 320px; font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; resize: vertical; }
		.answer-text p { margin: 0 0 12px; }
		.answer-text ul { margin: 0 0 12px; padding-left: 20px; }
		.sources { padding-left: 24px; margin: 0; }
		.sources li { margin-bottom: 12px; }
		.sources .where { font-weight: 600; font-size: .9rem; }
		blockquote { margin: 4px 0 0; padding: 6px 12px; border-left: 3px solid var(--line); color: var(--muted); font-size: .9rem; }
		.meta { color: var(--muted); font-size: .8rem; margin: 16px 0 0; }
		.warn { color: var(--error); margin: 0; }
	</style>
</head>
<body>
<main>
	<h1>Laravel RAG Demo</h1>
	<p class="lead">Ask a question about the uploaded documents. Every answer cites the passages it came from.</p>

	@if (session('status'))
		<div class="status">{{ session('status') }}</div>
	@endif

	@if ($errors->any())
		<div class="error">{{ $errors->first() }}</div>
	@endif

	<div class="card">
		<form method="post" action="{{ route('documents.ask') }}" class="ask">
			@csrf
			<textarea name="question" rows="2" maxlength="1000" placeholder="e.g. Which payment integrations are listed?" required>{{ old('question', $result['question'] ?? '') }}</textarea>
			<button type="submit">Ask</button>
		</form>
	</div>

	@if ($result)
		<h2>Answer</h2>
		<div class="card answer">
			@if ($result['refused'])
				<p class="warn">The model declined to answer this question.</p>
			@elseif (! $result['supported'])
				<p class="warn">No supported answer: the reply did not cite any passage from the documents, so it is not shown.</p>
			@else
				@php
					$answer = collect($result['segments'])
						->map(fn ($segment) => $segment['text'].($segment['sources'] ? ' ['.implode(', ', $segment['sources']).']' : ''))
						->implode('');
				@endphp
				<div class="answer-text">{!! \Illuminate\Support\Str::markdown($answer, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>

				<h3>Sources</h3>
				<ol class="sources">
					@foreach ($result['sources'] as $source)
						<li value="{{ $source['number'] }}">
							<span class="where">{{ $source['document'] }}{{ $source['page'] ? ', page '.$source['page'] : '' }}</span>
							<blockquote>{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $source['quote']), 320) }}</blockquote>
						</li>
					@endforeach
				</ol>
			@endif

			<p class="meta">
				{{ count($result['retrieved']) }} chunks retrieved
				@if ($result['retrieved'])
					(closest distance {{ number_format($result['retrieved'][0]['distance'], 3) }})
				@endif
				· {{ $result['model'] }} · {{ number_format($result['input_tokens']) }} in / {{ number_format($result['output_tokens']) }} out tokens
			</p>
		</div>
	@endif

	<h2>Add a document</h2>
	<div class="card">
		<form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data">
			@csrf
			<input type="file" name="file" accept=".pdf,.txt,.md,application/pdf,text/plain" required>
			<button type="submit">Upload and ingest</button>
		</form>
	</div>

	<h2>Documents</h2>
	<div class="card">
		@if ($documents->isEmpty())
			<p class="empty">No documents yet.</p>
		@else
			<table>
				<thead>
				<tr>
					<th>Name</th>
					<th class="num">Pages</th>
					<th class="num">Chunks</th>
					<th>Added</th>
				</tr>
				</thead>
				<tbody>
				@foreach ($documents as $document)
					<tr>
						<td>{{ $document->name }}</td>
						<td class="num">{{ $document->page_count ?? '–' }}</td>
						<td class="num">{{ $document->chunk_count }}</td>
						<td>{{ $document->created_at->diffForHumans() }}</td>
					</tr>
				@endforeach
				</tbody>
			</table>
		@endif
	</div>
</main>
</body>
</html>
