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
 *    localhost, and in the `local` environment), no embedded credentials;
 *  - the hostname is resolved and **every** address is checked (OWASP: a
 *    hostname allowlist alone does not stop DNS rebinding), IP literals in
 *    private/reserved ranges are refused before a socket opens, and the
 *    validated addresses are pinned with `CURLOPT_RESOLVE` so the transport
 *    cannot re-resolve to a different address;
 *  - a 2xx/3xx, or a 401/403, means *reached* — a product that answers with
 *    auth required is present, which is the fact the console needs. 404, 5xx
 *    and transport errors mean unavailable;
 *  - an optional per-app `token` is sent as a bearer credential and never
 *    appears in the manifest or the probe result;
 *  - results are cached (`oos.connected_apps_ttl`, default 300 s), including
 *    failures, so an unreachable product cannot slow every console boot.
 *
 * Pinning is only as strong as the Guzzle handler: the CurlHandler honours
 * `CURLOPT_RESOLVE`, the stream handler ignores it. The resolution check still
 * refuses a private answer before a request is made either way.
 */
final class ConnectedAppRegistry
{
    /**
     * Extra IPv4 ranges PHP's filter flags miss but SSRF must not reach.
     *
     * CGNAT, benchmarking, documentation and "this network" ranges.
     *
     * @var string[]
     */
    private const EXTRA_BLOCKED_V4 = array(
        '0.0.0.0/8',
        '100.64.0.0/10',
        '198.18.0.0/15',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.51.100.0/24',
        '203.0.113.0/24',
    );

    /**
     * Optional resolver seam for tests (host => IP list).
     *
     * Untyped with a default so Laravel's container constructs the class with
     * the real resolver.
     *
     * @var callable|null
     */
    private $resolver;
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
     * @param callable|null $resolver Test seam: host => list of IP strings.
     */
    public function __construct($resolver = null)
    {
        $this->resolver = $resolver;
    }

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
     * @param array<string, array{url: string, probe: string, token: string}>|null $configured
     *
     * @return array<string, array{url: string, available: bool, status: int|null}>
     */
    public function probe(?array $configured = null): array
    {
        $configured = $configured ?? $this->configured();
        $results = array();

        foreach ($configured as $endpoint => $entry) {
            $results[$endpoint] = $this->probeOne($entry['url'], $entry['probe'], $entry['token']);
        }

        return $results;
    }

    /**
     * Configured apps, normalised. An empty `url` means "not configured".
     *
     * `token` is optional and only ever used as a request header.
     *
     * @return array<string, array{url: string, probe: string, token: string}>
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
                'token' => (string) ($entry['token'] ?? ''),
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
    private function probeOne(string $base, string $probe, string $token): array
    {
        $result = array(
            'url' => $base,
            'available' => false,
            'status' => null,
        );

        if (null !== $this->refuse($base)) {
            return $result;
        }

        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
        $resolution = $this->resolveHost($host);

        if ($resolution['blocked']) {
            return $result;
        }

        $url = rtrim($base, '/').'/'.ltrim($probe, '/');
        $options = array();

        if (defined('CURLOPT_RESOLVE') && array() !== $resolution['ips']) {
            $options['curl'] = array(
                CURLOPT_RESOLVE => array_map(
                    static function (string $ip) use ($host, $port): string {
                        $literal = false !== strpos($ip, ':') ? '['.$ip.']' : $ip;

                        return $host.':'.$port.':'.$literal;
                    },
                    $resolution['ips'],
                ),
            );
        }

        $headers = array();

        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = Http::timeout(max(1, (int) config('oos.connected_apps_timeout', 2)))
                ->connectTimeout(max(1, (int) config('oos.connected_apps_connect_timeout', 1)))
                ->withoutRedirecting()
                ->acceptJson()
                ->withOptions($options)
                ->withHeaders($headers)
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

    /**
     * Resolve a hostname and decide whether it is safe to connect to.
     *
     * Every address must be public: a hostname that returns one private
     * address among several is refused (DNS rebinding / split-horizon tricks).
     * A loopback name is allowed — the operator opted into a local product —
     * but its addresses are still returned for pinning.
     *
     * @return array{ips: string[], blocked: bool}
     */
    public function resolveHost(string $host): array
    {
        $host = strtolower(trim($host));

        if ('' === $host) {
            return array('ips' => array(), 'blocked' => true);
        }

        $ips = $this->lookup($host);

        if (array() === $ips) {
            // Cannot validate what does not resolve; refuse rather than let the
            // client resolve it a second time.
            return array('ips' => array(), 'blocked' => true);
        }

        if ($this->isLoopbackHost($host)) {
            return array('ips' => $ips, 'blocked' => false);
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return array('ips' => $ips, 'blocked' => true);
            }
        }

        return array('ips' => $ips, 'blocked' => false);
    }

    /**
     * Is this address a public unicast address?
     */
    public function isPublicIp(string $ip): bool
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if (false === $public) {
            return false;
        }

        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            if (false === $long) {
                return false;
            }

            foreach (self::EXTRA_BLOCKED_V4 as $cidr) {
                if ($this->ipv4InCidr($long, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        $lower = strtolower($ip);

        // Unspecified and the IPv6 documentation range.
        return '::' !== $lower && ! str_starts_with($lower, '2001:db8:');
    }

    /**
     * Resolve a hostname to its addresses.
     *
     * @return string[]
     */
    private function lookup(string $host): array
    {
        if (is_callable($this->resolver)) {
            $ips = ($this->resolver)($host);

            return array_values(
                array_unique(
                    array_filter(
                        (array) $ips,
                        static function ($ip) {
                            return is_string($ip) && false !== filter_var($ip, FILTER_VALIDATE_IP);
                        }
                    )
                )
            );
        }

        $ips = array();

        foreach ((array) gethostbynamel($host) as $ip) {
            $ips[] = $ip;
        }

        // AAAA records are optional; a resolver without IPv6 still works.
        $records = @dns_get_record($host, DNS_AAAA); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is a normal "cannot resolve" case.

        foreach ((array) $records as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Is the host a loopback name or address?
     */
    private function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), array('localhost', '127.0.0.1', '::1'), true);
    }

    /**
     * Is a packed IPv4 address inside a CIDR block?
     */
    private function ipv4InCidr(int $long, string $cidr): bool
    {
        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $mask = -1 << (32 - $bits);
        $subnetLong = ip2long($subnet);

        return false !== $subnetLong && ($long & $mask) === ($subnetLong & $mask);
    }
}
