<?php

use App\Http\Middleware\EnsureProEntitlement;
use App\Http\Middleware\EnsureSpikeEnvironment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withSchedule(function (Schedule $schedule): void {
        // 090-h — `cron_schedule` workflow triggers. This entry only fires
        // where the Laravel scheduler runs (`php artisan schedule:work`, or
        // `schedule:run` from cron); the default compose worker runs
        // `queue:work` alone, so a deployment that relies on triggers must
        // run the scheduler too. `nvoos:fire-triggers --dry-run` lists what
        // would fire.
        $schedule->command('nvoos:fire-triggers')
            ->everyMinute()
            ->withoutOverlapping();

        // 090-i — one health sample every five minutes gives the command
        // centre's uptime KPI a denominator. Five-minute granularity over a
        // 24h window is 288 samples: enough to show a degradation, cheap
        // enough to ignore. Like the entry above, this only runs where the
        // scheduler does.
        $schedule->command('nvoos:sample-health')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie mode (Proposal 090-B, ADR-4). Same-origin
        // console requests authenticate with the session cookie; MCP/API
        // clients keep using bearer tokens.
        $middleware->statefulApi();

        // API-only runtime: there is no `login` route, and `Authenticate`
        // resolves `route('login')` before the exception handler runs, which
        // surfaced as a 500 instead of a 401. A null redirect target makes it
        // throw AuthenticationException, which renders as JSON.
        $middleware->redirectGuestsTo(static fn (): ?string => null);

        // The console sends the raw session token in `X-CSRF-TOKEN`, which
        // ValidateCsrfToken checks directly. The Phase 0–4 `X-WP-Nonce`
        // translation shim (AcceptWpNonce) is gone.
        $middleware->alias([
            'spike.local' => EnsureSpikeEnvironment::class,
            'pro.entitlement' => EnsureProEntitlement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This runtime is API-only: there is no `login` route to redirect to.
        // Without this, an unauthenticated request that omits the JSON Accept
        // header gets a 500 (RouteNotFoundException: Route [login] not
        // defined) instead of the 401 the contract promises.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
