<?php

namespace App\Support\ConnectedApps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Discovery for the four console keys supplied by separate NV oOS products
 * (090-i Phase 4, ADR-11: connected products are discovered, never ported).
 *
 * `contentGraph`, `docsHub`, `mediaStudio` and `documentEditor` are their own
 * plugins with their own REST contracts. On this runtime they are reachable
 * only when an operator points each one at its product base URL
 * (`oos.connected_apps`). This registry probes those bases, caches the answer,
 * and reports the URLs through the capability manifest
 * (`endpoint_urls` + a flipped `endpoints` entry).
 *
 * The probe is deliberately shallow and conservative:
 *
 *  - one GET per configured app, no redirects followed (a redirect is the
 *    classic SSRF pivot from an allowed host to an internal one);
 *  - HTTPS required for any non-loopback host (http stays available for
 *    localhost, and in the `local` environment); no embedded credentials, and
 *    IP literals in private/reserved ranges are refused before a socket opens;
 *  - a 2xx/3xx, or a 401/403, means *reached* — a product that answers with
 *    auth required is present, which is the fact the console needs. 404, 5xx
 *    and transport errors mean unavailable;
 *  - results are cached (`oos.connected_apps_ttl`, default 300 s), including
 *    failures, so an unreachable product cannot slow every console boot.
 *
 * DNS pinning is not implemented: Guzzle resolves the hostname itself, so a
 * hostile DNS server could still point at an internal address. The URLs are
 * operator-supplied configuration, not user input, and the literal-range and
 * redirect refusals close the realistic paths.
 */
final class ConnectedAppRegistry
{
    /** Cache key prefix for the probe results. */
    private const CACHE_KEY = 'nvoos:connected-apps';

    /**
     * Descriptor keys that may be pointed at a product, with their default
     * probe paths. Each product exposes a `health` route; Content Graph uses
     * its public graph route instead.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = array(
        'contentGraph' => '/graph',
        'docsHub' => '/health',
        'mediaStudio' => '/health',
        'documentEditor' => '/health',
    );

    /**
     * Configured apps that answered their probe.
     *
     * @return array<string, string> Console endpoint key => product REST base.
     */
    public function available(): array
    {
        $configured = $this->configured();

        if (array() === $configured) {
            return array();
        }

        $ttl = max(1, (int) config('oos.connected_apps_ttl', 300));
        $key = self::CACHE_KEY.':'.md5(serialize($configured));

        /** @var array<string, array{url: string, available: bool, status: int|null}> $results */
        $results = Cache::remember($key, $ttl, fn (): array => $this->probe($configured));

        $urls = array();

        foreach ($results as $endpoint => $result) {
            if ($result['available']) {
                $urls[$endpoint] = $result['url'];
            }
        }

        return $urls;
    }

    /**
     * Probe every configured app (uncached). Public for the health/tests seam.
     *
     * @param array<string, array{url: string, probe: string}>|null $configured
     *
     * @return array<string, array{url: string, available: bool, status: int|null}>
     */
    public function probe(?array $configured = null): array
    {
        $configured = $configured ?? $this->configured();
        $results = array();

        foreach ($configured as $endpoint => $entry) {
            $results[$endpoint] = $this->probeOne($entry['url'], $entry['probe']);
        }

        return $results;
    }

    /**
     * Configured apps, normalised. An empty `url` means "not configured".
     *
     * @return array<string, array{url: string, probe: string}>
     */
    public function configured(): array
    {
        $configured = array();
        $entries = (array) config('oos.connected_apps', array());

        foreach (self::DEFAULTS as $endpoint => $defaultProbe) {
            $entry = $entries[$endpoint] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $url = trim((string) ($entry['url'] ?? ''));

            if ('' === $url) {
                continue;
            }

            $configured[$endpoint] = array(
                'url' => $url,
                'probe' => (string) ($entry['probe'] ?? $defaultProbe),
            );
        }

        return $configured;
    }

    /**
     * Why a URL must not be probed, or null when it is acceptable.
     *
     * Public so the refusals are pinned by tests rather than trusted.
     */
    public function refuse(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return 'The URL could not be parsed.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, array('https', 'http'), true)) {
            return 'Only http(s) URLs can be probed.';
        }

        if ('' === $host) {
            return 'The URL has no host.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Credentials in the URL are not allowed.';
        }

        $literal = trim($host, '[]');
        $isLocal = in_array($host, array('localhost', '127.0.0.1', '::1', '[::1]'), true);

        if ('http' === $scheme && ! $isLocal && ! app()->environment('local')) {
            return 'Plain HTTP is only allowed for localhost (or in the local environment).';
        }

        if (false !== filter_var($literal, FILTER_VALIDATE_IP)) {
            $public = filter_var(
                $literal,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if (false === $public) {
                return 'Private, reserved and loopback addresses are not probed.';
            }
        }

        return null;
    }

    /**
     * Probe one product.
     *
     * @return array{url: string, available: bool, status: int|null}
     */
    private function probeOne(string $base, string $probe): array
    {
        $result = array(
            'url' => $base,
            'available' => false,
            'status' => null,
        );

        if (null !== $this->refuse($base)) {
            return $result;
        }

        $url = rtrim($base, '/').'/'.ltrim($probe, '/');

        try {
            $response = Http::timeout(max(1, (int) config('oos.connected_apps_timeout', 2)))
                ->connectTimeout(max(1, (int) config('oos.connected_apps_connect_timeout', 1)))
                ->withoutRedirecting()
                ->acceptJson()
                ->get($url);
        } catch (Throwable) {
            return $result;
        }

        $status = $response->status();
        $result['status'] = $status;

        // Reached: a success, or an auth challenge (the product exists).
        $result['available'] = ($status >= 200 && $status < 400)
            || in_array($status, array(401, 403), true);

        return $result;
    }
}
