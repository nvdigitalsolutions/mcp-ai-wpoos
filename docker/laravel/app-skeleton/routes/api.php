<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantPortabilityController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\ToolsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console contract (Proposal 090-B, Phases 0-2)
|--------------------------------------------------------------------------
|
| Paths mirror the console's expected `/api/v1` base. Auth-bearing routes use
| Sanctum (bearer or the SPA cookie mode enabled in bootstrap/app.php).
|
*/
Route::prefix('api/v1')->group(function (): void {
    Route::get('/features', FeaturesController::class);
    Route::get('/site', SiteController::class);

    Route::middleware('web')->group(function (): void {
        Route::get('/session/nonce', [SessionController::class, 'nonce']);
        Route::post('/session/login', [SessionController::class, 'login'])
            ->middleware('throttle:6,1');
        Route::post('/session/logout', [SessionController::class, 'logout'])
            ->middleware('auth');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/session/me', [SessionController::class, 'me']);

        Route::middleware('throttle:tools')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::get('/settings/schema', [SettingsController::class, 'schema']);
            Route::get('/settings/values', [SettingsController::class, 'values']);
            Route::post('/settings/values', [SettingsController::class, 'updateValues']);

            Route::get('/diagnostics/overview', DiagnosticsController::class);
            Route::get('/operations/cron', [OperationsController::class, 'cron']);
            Route::delete('/operations/cron/{jobId}', [OperationsController::class, 'removeCron']);
            Route::get('/operations/dlq', [OperationsController::class, 'dlq']);
            Route::post('/operations/dlq/{itemId}/retry', [OperationsController::class, 'retry']);
            Route::post('/operations/dlq/{itemId}/dismiss', [OperationsController::class, 'dismiss']);
            Route::get('/operations/timeline', [OperationsController::class, 'timeline']);

            Route::post('/assistants/export', [AssistantPortabilityController::class, 'export']);
            Route::post('/assistants/import', [AssistantPortabilityController::class, 'import']);

            Route::get('/assistants', [AssistantController::class, 'index']);
            Route::post('/assistants', [AssistantController::class, 'store']);
            Route::get('/assistants/{assistant}', [AssistantController::class, 'show']);
            Route::match(['put', 'patch'], '/assistants/{assistant}', [AssistantController::class, 'update']);
            Route::delete('/assistants/{assistant}', [AssistantController::class, 'destroy']);

            Route::get('/tools', ToolsController::class);
            Route::get('/pro/ops/overview', OpsController::class);
        });
    });
});

Route::middleware(['auth:sanctum', 'throttle:tools', 'spike.local'])
    ->post('/dev/tools/{slug}', ToolController::class);

Route::middleware(['auth:sanctum', 'throttle:chat'])
    ->post('/chat/stream', ChatStreamController::class);
