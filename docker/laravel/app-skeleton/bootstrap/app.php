<?php

use App\Http\Middleware\AcceptWpNonce;
use App\Http\Middleware\EnsureProEntitlement;
use App\Http\Middleware\EnsureSpikeEnvironment;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // Map the console's X-WP-Nonce onto Laravel's raw CSRF header before
        // validation (Proposal 090-B, ADR-4, Phases 0-4).
        $middleware->web(prepend: [AcceptWpNonce::class]);

        $middleware->alias([
            'spike.local' => EnsureSpikeEnvironment::class,
            'pro.entitlement' => EnsureProEntitlement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
