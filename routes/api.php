<?php

use App\Http\Controllers\AgentAskController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::post('/agent/ask', AgentAskController::class)->middleware('throttle:agent');

Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('throttle:invoices')->name('invoices.store');
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
