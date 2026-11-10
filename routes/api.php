<?php

use App\Http\Controllers\AgentAskController;
use Illuminate\Support\Facades\Route;

Route::post('/agent/ask', AgentAskController::class)->middleware('throttle:agent');
