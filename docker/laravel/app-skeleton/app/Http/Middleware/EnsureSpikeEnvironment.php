<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep the dev tool surface out of non-development environments.
 */
class EnsureSpikeEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        return $next($request);
    }
}
