<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept the console's `X-WP-Nonce` header as a CSRF token.
 *
 * The SPA's 35 request modules send the WordPress nonce header. Rather than
 * editing every client, the Laravel runtime maps `X-WP-Nonce` onto
 * `X-CSRF-TOKEN` (the raw-token header Laravel validates).
 *
 * The mapping is unconditional: this middleware runs before Sanctum starts the
 * session, so it cannot compare against the session token — and it does not
 * need to. `ValidateCsrfToken` still compares the header to the session token
 * and rejects a mismatch, so the shim cannot weaken the check.
 *
 * This is the Phase 0–4 compatibility shim; Phase 5 removes it after the SPA
 * sends `X-XSRF-TOKEN` (Proposal 090-B, ADR-4).
 */
class AcceptWpNonce
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('X-CSRF-TOKEN') && $request->headers->has('X-WP-Nonce')) {
            $request->headers->set('X-CSRF-TOKEN', (string) $request->header('X-WP-Nonce'));
        }

        return $next($request);
    }
}
