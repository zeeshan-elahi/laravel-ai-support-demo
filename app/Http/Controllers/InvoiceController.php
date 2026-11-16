<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Invoices\InvoiceIntake;
use App\Jobs\ExtractInvoice;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
	/**
	 * Store the PDF and queue the extraction. Answers 202 Accepted with the invoice
	 * as it stands (pending until a queue worker has run the job) and a Location
	 * header to poll.
	 */
	public function store(UploadInvoiceRequest $request, InvoiceIntake $intake): JsonResponse
	{
		$invoice = $intake->fromUpload($request->file('file'));
		ExtractInvoice::dispatch($invoice->id, $request->validated('model'));

		// With the sync queue (tests, local runs) the job has already finished.
		$invoice->refresh()->load(['lines', 'extractions']);

		return (new InvoiceResource($invoice))
			->response()
			->setStatusCode(202)
			->header('Location', route('invoices.show', $invoice->id));
	}

	public function show(Invoice $invoice): InvoiceResource
	{
		return new InvoiceResource($invoice->load(['lines', 'extractions']));
	}
}
