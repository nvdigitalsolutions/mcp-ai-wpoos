<?php

use App\Http\Middleware\ApplyCorsPolicy;
use App\Http\Middleware\EnsureAssistantRestEnabled;
use App\Http\Middleware\EnforceRequestLimits;
use App\Http\Middleware\GuardFailedAuthentication;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\EnsurePrincipalScope;
use App\Http\Middleware\EnsureProEntitlement;
use App\Http\Middleware\EnsureSpikeEnvironment;
use App\Support\Auth\Auth0TokenException;
use App\Support\Security\RootKeyInvalid;
use App\Support\Security\RootKeyRequired;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Auth\Middleware\Authenticate;
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
        // 149 §186 — retention for the security-event stream. Day-granular rule,
        // so daily is enough; the sweep is one indexed delete and `0` keeps the
        // rows forever. Only runs where the scheduler does.
        $schedule->command('nvoos:prune-security-events')
            ->daily()
            ->withoutOverlapping();

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

        // 090-b — the Schedule Manager tick. Installed presets and hand-made
        // schedules only fire where this runs: the `scheduler` container role
        // runs `php artisan schedule:work`, and `nvoos:run-schedules --dry-run`
        // lists what would fire.
        $schedule->command('nvoos:run-schedules')
            ->everyMinute()
            ->withoutOverlapping();

        // 159 slice 3 — machine credentials expire and nothing rotates them for
        // you: a replacement token is shown once, so a client cannot fetch it.
        // This reports what is about to lapse, and exits 1 when there is
        // something to rotate, which is the honest half of a rotation cadence.
        $schedule->command('nvoos:machine-token:expiring')
            ->dailyAt('07:00')
            ->withoutOverlapping();

        // 090-j — the media compression sweep. The expression is configuration
        // rather than code: how often a library should be re-optimised is an
        // operator decision, and an empty value schedules nothing (the console
        // action still works). The command writes a `media.sweep` row, which
        // is what the command centre's schedule summary reads.
        $sweepSchedule = (string) config('oos.media_worker.sweep_schedule', '');

        if ('' !== $sweepSchedule && CronExpression::isValidExpression($sweepSchedule)) {
            $schedule->command('nvoos:sweep-media')
                ->cron($sweepSchedule)
                ->withoutOverlapping();
        }

        // 149 §73 — routing metrics retention. One row per deviation, so the
        // table grows slowly; a slow leak is still a leak. The window is
        // configuration (0 disables), and the daily slot is off-peak because
        // the delete is the only write this table ever sees from a schedule.
        $routingRetentionDays = (int) config('oos.routing.retention_days', 90);

        if ($routingRetentionDays > 0) {
            $schedule->command('nvoos:prune-routing-metrics', ['--days' => $routingRetentionDays])
                ->dailyAt('03:20')
                ->withoutOverlapping();
        }

        // 149 §80 — roll the token usage up before retention (§76) prunes it.
        // Seven days of overlap heals a day a failed run skipped; the rollup is
        // idempotent, so overlap is cheap. `rollup_days = 0` removes the entry,
        // and retention then refuses to prune anything (it will not delete a
        // day that was never aggregated).
        $tokenRollupDays = (int) config('oos.token_usage.rollup_days', 7);

        if ($tokenRollupDays > 0) {
            $schedule->command('nvoos:rollup-token-usage', ['--days' => $tokenRollupDays])
                ->dailyAt('03:10')
                ->withoutOverlapping();
        }

        // 149 §176 — per-tool quota usage. One row per estate, user, tool and
        // day: bounded by callers rather than by calls, so it needs a delete by
        // date and nothing more. Deliberately after the token rollup and prune
        // slots, because it has no aggregation step to wait for. 0 disables it.
        $toolQuotaRetentionDays = (int) config('oos.tool_quotas.retention_days', 30);

        if ($toolQuotaRetentionDays > 0) {
            $schedule->command('nvoos:prune-tool-quota-usage', ['--days' => $toolQuotaRetentionDays])
                ->dailyAt('03:30')
                ->withoutOverlapping();
        }

        // 149 §111 — the access-request digest. §110 made the refusal visible on
        // a page; a page is pull, and the administrator who needs it has no reason
        // to visit it. Daily at a fixed hour, and silent when nothing is pending —
        // an empty digest is what teaches a recipient to filter the channel.
        // Disabled outright when `oos.access_digest.enabled` is false (a runtime
        // with no mail transport), and per-estate via `enable_access_digest`.
        $digestSchedule = (string) config('oos.access_digest.schedule', '');

        if (
            (bool) config('oos.access_digest.enabled', true)
            && '' !== $digestSchedule
            && CronExpression::isValidExpression($digestSchedule)
        ) {
            $schedule->command('nvoos:access-digest')
                ->cron($digestSchedule)
                ->withoutOverlapping();
        }

        // 149 §76 — token usage retention. One row per AI call, and the rows are
        // the cost history, so the window is a year by default and the delete
        // is chunked. Ten minutes after routing retention keeps the two from
        // contending for the same database at 03:20.
        $tokenRetentionDays = (int) config('oos.token_usage.retention_days', 365);

        if ($tokenRetentionDays > 0) {
            $schedule->command('nvoos:prune-token-usage', ['--days' => $tokenRetentionDays])
                ->dailyAt('03:30')
                ->withoutOverlapping();
        }

        // 149 §74 — the OTLP push. Configured-but-unscheduled is a valid state
        // (manual runs only), so the endpoint alone does not register anything;
        // the expression is the operator's decision, like the media sweep.
        $routingExportEndpoint = (string) config('oos.routing.export.endpoint', '');
        $routingExportSchedule = (string) config('oos.routing.export.schedule', '');

        if (
            '' !== $routingExportEndpoint
            && '' !== $routingExportSchedule
            && CronExpression::isValidExpression($routingExportSchedule)
        ) {
            $schedule->command('nvoos:push-routing-metrics')
                ->cron($routingExportSchedule)
                ->withoutOverlapping();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // 149 §184 — CORS from the operator's settings, not only from
        // `NVOOS_CORS_ORIGINS`. This must run before the framework's `HandleCors`,
        // which reads `config('cors')` while handling the request; `prepend` puts
        // it first in the global stack for exactly that reason. See the class
        // docblock for the precedence rule and the wildcard/credentials trap.
        $middleware->prepend(ApplyCorsPolicy::class);

        // Sanctum SPA cookie mode (Proposal 090-B, ADR-4). Same-origin
        // console requests authenticate with the session cookie; MCP/API
        // clients keep using bearer tokens.
        $middleware->statefulApi();

        // 149 §187 — OWASP-recommended response headers from the operator's
        // settings. Appended to the global stack: it only touches the response, so
        // its request-phase position does not matter, and appending keeps the
        // CORS-before-HandleCors ordering above untouched.
        $middleware->append(SecurityHeaders::class);

        // 149 §185 — failed-authentication lockout per address. Registered
        // *before* the limit below so the resulting order is
        // [limits, lockout, …]: a body is measured before an unauthenticated
        // attempt is counted, and the count is checked before the authenticator
        // runs (route middleware), so a locked-out address never reaches it.
        $middleware->prependToGroup('api', GuardFailedAuthentication::class);

        // 149 §184 — request-size and JSON-depth limits. Registered *after*
        // `statefulApi()` on purpose: that call prepends the Sanctum stateful
        // middleware (which brings CSRF validation), so registering first would
        // leave CSRF ahead of the limit and answer an oversized body with 419
        // without ever measuring it. First in the group means a 1 MB body is
        // refused before CSRF, authentication, tenancy and the controller each
        // do work on it.
        $middleware->prependToGroup('api', EnforceRequestLimits::class);

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
            // Proposal 159 §10 — the scope vocabulary. Attached once, to the
            // `api` group below, rather than per route: see the class docblock
            // for why a group instance reads the route's declarations instead of
            // a second attach overriding the first.
            'scope' => EnsurePrincipalScope::class,
            // 159 slice 2 — the three `rest_enable_assistant_*` keys, honoured
            // for scoped machine principals only.
            'assistant.rest' => EnsureAssistantRestEnabled::class,
        ]);

        // 159 §10 — default-deny for scoped credentials, attached once for the
        // whole API surface so that an unannotated route is a visible refusal
        // rather than a silent permission.
        $middleware->appendToGroup('api', EnsurePrincipalScope::class);

        // The ordering is part of the decision, not an implementation detail:
        // `PrincipalScope::forRequest()` reads `null` as unrestricted, so running
        // before `auth:sanctum` would read no principal, allow everything and
        // enforce nothing while looking installed. A test proves this order.
        $middleware->appendToPriorityList(
            Authenticate::class,
            EnsurePrincipalScope::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This runtime is API-only: there is no `login` route to redirect to.
        // Without this, an unauthenticated request that omits the JSON Accept
        // header gets a 500 (RouteNotFoundException: Route [login] not
        // defined) instead of the 401 the contract promises.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // 149 §102 — a rejected Auth0 token answers with its reason.
        //
        // "Unauthenticated." collapses four different situations into one string:
        // expired means fetch a new token, audience means ask Auth0 for the right
        // API, unmapped means the estate must link the subject, and unreachable
        // JWKS is our own outage. The status differs too — a genuine token for
        // the wrong audience is a refusal (403), not an authentication failure.
        //
        // Not reported: a rejected token is expected control flow, and logging
        // every expired token at error level turns a normal client retry into
        // an alert. The JWKS outage is the exception — that one is reported
        // where it happens, inside the verifier.
        $exceptions->dontReport(array(Auth0TokenException::class));

        // 149 §188 — the root-key gate answers 423, not 403: the caller is
        // authenticated and authorised, and the request is refused only until a
        // second credential is presented. A rejected key at the point of setting it
        // is a 422. Both are JSON for anything expecting it, and a redirect back
        // with an error otherwise, so the Filament form shows the reason.
        $exceptions->render(
            static function (RootKeyRequired $exception, Request $request): mixed {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(array(
                        'code' => RootKeyRequired::CODE,
                        'message' => $exception->getMessage(),
                    ), 423);
                }

                return back()->withErrors(array('root_key' => $exception->getMessage()));
            }
        );

        $exceptions->render(
            static function (RootKeyInvalid $exception, Request $request): mixed {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(array(
                        'code' => RootKeyInvalid::CODE,
                        'message' => $exception->getMessage(),
                    ), 422);
                }

                return back()->withErrors(array('root_key' => $exception->getMessage()));
            }
        );

        $exceptions->render(
            static fn (Auth0TokenException $exception): mixed => response()->json(
                array(
                    'error' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ),
                $exception->status
            )
        );
    })->create();
