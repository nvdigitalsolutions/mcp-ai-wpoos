<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'NV oOS Laravel Spike',
    'health' => url('/health'),
    'mcp' => url('/mcp'),
]));

Route::get('/health', HealthController::class);
