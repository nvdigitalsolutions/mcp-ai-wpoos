<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantPortabilityController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PaperStoreController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\TranscriptsController;
use App\Http\Controllers\TriggersController;
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

            Route::get('/chat-transcripts', [TranscriptsController::class, 'index']);
            Route::post('/chat-transcripts', [TranscriptsController::class, 'store']);
            Route::get('/chat-transcripts/{sessionKey}', [TranscriptsController::class, 'show']);
            Route::delete('/chat-transcripts/{sessionKey}', [TranscriptsController::class, 'destroy']);

            Route::get('/chat-memory/preferences', [MemoryController::class, 'preferences']);
            Route::post('/chat-memory/preferences', [MemoryController::class, 'updatePreferences']);
            Route::get('/chat-memory/recall', [MemoryController::class, 'recall']);
            Route::post('/chat-memory/store', [MemoryController::class, 'store']);
            Route::get('/chat-memory/audit', [MemoryController::class, 'audit']);
            Route::match(['put', 'patch'], '/chat-memory/{id}', [MemoryController::class, 'update']);
            Route::delete('/chat-memory/{id}', [MemoryController::class, 'destroy']);

            Route::get('/cost/dashboard-summary', [AnalyticsController::class, 'summary']);
            Route::get('/cost/trend', [AnalyticsController::class, 'trend']);
            Route::get('/cost/by-provider', [AnalyticsController::class, 'byProvider']);

            Route::get('/assistants', [AssistantController::class, 'index']);
            Route::post('/assistants', [AssistantController::class, 'store']);
            Route::get('/assistants/{assistant}', [AssistantController::class, 'show']);
            Route::match(['put', 'patch'], '/assistants/{assistant}', [AssistantController::class, 'update']);
            Route::delete('/assistants/{assistant}', [AssistantController::class, 'destroy']);

            Route::get('/tools', ToolsController::class);
            Route::get('/pro/ops/overview', OpsController::class);

            // Phase 3b — HITL approvals.
            Route::get('/approvals', [ApprovalsController::class, 'index']);
            Route::post('/approvals', [ApprovalsController::class, 'store']);
            Route::post('/approvals/{id}/{action}', [ApprovalsController::class, 'resolve'])
                ->whereIn('action', ['approve', 'deny']);

            // Phase 3b — workflow triggers.
            Route::get('/triggers', [TriggersController::class, 'index']);
            Route::post('/triggers', [TriggersController::class, 'store']);
            Route::put('/triggers/{id}', [TriggersController::class, 'update']);
            Route::delete('/triggers/{id}', [TriggersController::class, 'destroy']);

            // Phase 3b — security posture, snapshots, self-test.
            Route::get('/security/posture', [SecurityController::class, 'posture']);
            Route::get('/security/preview-headers', [SecurityController::class, 'previewHeaders']);
            Route::get('/security/snapshots', [SecurityController::class, 'snapshots']);
            Route::post('/security/snapshot', [SecurityController::class, 'saveSnapshot']);
            Route::post('/security/restore', [SecurityController::class, 'restoreSnapshot']);
            Route::post('/security/self-test', [SecurityController::class, 'selfTest']);

            // Phase 3b — Paper Store. `/search` must precede `{collection}`.
            Route::get('/paper-store', [PaperStoreController::class, 'collections']);
            Route::get('/paper-store/search', [PaperStoreController::class, 'search']);
            Route::get('/paper-store/{collection}', [PaperStoreController::class, 'records']);
            Route::post('/paper-store/{collection}', [PaperStoreController::class, 'store']);
            Route::get('/paper-store/{collection}/{id}', [PaperStoreController::class, 'show']);
            Route::put('/paper-store/{collection}/{id}', [PaperStoreController::class, 'update']);
            Route::delete('/paper-store/{collection}/{id}', [PaperStoreController::class, 'destroy']);
        });
    });
});

Route::middleware(['auth:sanctum', 'throttle:tools', 'spike.local'])
    ->post('/dev/tools/{slug}', ToolController::class);

Route::middleware(['auth:sanctum', 'throttle:chat'])
    ->post('/chat/stream', ChatStreamController::class);
