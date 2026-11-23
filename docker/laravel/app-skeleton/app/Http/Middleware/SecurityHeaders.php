<?php

namespace App\Http\Middleware;

use App\Support\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Response security headers from the operator's settings — Proposal 149 §187.
 *
 * Four controls were writable and read by nothing here: `enable_security_headers`,
 * `enable_hsts`, `hsts_max_age` and `csp_frame_ancestors`. The field describes the
 * first as "OWASP-recommended security headers: X-Content-Type-Options,
 * X-Frame-Options, CSP, etc." — the exact set this middleware emits.
 *
 * ## Why the app sets headers the proxy may also set
 *
 * `lib/laravel-adapter/config/nvoos.php` records that the console proxy owns the
 * headers and ships its own HSTS ramp. That makes the proxy the *outer* layer, not
 * a reason to leave the app bare: headers are a defence-in-depth control, and an
 * app that emits none is unprotected the moment it is reached directly (a worker
 * port, an internal service mesh, a misrouted proxy). Where both set the same
 * header, the proxy's value wins for proxied traffic; where it sets none, these
 * are the only ones present. The posture report grades the effective policy
 * either way.
 *
 * ## The decisions inside
 *
 * - **`enable_security_headers` is the umbrella** and defaults on. Turning it off
 *   removes every header here, which is the control an operator asked for.
 * - **HSTS needs a positive `max-age`.** `hsts_max_age` defaults to 0, and
 *   `Strict-Transport-Security: max-age=0` *disables* HSTS — so a zero is treated
 *   as "not configured" rather than emitted. It is also only sent over HTTPS, or
 *   when `app.url` declares HTTPS: behind a proxy `isSecure()` is false unless
 *   trusted proxies are configured, and the deployment's own declared scheme is
 *   the honest fallback.
 * - **`csp_frame_ancestors` supersedes `X-Frame-Options`.** The field is a
 *   three-option select — `none`, `self`, or disabled — not free text, so each
 *   maps to one CSP token. `none` blocks all framing including same-origin; that
 *   is what the option says, and `self` is the one that permits same-origin
 *   embedding. Sending XFO alongside is contradictory (CSP wins in modern
 *   browsers, XFO in old ones), so the legacy header is omitted whenever a policy
 *   is configured, and emitted only when the operator disabled CSP framing.
 * - **Header injection is not reachable.** The value is a select, so the stored
 *   row is one of three strings; `frameAncestors()` maps exactly two of them and
 *   ignores everything else. A row written directly to the table therefore cannot
 *   produce a header either.
 *
 * ## Failure behaviour
 *
 * If the settings store cannot be read, no headers are added. These protect the
 * client from the app; refusing responses because the store is down would be a
 * worse trade than one response without `nosniff`.
 */
final class SecurityHeaders
{
    /** HSTS values above this are rejected by browsers; anything longer is noise. */
    private const MAX_HSTS_AGE = 63072000;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $enabled = filter_var($this->settings->value('enable_security_headers'), FILTER_VALIDATE_BOOLEAN);

            if (! $enabled) {
                return $response;
            }

            $ancestors = trim((string) $this->settings->value('csp_frame_ancestors'));
            $hsts = filter_var($this->settings->value('enable_hsts'), FILTER_VALIDATE_BOOLEAN);
            $age = max(0, min(self::MAX_HSTS_AGE, (int) $this->settings->value('hsts_max_age')));
        } catch (Throwable) {
            return $response;
        }

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $policy = '' !== $ancestors ? $this->frameAncestors($ancestors) : '';

        if ('' !== $policy) {
            $headers->set('Content-Security-Policy', 'frame-ancestors '.$policy);
        } else {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        if ($hsts && $age > 0 && $this->isHttps($request)) {
            $headers->set('Strict-Transport-Security', 'max-age='.$age);
        }

        return $response;
    }

    /**
     * Turn the select's option key into a CSP token.
     *
     * The field offers exactly three values, so this is a lookup and not a parser:
     * anything the select did not offer maps to the empty string, and the caller
     * then falls back to `X-Frame-Options`. That also makes a hand-inserted row
     * harmless — it cannot smuggle a header, because nothing but these two tokens
     * can be produced.
     */
    private function frameAncestors(string $configured): string
    {
        return match ($configured) {
            'none' => "'none'",
            'self' => "'self'",
            default => '',
        };
    }

    /**
     * Is this response travelling over HTTPS?
     *
     * `isSecure()` alone is not enough behind a terminating proxy: it reports the
     * scheme the worker saw, not the one the client used. `app.url` is the
     * deployment's own declaration of its public scheme, which is the honest
     * fallback and the same signal `SecurityController` uses.
     */
    private function isHttps(Request $request): bool
    {
        return $request->isSecure() || str_starts_with((string) config('app.url'), 'https://');
    }
}
