<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityEventLogger;
use App\Support\Settings\SettingsService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Failed-authentication lockout per address — Proposal 149 §185.
 *
 * The three `enable_auth_rate_limiting` / `auth_rate_limit_threshold` /
 * `auth_rate_limit_window` controls were writable on `/ops/settings` and read by
 * nothing. They are **not** a request throttle: the field says they track
 * "failed auth attempts per IP and temporarily blocks after exceeding the
 * threshold", which is a lockout, and the existing `throttle:` limiters are a
 * different control that already worked. Wiring these into the throttle numbers
 * would have been the wrong mechanism wearing the right name.
 *
 * ## What counts as a failure
 *
 * A `401` from the API stack. Not `403`: that is authorisation — the caller
 * proved who they are and lacks a permission, and counting it would let a user
 * lock themselves out by opening a page they cannot see. Not `422`: a malformed
 * login is a validation problem the login throttle already bounds.
 *
 * ## Clearing, and the trade-off it accepts
 *
 * Any successful response (`< 400`) clears the counter for that address: a valid
 * credential is evidence the caller is not the brute-forcer. The cost is real and
 * worth stating — behind a shared NAT, one legitimate user's success resets an
 * attacker's count, so the lockout is weaker there. The alternative (never
 * clearing) locks out a whole office because one client kept retrying an expired
 * token, which is worse and not obviously safer.
 *
 * ## Failure behaviour
 *
 * If the settings store or the cache is unavailable, the request proceeds. A
 * lockout that cannot read its threshold must not decide to lock everyone out;
 * the backstop is the `throttle:` limiters, which need no store of ours.
 */
final class GuardFailedAuthentication
{
    /** Cache-key namespace; the window TTL is the value's expiry, not the key's. */
    private const PREFIX = 'auth-failures:';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SecurityEventLogger $events,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $enabled = filter_var(
                $this->settings->value('enable_auth_rate_limiting'),
                FILTER_VALIDATE_BOOLEAN
            );

            if (! $enabled) {
                return $next($request);
            }

            // Clamped to the field's own bounds. A zero or negative value must
            // not become "block everything", which is what an unclamped
            // comparison against 0 would do.
            $threshold = max(3, min(100, (int) $this->settings->value('auth_rate_limit_threshold')));
            $window = max(60, min(3600, (int) $this->settings->value('auth_rate_limit_window')));
        } catch (Throwable) {
            return $next($request);
        }

        $key = self::PREFIX.$request->ip();

        try {
            $failures = (int) Cache::get($key, 0);
        } catch (Throwable) {
            return $next($request);
        }

        if ($failures >= $threshold) {
            $this->events->record(
                SecurityEventLogger::AUTH_LOCKOUT,
                $request,
                null,
                'Blocked after repeated authentication failures',
                array('threshold' => $threshold)
            );

            return response()->json(array(
                'code' => 'auth_rate_limited',
                'message' => 'Too many failed authentication attempts from this address. Try again later.',
            ), 429)->header('Retry-After', (string) $window);
        }

        try {
            $response = $next($request);
        } catch (AuthenticationException $error) {
            $this->settle($request, $key, $failures, $window, 401);

            throw $error;
        } catch (HttpExceptionInterface $error) {
            $this->settle($request, $key, $failures, $window, $error->getStatusCode());

            throw $error;
        }

        $this->settle($request, $key, $failures, $window, $response->getStatusCode());

        return $response;
    }

    /**
     * Count a failure, or clear on success.
     *
     * Authentication failures are usually **thrown** (`AuthenticationException`)
     * and rendered by the exception handler, which runs outside the middleware
     * pipeline — so a `$response` is never produced for them. Catching here and
     * re-throwing is the only way for this middleware to see the status without
     * taking over the rendering.
     */
    private function settle(Request $request, string $key, int $failures, int $window, int $status): void
    {
        try {
            if (401 === $status) {
                Cache::put($key, $failures + 1, $window);

                $this->events->record(
                    SecurityEventLogger::AUTH_FAILURE,
                    $request,
                    null,
                    'Authentication failed',
                    array('route' => $request->path())
                );
            } elseif ($status < 400) {
                Cache::forget($key);
            }
        } catch (Throwable) {
            // Counting is best-effort; the response is already decided.
        }
    }
}
