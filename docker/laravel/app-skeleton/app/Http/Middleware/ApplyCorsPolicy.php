<?php

namespace App\Http\Middleware;

use App\Support\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * CORS, from the operator's settings rather than a build-time environment
 * variable — Proposal 149 §184.
 *
 * `config/cors.php` has always read `NVOOS_CORS_ORIGINS`, so the two controls on
 * `/ops/settings` (`cors_allow_origin`, `cors_allowed_origins`) were writable and
 * read by nothing. That is the same failure §183.9 fixed for the input guard:
 * a control that saves and is ignored reports a policy the runtime does not have.
 *
 * ## Why a middleware and not a config write
 *
 * The obvious fix is to fold the setting into `config(['cors.allowed_origins'])`
 * at boot. It is wrong here for two reasons: CORS is read per request by
 * `HandleCors`, and with Octane a boot-time write would freeze the first
 * request's value for the life of the worker. This class runs immediately before
 * `HandleCors` (registered with `prepend`, so it precedes the framework's own
 * entry in the global stack) and sets the effective policy for that request.
 *
 * A useful side effect: `SecurityController` grades `cors_restricted` by reading
 * `config('cors.allowed_origins')`, so the posture card now reports the policy
 * that is actually applied instead of the environment default.
 *
 * ## Precedence, and the trap it avoids
 *
 * A stored list **wins only when it has content**. `cors_allowed_origins`
 * defaults to the empty string, and treating that as authoritative would wipe an
 * existing `NVOOS_CORS_ORIGINS` on every request — an operator who never touched
 * the admin would silently lose their cross-origin clients. So:
 *
 * | Stored value | Effective policy |
 * |---|---|
 * | mode `star` | `['*']`, credentials off (see below) |
 * | mode `site`, list non-empty | the site's own origin + the listed origins |
 * | mode `site`, list empty | whatever `config/cors.php` resolved — unchanged |
 *
 * ## The wildcard cannot carry credentials
 *
 * The Fetch standard makes `Access-Control-Allow-Origin: *` invalid when the
 * request is credentialed, and `config/cors.php` sets `supports_credentials` to
 * true. Leaving both in place would hand an operator CORS that browsers reject —
 * a silent outage rather than a policy. Selecting `star` therefore also turns
 * credentials off, which is the only combination that works and the one that
 * matches what "any domain may call the API" can safely mean. The posture report
 * still flags the wildcard as `cors_restricted: fail`, which is correct.
 *
 * ## Failure behaviour
 *
 * If the settings store cannot be read (database down during a deploy), the
 * request proceeds with the configured policy unchanged. A CORS lookup failing
 * must not take the API down: the control narrows who may read cross-origin
 * responses, and the fallback is the deployment's own baseline, not "allow all".
 */
final class ApplyCorsPolicy
{
    /** The two modes `cors_allow_origin` offers. */
    public const MODE_SITE = 'site';

    public const MODE_STAR = 'star';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // No Origin header means no CORS decision to make. Skipping here keeps
        // first-party console calls (the overwhelming majority) free of the two
        // settings reads below.
        if (! $request->headers->has('Origin')) {
            return $next($request);
        }

        // `HandleCors` only acts on these paths; computing a policy for anything
        // else would be a query nobody reads.
        if (! $request->is('api/*', 'sanctum/*')) {
            return $next($request);
        }

        try {
            $mode = (string) $this->settings->value('cors_allow_origin');
            $listed = (string) $this->settings->value('cors_allowed_origins');
        } catch (Throwable) {
            return $next($request);
        }

        if (self::MODE_STAR === $mode) {
            config(array(
                'cors.allowed_origins' => array('*'),
                'cors.supports_credentials' => false,
            ));

            return $next($request);
        }

        $origins = $this->parseOrigins($listed);

        if (array() === $origins) {
            // Nothing stored: keep whatever the deployment configured.
            return $next($request);
        }

        $site = $this->siteOrigin();

        if (null !== $site) {
            array_unshift($origins, $site);
        }

        config(array('cors.allowed_origins' => array_values(array_unique($origins))));

        return $next($request);
    }

    /**
     * One origin per line, as the field describes.
     *
     * Entries are normalised to scheme://host[:port] with no trailing slash,
     * because `HandleCors` compares the `Origin` header literally and a stray
     * slash turns an allowed origin into a refused one. A bare `*` in the list is
     * dropped: the wildcard is what `cors_allow_origin` is for, and honouring it
     * here would let a textarea quietly open the API to every site.
     *
     * @return list<string>
     */
    private function parseOrigins(string $listed): array
    {
        $origins = array();

        foreach (preg_split('/\R/', $listed) ?: array() as $line) {
            $candidate = rtrim(trim($line), '/');

            if ('' === $candidate || '*' === $candidate) {
                continue;
            }

            if (! preg_match('#^https?://[^/\s]+$#i', $candidate)) {
                continue;
            }

            $origins[] = $candidate;
        }

        return $origins;
    }

    /**
     * The console's own origin, which the field says is always allowed.
     *
     * `app.url` is the deployment's own address; when it is unset or unparseable
     * the list is left as stored rather than guessing from the request, because a
     * request-supplied `Host` is attacker-controlled.
     */
    private function siteOrigin(): ?string
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
