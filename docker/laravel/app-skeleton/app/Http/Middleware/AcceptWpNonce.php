<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept the console's `X-WP-Nonce` header as a CSRF token.
 *
 * The SPA's 35 request modules send the WordPress nonce header. Rather than
 * editing every client, the Laravel runtime maps a matching `X-WP-Nonce`
 * onto `X-CSRF-TOKEN` (the raw-token header Laravel validates) before the
 * CSRF middleware runs. The mapping only happens when the value matches the
 * session token, so it cannot weaken the check.
 *
 * This is the Phase 0–4 compatibility shim; Phase 5 removes it after the SPA
 * sends `X-XSRF-TOKEN` (Proposal 090-B, ADR-4).
 */
class AcceptWpNonce
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('X-CSRF-TOKEN') && $request->headers->has('X-WP-Nonce')) {
            $token = (string) $request->header('X-WP-Nonce');
            $sessionToken = $request->hasSession() ? $request->session()->token() : null;

            if (is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
                $request->headers->set('X-CSRF-TOKEN', $token);
            }
        }

        return $next($request);
    }
}
