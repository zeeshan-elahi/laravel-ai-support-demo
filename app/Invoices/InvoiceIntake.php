<?php

namespace App\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an original PDF and creates its invoice row (status pending), so the
 * queued extraction has something to update. Files are named by content hash;
 * the same file uploaded twice is stored once but gets its own invoice row.
 */
class InvoiceIntake
{
	public function fromPath(string $path, ?string $originalName = null): Invoice
	{
		return $this->store((string) file_get_contents($path), $originalName ?? basename($path));
	}

	public function fromUpload(UploadedFile $file): Invoice
	{
		return $this->store($file->getContent(), $file->getClientOriginalName());
	}

	private function store(string $contents, string $originalName): Invoice
	{
		$sha256 = hash('sha256', $contents);
		$path = config('invoices.directory') . '/' . $sha256 . '.pdf';
		$disk = Storage::disk(config('invoices.disk'));

		if (! $disk->exists($path)) {
			$disk->put($path, $contents);
		}

		return Invoice::create([
			'source_path' => $path,
			'original_name' => $originalName,
			'source_sha256' => $sha256,
			'status' => InvoiceStatus::Pending,
			'failed_checks' => [],
		]);
	}
}
