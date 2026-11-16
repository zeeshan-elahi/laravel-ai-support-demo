<?php

namespace App\Console\Commands;

use App\Invoices\Eval\InvoiceEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class InvoiceEvalCommand extends Command
{
	protected $signature = 'invoice:eval
		{--model= : Model to evaluate instead of the configured default}
		{--only= : Comma-separated sample ids to run (e.g. inv-04-dune-lantern-multi-page)}';

	protected $description = 'Extract every sample invoice and score status, failed checks and each field against the ground truth';

	public function handle(InvoiceEvaluator $evaluator): int
	{
		$model = $this->option('model') ?: (string) config('invoices.model');
		$files = glob(resource_path('invoice-eval/expected/*.json')) ?: [];
		sort($files);

		if ($only = $this->option('only')) {
			$ids = array_map('trim', explode(',', $only));
			$files = array_values(array_filter($files, fn (string $f) => in_array(basename($f, '.json'), $ids, true)));
		}

		if ($files === []) {
			$this->error('No ground-truth files matched.');

			return self::FAILURE;
		}

		$this->info("Evaluating {$model} on " . count($files) . ' documents');
		$results = [];

		foreach ($files as $file) {
			$result = $evaluator->evaluate($file, $model);
			$results[] = $result;
			$x = $result['extraction'];

			$this->line(sprintf(
				'  %s %-44s %-12s fields %s · %s',
				$result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>',
				$result['id'],
				$result['invoice']->status->value,
				$result['fields_total'] ? "{$result['fields_correct']}/{$result['fields_total']}" : '—',
				$x ? sprintf('%d ms · $%s', $x->latency_ms, $x->cost_usd) : 'no call logged',
			));

			foreach ($result['failures'] as $failure) {
				$this->line("       - {$failure}");
			}
		}

		$summary = $this->summarise($model, $results);
		$this->newLine();
		$this->table(['Model', 'Documents passed', 'Status right', 'Fields right', 'Cost (total)', 'Cost per document', 'Avg latency'], [[
			$summary['model'],
			"{$summary['passed']}/{$summary['documents']}",
			"{$summary['status_ok']}/{$summary['documents']}",
			"{$summary['fields_correct']}/{$summary['fields_total']} (" . round($summary['field_accuracy'] * 100, 1) . '%)',
			'$' . number_format($summary['cost_usd'], 6),
			'$' . number_format($summary['cost_per_document_usd'], 6),
			$summary['avg_latency_ms'] . ' ms',
		]]);

		$path = 'invoice-eval/' . now()->format('Y-m-d-His') . '-' . $model . '.json';
		Storage::put($path, json_encode([
			'summary' => $summary,
			'results' => array_map(fn (array $r) => [
				'id' => $r['id'],
				'passed' => $r['passed'],
				'status' => $r['invoice']->status->value,
				'failed_checks' => $r['invoice']->failed_checks,
				'fields' => "{$r['fields_correct']}/{$r['fields_total']}",
				'failures' => $r['failures'],
				'invoice_id' => $r['invoice']->id,
				'extraction_id' => $r['extraction']?->id,
			], $results),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$this->line("Report: storage/app/private/{$path}");

		return $summary['passed'] === $summary['documents'] ? self::SUCCESS : self::FAILURE;
	}

	/**
	 * @param  list<array<string, mixed>>  $results
	 * @return array<string, mixed>
	 */
	private function summarise(string $model, array $results): array
	{
		$extractions = collect($results)->pluck('extraction')->filter();
		$cost = (float) $extractions->sum(fn ($x) => (float) $x->cost_usd);
		$fieldsTotal = array_sum(array_column($results, 'fields_total'));
		$fieldsCorrect = array_sum(array_column($results, 'fields_correct'));
		$documents = count($results);

		return [
			'model' => $model,
			'documents' => $documents,
			'passed' => count(array_filter($results, fn (array $r) => $r['passed'])),
			'status_ok' => count(array_filter($results, fn (array $r) => $r['status_ok'])),
			'fields_correct' => $fieldsCorrect,
			'fields_total' => $fieldsTotal,
			'field_accuracy' => $fieldsTotal ? round($fieldsCorrect / $fieldsTotal, 4) : 0,
			'cost_usd' => round($cost, 6),
			'cost_per_document_usd' => $documents ? round($cost / $documents, 6) : 0,
			'avg_latency_ms' => (int) round($extractions->avg('latency_ms') ?? 0),
			'input_tokens' => $extractions->sum('input_tokens'),
			'output_tokens' => $extractions->sum('output_tokens'),
		];
	}
}
