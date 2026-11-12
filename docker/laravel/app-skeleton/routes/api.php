<?php

use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ToolController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:tools', 'spike.local'])
    ->post('/dev/tools/{slug}', ToolController::class);

Route::middleware(['auth:sanctum', 'throttle:chat'])
    ->post('/chat/stream', ChatStreamController::class);
