<?php

use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\ToolsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| T0 console contract (Proposal 090-B, Phase 0/1)
|--------------------------------------------------------------------------
|
| Paths mirror the console's expected `/api/v1` base. Auth-bearing routes use
| Sanctum (bearer or the SPA cookie mode enabled in bootstrap/app.php).
|
*/
Route::prefix('api/v1')->group(function (): void {
    Route::get('/features', FeaturesController::class);
    Route::get('/site', SiteController::class);

    Route::middleware('web')->get('/session/nonce', [SessionController::class, 'nonce']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/session/me', [SessionController::class, 'me']);

        Route::middleware('throttle:tools')->group(function (): void {
            Route::get('/tools', ToolsController::class);
            Route::get('/pro/ops/overview', OpsController::class);
        });
    });
});

Route::middleware(['auth:sanctum', 'throttle:tools', 'spike.local'])
    ->post('/dev/tools/{slug}', ToolController::class);

Route::middleware(['auth:sanctum', 'throttle:chat'])
    ->post('/chat/stream', ChatStreamController::class);
