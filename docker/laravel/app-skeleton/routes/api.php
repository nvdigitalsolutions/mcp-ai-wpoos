<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantPortabilityController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PaperStoreController;
use App\Http\Controllers\Pro\PresetsController;
use App\Http\Controllers\Pro\RecordsController;
use App\Http\Controllers\Pro\SchedulesController;
use App\Http\Controllers\Pro\ToolkitsController;
use App\Http\Controllers\ProfessionController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TeamCatalogueController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\TranscriptsController;
use App\Http\Controllers\TriggersController;
use App\Http\Controllers\Wp\ContentController as WpContentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console contract (Proposal 090-B, Phases 0-2)
|--------------------------------------------------------------------------
|
| Paths mirror the console's expected `/api/v1` base. Auth-bearing routes use
| Sanctum (bearer or the SPA cookie mode enabled in bootstrap/app.php).
|
| Authorization (090-g Phase 5): site roles are WordPress's, and the admin
| surfaces below carry `can:manage_options` — the same gate the console's nav
| uses. Hiding a button is UX; the middleware is the enforcement.
|
*/
Route::prefix('api/v1')->group(function (): void {
    Route::get('/features', FeaturesController::class);
    Route::get('/site', SiteController::class);

    // The nonce is a plain GET and must always have a session, so it keeps the
    // `web` group. Login/logout deliberately stay on the `api` group:
    // `statefulApi()` already starts the session (and validates CSRF) for
    // console-origin requests, and wrapping them in `web` a second time started
    // two session stores, the outer of which overwrote the authenticated
    // payload.
    Route::middleware('web')->group(function (): void {
        Route::get('/session/nonce', [SessionController::class, 'nonce']);
    });

    Route::post('/session/login', [SessionController::class, 'login'])
        ->middleware('throttle:6,1');
    Route::post('/session/logout', [SessionController::class, 'logout'])
        ->middleware('auth');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/session/me', [SessionController::class, 'me']);

        // ── Available to any authenticated user ──────────────────────────
        // Chat and its satellite surfaces are per-user, not administrative.
        // `wp/*` and `media` mirror WordPress's own capabilities: authors may
        // create content and upload files, subscribers may not.
        Route::middleware('throttle:console')->group(function (): void {
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

            Route::get('/tools', ToolsController::class);

            // The list stays readable: the console shows every role the
            // assistant catalogue. Everything below it is administrative.
            Route::get('/assistants', [AssistantController::class, 'index']);

            // 090-g Phase 3 — org teams and user membership.
            //
            // `/teams` is already the WordPress agent-team surface (a named
            // collection of professions, consumed by the SPA's TeamsPage), so
            // the org primitive — users with roles — mounts under `/org`.
            // Reusing `/teams` would return a different shape at the same URL
            // and silently break the shared console.
            //
            // No `manage_options` here: membership is the authorization, and
            // TeamAccess decides per ability (a site administrator overrides).
            Route::prefix('org')->group(function (): void {
                Route::get('/teams', [TeamController::class, 'index']);
                Route::post('/teams', [TeamController::class, 'store']);
                Route::get('/teams/{team}', [TeamController::class, 'show']);
                Route::match(['put', 'patch'], '/teams/{team}', [TeamController::class, 'update']);
                Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
                Route::post('/teams/{team}/members', [TeamController::class, 'storeMember']);
                Route::match(['put', 'patch'], '/teams/{team}/members/{user}', [TeamController::class, 'updateMember']);
                Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'destroyMember']);
            });

            Route::middleware('can:edit_posts')->group(function (): void {
                // Phase 3c — WordPress-compatible content shim. The Laravel
                // profile points the console's `wpBase` at /api/v1/wp.
                Route::get('/wp/{restBase}', [WpContentController::class, 'index']);
                Route::post('/wp/{restBase}', [WpContentController::class, 'store']);
                Route::get('/wp/{restBase}/{id}', [WpContentController::class, 'show'])
                    ->whereNumber('id');
            });

            Route::middleware('can:upload_files')->group(function (): void {
                // Phase 3d — media library. `wp/v2/media`-shaped list, read and
                // multipart upload (`file` field) for the console's Media
                // Library and the chat attachment picker.
                Route::get('/media', [MediaController::class, 'index']);
                Route::post('/media', [MediaController::class, 'store']);
                Route::get('/media/{id}', [MediaController::class, 'show'])->whereNumber('id');
            });
        });

        // ── Administrative surfaces (manage_options) ─────────────────────
        Route::middleware(['throttle:console', 'can:manage_options'])->group(function (): void {
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

            // Portability mutates the whole assistant catalogue.
            Route::post('/assistants/export', [AssistantPortabilityController::class, 'export']);
            Route::post('/assistants/import', [AssistantPortabilityController::class, 'import']);

            Route::get('/cost/dashboard-summary', [AnalyticsController::class, 'summary']);
            Route::get('/cost/trend', [AnalyticsController::class, 'trend']);
            Route::get('/cost/by-provider', [AnalyticsController::class, 'byProvider']);

            Route::post('/assistants', [AssistantController::class, 'store']);
            Route::get('/assistants/{assistant}', [AssistantController::class, 'show']);
            Route::match(['put', 'patch'], '/assistants/{assistant}', [AssistantController::class, 'update']);
            Route::delete('/assistants/{assistant}', [AssistantController::class, 'destroy']);

            // Phase 4 parity — WordPress's read-only team catalogue.
            Route::get('/teams', [TeamCatalogueController::class, 'index']);
            Route::get('/teams/{team}/members', [TeamCatalogueController::class, 'members']);

            // Phase 4 — profession catalogue (the assistant selector's source).
            Route::get('/professions', [ProfessionController::class, 'index']);
            Route::get('/professions/{slug}', [ProfessionController::class, 'show'])
                ->where('slug', '[a-z0-9_-]+');

            Route::get('/pro/ops/overview', OpsController::class);

            // Phase 3b — HITL approvals.
            Route::get('/approvals', [ApprovalsController::class, 'index']);
            Route::post('/approvals', [ApprovalsController::class, 'store']);
            Route::post('/approvals/{id}/{action}', [ApprovalsController::class, 'resolve'])
                ->whereIn('action', ['approve', 'deny']);

            // Phase 3b — workflow triggers. The console points `triggers` at
            // `/orchestration/triggers`; `/triggers` stays as the client-default
            // alias and the WordPress route parity.
            foreach (['/triggers', '/orchestration/triggers'] as $triggersPath) {
                Route::get($triggersPath, [TriggersController::class, 'index']);
                Route::post($triggersPath, [TriggersController::class, 'store']);
                Route::put($triggersPath.'/{id}', [TriggersController::class, 'update']);
                Route::delete($triggersPath.'/{id}', [TriggersController::class, 'destroy']);
            }

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

            // Phase 3c — Pro Schedule Manager. The console's `scheduleManager`
            // endpoint is `${pro}`, so these live under /api/v1/pro.
            Route::middleware('pro.entitlement:schedules')->prefix('pro')->group(function (): void {
                Route::get('/schedules', [SchedulesController::class, 'index']);
                Route::post('/schedules', [SchedulesController::class, 'store']);
                Route::get('/schedules/fleet-stats', [SchedulesController::class, 'fleetStats']);
                Route::get('/schedules/{id}', [SchedulesController::class, 'show']);
                Route::post('/schedules/{id}', [SchedulesController::class, 'update']);
                Route::delete('/schedules/{id}', [SchedulesController::class, 'destroy']);
                Route::post('/schedules/{id}/toggle', [SchedulesController::class, 'toggle']);
                Route::post('/schedules/{id}/trigger', [SchedulesController::class, 'trigger']);
                Route::get('/schedules/{id}/history', [SchedulesController::class, 'history']);
                Route::get('/schedules/{id}/results', [SchedulesController::class, 'results']);

                Route::get('/presets', [PresetsController::class, 'index']);
                Route::post('/presets/{id}/install', [PresetsController::class, 'install']);
            });

            // Phase 3c — Pro Records schema (content entities only).
            Route::middleware('pro.entitlement:records')->prefix('pro/records')->group(function (): void {
                Route::get('/schema', [RecordsController::class, 'schema']);

                // Not ported: WordPress-to-WordPress proxy and the
                // deep_research create flow. Answer 501, not 404.
                Route::get('/remote/{entity}', [RecordsController::class, 'remoteIndex']);
                Route::get('/remote/{entity}/{id}', [RecordsController::class, 'remoteShow'])->whereNumber('id');
                Route::post('/{entity}/research', [RecordsController::class, 'research']);
            });

            // Phase 4 — manifest-driven Pro toolkits. Entitlement is checked
            // per manifest (`feature`) inside the controller, so the list can
            // filter unentitled families instead of failing wholesale.
            Route::prefix('pro/toolkits')->group(function (): void {
                Route::get('/', [ToolkitsController::class, 'index']);
                Route::get('/{slug}', [ToolkitsController::class, 'show']);
                Route::get('/{slug}/settings', [ToolkitsController::class, 'settings']);
                Route::post('/{slug}/settings', [ToolkitsController::class, 'updateSettings']);
                Route::get('/{slug}/dashboard', [ToolkitsController::class, 'dashboard']);
                Route::get('/{slug}/entities/{entity}', [ToolkitsController::class, 'indexEntity']);
                Route::post('/{slug}/entities/{entity}', [ToolkitsController::class, 'storeEntity']);
                // Registered before `{id}` so the literal segments win the match.
                Route::get('/{slug}/entities/{entity}/duplicates', [ToolkitsController::class, 'duplicates']);
                Route::post('/{slug}/entities/{entity}/import', [ToolkitsController::class, 'importEntity']);
                Route::get('/{slug}/entities/{entity}/{id}', [ToolkitsController::class, 'showEntity']);
                Route::put('/{slug}/entities/{entity}/{id}', [ToolkitsController::class, 'updateEntity']);
                Route::delete('/{slug}/entities/{entity}/{id}', [ToolkitsController::class, 'destroyEntity']);
            });
        });
    });
});

Route::middleware(['auth:sanctum', 'throttle:tools', 'spike.local'])
    ->post('/dev/tools/{slug}', ToolController::class);

Route::middleware(['auth:sanctum', 'throttle:chat'])
    ->post('/chat/stream', ChatStreamController::class);
