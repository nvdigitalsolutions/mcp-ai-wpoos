<?php

namespace App\Http\Middleware;

use App\Support\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Request-size and JSON-complexity limits from the operator's settings —
 * Proposal 149 §184.
 *
 * Two controls on `/ops/settings` were writable and read by nothing:
 *
 * - `max_request_body_size_kb` — "rejected with HTTP 413", 0 means no limit;
 * - `max_json_depth` — "prevents stack exhaustion from deeply nested payloads".
 *
 * Both are denial-of-service controls. OWASP ASVS 5.0 states the reason plainly
 * in V2.2: a number that "controls how many times a loop will take place… could
 * lead to excessive processing and a potential denial of service condition", and
 * V2.4.1 asks for controls against excessive calls. Depth is the same argument
 * one level down — `json_decode` recurses.
 *
 * ## JSON only, deliberately
 *
 * The size limit applies to JSON bodies, not to multipart uploads. The field
 * describes "chat and tool endpoints", and those are JSON; media uploads are
 * multipart and are already bounded by PHP's `upload_max_filesize` and
 * `post_max_size`. Applying one kilobyte-derived cap to uploads would break
 * images to protect a JSON parser, which is the wrong trade.
 *
 * ## Size comes from `Content-Length` first, then from the body itself
 *
 * `Content-Length` gives a cheap rejection before the body is touched. It is not
 * the authority, because a chunked body declares no length and a lying client
 * declares the wrong one. The authoritative check runs on the bytes actually
 * read — which cost nothing extra, because the depth check has already
 * materialised the body. A request is refused if *either* measurement exceeds
 * the limit.
 *
 * PHP's own request limits remain the backstop for bodies this middleware never
 * sees (non-JSON, or refused earlier in the stack).
 *
 * ## The catalogue default is the effective default
 *
 * `SettingsService::value()` falls back to the field default, so an unconfigured
 * deployment enforces 1 MB and depth 32 — WordPress's own defaults, and the
 * parity this port is chasing. `0` disables the size limit, which is the field's
 * documented contract.
 *
 * ## Failure behaviour
 *
 * If the settings store cannot be read, the request proceeds unchecked. These
 * limits protect availability; refusing every request because the database that
 * holds the limit is unavailable would be the outage they exist to prevent.
 */
final class EnforceRequestLimits
{
    /** The field's own ceiling for depth; anything above it is not a policy. */
    private const MAX_DEPTH_CEILING = 512;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isJson($request)) {
            return $next($request);
        }

        try {
            $limitKb = (int) $this->settings->value('max_request_body_size_kb');
            $depth = (int) $this->settings->value('max_json_depth');
        } catch (Throwable) {
            return $next($request);
        }

        $length = $this->declaredLength($request);

        if ($limitKb > 0 && null !== $length && $length > $limitKb * 1024) {
            abort(413, sprintf(
                'Request body is larger than the %d KB limit.',
                $limitKb
            ));
        }

        $raw = $request->getContent();

        if ('' === $raw) {
            return $next($request);
        }

        // The authoritative size check: what arrived, not what was declared.
        if ($limitKb > 0 && strlen($raw) > $limitKb * 1024) {
            abort(413, sprintf(
                'Request body is larger than the %d KB limit.',
                $limitKb
            ));
        }

        $depth = max(1, min(self::MAX_DEPTH_CEILING, $depth));

        // The decode exists only to measure. Its result is discarded: the
        // controller parses the body itself, and a parse failure that is not a
        // depth failure belongs to the endpoint, not to a size guard.
        json_decode($raw, true, $depth);

        if (JSON_ERROR_DEPTH === json_last_error()) {
            abort(400, sprintf(
                'JSON body is nested deeper than the %d levels allowed.',
                $depth
            ));
        }

        return $next($request);
    }

    /**
     * Is this a JSON body?
     *
     * Matches `application/json` and structured suffixes (`application/ld+json`),
     * because the depth limit is about the parser, not about one MIME string.
     */
    private function isJson(Request $request): bool
    {
        $type = strtolower((string) $request->headers->get('Content-Type', ''));

        return str_contains($type, 'json');
    }

    /**
     * The declared body length, or null when the request does not declare one.
     *
     * Used only for the early rejection. A client that understates the length
     * still hits the check on the bytes actually read, and one that overstates it
     * only gets itself refused.
     */
    private function declaredLength(Request $request): ?int
    {
        $declared = $request->headers->get('Content-Length');

        if (null === $declared || ! ctype_digit($declared)) {
            return null;
        }

        return (int) $declared;
    }
}
