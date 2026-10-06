<?php

use App\Http\Controllers\ProductionController;
use Illuminate\Support\Facades\Route;

// Stateless bearer tokens: no cookie authentication or CSRF exception is used.
Route::match(['get', 'post'], '/production', ProductionController::class);
