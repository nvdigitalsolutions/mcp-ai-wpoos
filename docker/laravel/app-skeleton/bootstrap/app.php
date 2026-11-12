<?php

use App\Http\Middleware\AcceptWpNonce;
use App\Http\Middleware\EnsureProEntitlement;
use App\Http\Middleware\EnsureSpikeEnvironment;
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

        // Map the console's X-WP-Nonce onto Laravel's raw CSRF header before
        // validation (Proposal 090-B, ADR-4, Phases 0-4). Registered on the
        // api group too: the console's session and CRUD routes live there, and
        // the stateful pipeline validates CSRF before the controller runs.
        $middleware->web(prepend: [AcceptWpNonce::class]);
        $middleware->api(prepend: [AcceptWpNonce::class]);

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
