<?php
/**
 * Shared HTTP client for remote source drivers.
 *
 * Features: SSRF guard, ETag/Last-Modified caching, exponential backoff
 * retry (max 3), circuit breaker per host, rate-limit accounting.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Remote;

use NvoosGraphify\Graph\Db;
use WP_Error;

/**
 * Shared HTTP client for remote source drivers.
 *
 * @since 1.0.0
 */
final class HttpClient
{
    /**
     * Max retry attempts.
     *
     * @var int
     */
    public const MAX_RETRIES = 3;

    /**
     * Base sleep microseconds between retries (1 second).
     *
     * @var int
     */
    public const RETRY_BASE_US = 1000000;

    /**
     * Number of failures before circuit opens.
     *
     * @var int
     */
    public const CIRCUIT_THRESHOLD = 5;

    /**
     * Seconds before a half-open retry is allowed.
     *
     * @var int
     */
    public const CIRCUIT_RESET_TTL = 60;

    /**
     * Default cache TTL in seconds.
     *
     * @var int
     */
    public const DEFAULT_CACHE_TTL = 300;

    /**
     * Source slug for rate-limit accounting.
     *
     * @var string
     */
    private string $sourceSlug;

    /**
     * Constructor.
     *
     * @since 1.0.0
     * @param string $sourceSlug Source slug for rate-limit accounting.
     */
    public function __construct(string $sourceSlug = '')
    {
        $this->sourceSlug = sanitize_key($sourceSlug);
    }

    /**
     * Perform a GET request with caching and retry.
     *
     * @since 1.0.0
     * @param string               $url  URL to request.
     * @param array<string,mixed>  $args wp_remote_get compatible args.
     * @return array<string,mixed>|WP_Error Response array or WP_Error.
     */
    public function get(string $url, array $args = array()): array|WP_Error
    {
        return $this->request('GET', $url, array(), $args);
    }

    /**
     * Perform a POST request.
     *
     * @since 1.0.0
     * @param string               $url  URL to request.
     * @param array<string,mixed>  $body Request body.
     * @param array<string,mixed>  $args wp_remote_post compatible args.
     * @return array<string,mixed>|WP_Error Response array or WP_Error.
     */
    public function post(string $url, array $body = array(), array $args = array()): array|WP_Error
    {
        return $this->request('POST', $url, $body, $args);
    }

    /**
     * Force-close the circuit breaker for a given host.
     *
     * @since 1.0.0
     * @param string $host Host to reset.
     * @return void
     */
    public function resetCircuit(string $host): void
    {
        $key = 'nvoos_graphify_circuit_' . md5($host);
        delete_transient($key);
    }

    /**
     * Perform the HTTP request with SSRF guard, circuit breaker, caching, and retry.
     *
     * @since 1.0.0
     * @param string               $method HTTP method.
     * @param string               $url    URL.
     * @param array<string,mixed>  $body   Request body (POST only).
     * @param array<string,mixed>  $args   Additional wp_remote_* args.
     * @return array<string,mixed>|WP_Error
     */
    private function request(string $method, string $url, array $body = array(), array $args = array()): array|WP_Error
    {
        $url = esc_url_raw($url);
        if (empty($url)) {
            return new WP_Error('invalid_url', __('Invalid URL.', 'nvoos-graphify'));
        }

        // SSRF guard.
        $ssrfCheck = $this->checkSsrf($url);
        if (is_wp_error($ssrfCheck)) {
            return $ssrfCheck;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = strtolower((string) $host);

        // Circuit breaker check.
        $circuitCheck = $this->checkCircuit($host);
        if (is_wp_error($circuitCheck)) {
            return $circuitCheck;
        }

        // Cache check (GET only).
        $cacheKey = null;
        if ('GET' === $method) {
            $cacheKey = 'nvoos_graphify_httpcache_' . md5($url . wp_json_encode($args));
            $cached   = $this->getCached($cacheKey, $url, $args);
            if ($cached) {
                return $cached;
            }
        }

        // Build request args.
        $requestArgs = array_merge(
            array(
                'timeout'    => 15,
                'user-agent' => 'NV-oOS-Graphify/' . NVOOS_GRAPHIFY_VERSION . ' WordPress/' . get_bloginfo('version'),
            ),
            $args
        );

        if ('POST' === $method) {
            $requestArgs['body'] = $body;
        }

        // Retry loop with exponential backoff.
        $attempt   = 0;
        $lastError = null;
        while ($attempt < self::MAX_RETRIES) {
            if ($attempt > 0) {
                // Exponential backoff: 1s, 2s, 4s.
                usleep(self::RETRY_BASE_US * (int) pow(2, $attempt - 1));
            }
            ++$attempt;

            $raw = 'GET' === $method ? wp_remote_get($url, $requestArgs) : wp_remote_post($url, $requestArgs);

            if (is_wp_error($raw)) {
                $lastError = $raw;
                $this->recordFailure($host);
                continue;
            }

            $status = wp_remote_retrieve_response_code($raw);

            // Server errors (5xx) are retried; 4xx and 2xx/3xx are not.
            if ($status >= 500) {
                $lastError = new WP_Error('http_' . $status, sprintf('HTTP %d from %s', $status, esc_url($url)));
                $this->recordFailure($host);
                continue;
            }

            // Success — reset failure count.
            $this->recordSuccess($host);

            $responseHeaders = wp_remote_retrieve_headers($raw);
            $headersArray    = array();
            if (is_object($responseHeaders) && method_exists($responseHeaders, 'getAll')) {
                $headersArray = $responseHeaders->getAll();
            } elseif (is_array($responseHeaders)) {
                $headersArray = $responseHeaders;
            }

            $result = array(
                'body'    => wp_remote_retrieve_body($raw),
                'headers' => $headersArray,
                'status'  => $status,
                'cached'  => false,
            );

            // Cache GET responses.
            if ('GET' === $method && null !== $cacheKey) {
                $this->storeCache($cacheKey, $result, $headersArray);
            }

            return $result;
        }

        return $lastError instanceof WP_Error ? $lastError : new WP_Error('http_error', __('Request failed after retries.', 'nvoos-graphify'));
    }

    /**
     * Check for SSRF risk.
     *
     * @since 1.0.0
     * @param string $url URL to check.
     * @return true|WP_Error True if safe, WP_Error if blocked.
     */
    private function checkSsrf(string $url): true|WP_Error
    {
        /**
         * Allow bypassing the SSRF guard for private remotes (e.g. local development).
         *
         * @since 0.6.0
         * @param bool   $allow Whether to allow private IPs.
         * @param string $url   The URL being requested.
         */
        if (apply_filters('nvoos_graphify_allow_private_remotes', false, $url)) {
            return true;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return new WP_Error('ssrf_invalid_host', __('Could not parse host from URL.', 'nvoos-graphify'));
        }
        $host = strtolower($host);

        // Direct loopback / localhost checks (before DNS resolution).
        if ('localhost' === $host || '::1' === $host) {
            return new WP_Error('ssrf_blocked', __('SSRF guard: loopback address blocked.', 'nvoos-graphify'));
        }

        // Resolve to IP for range checks.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return new WP_Error('ssrf_unresolvable', __('SSRF guard: could not resolve host.', 'nvoos-graphify'));
        }

        if ($this->isPrivateIp($ip)) {
            return new WP_Error('ssrf_blocked', __('SSRF guard: private IP address blocked.', 'nvoos-graphify'));
        }

        return true;
    }

    /**
     * Check whether an IP address is in a private/loopback range.
     *
     * @since 1.0.0
     * @param string $ip IPv4 or IPv6 address string.
     * @return bool True if private.
     */
    private function isPrivateIp(string $ip): bool
    {
        if ('::1' === $ip) {
            return true;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        return false;
    }

    /**
     * Check the circuit breaker state for a host.
     *
     * @since 1.0.0
     * @param string $host Hostname.
     * @return true|WP_Error True if allowed, WP_Error if circuit is open.
     */
    private function checkCircuit(string $host): true|WP_Error
    {
        $key     = 'nvoos_graphify_circuit_' . md5($host);
        $circuit = get_transient($key);

        if (false === $circuit) {
            return true;
        }

        if (! is_array($circuit)) {
            return true;
        }

        $state = $circuit['state'] ?? 'closed';

        if ('open' === $state) {
            $openedAt = isset($circuit['opened_at']) ? (int) $circuit['opened_at'] : 0;
            if (time() - $openedAt >= self::CIRCUIT_RESET_TTL) {
                // Transition to half-open — allow one test request.
                $circuit['state'] = 'half-open';
                set_transient($key, $circuit, self::CIRCUIT_RESET_TTL * 2);
                return true;
            }
            /* translators: %s hostname */
            return new WP_Error('circuit_open', sprintf(__('Circuit breaker open for host: %s', 'nvoos-graphify'), esc_html($host)));
        }

        return true;
    }

    /**
     * Record a request failure and potentially open the circuit.
     *
     * @since 1.0.0
     * @param string $host Hostname.
     * @return void
     */
    private function recordFailure(string $host): void
    {
        $key     = 'nvoos_graphify_circuit_' . md5($host);
        $circuit = get_transient($key);
        if (! is_array($circuit)) {
            $circuit = array(
                'state'     => 'closed',
                'failures'  => 0,
                'opened_at' => 0,
            );
        }
        $circuit['failures'] = isset($circuit['failures']) ? (int) $circuit['failures'] + 1 : 1;
        if ($circuit['failures'] >= self::CIRCUIT_THRESHOLD) {
            $circuit['state']     = 'open';
            $circuit['opened_at'] = time();
        }
        set_transient($key, $circuit, self::CIRCUIT_RESET_TTL * 10);

        // Update DB circuit state if source slug is known.
        if ($this->sourceSlug && 'open' === $circuit['state']) {
            Db::updateRemoteSourceSync($this->sourceSlug, null, 'open');
        }
    }

    /**
     * Record a request success and close the circuit.
     *
     * @since 1.0.0
     * @param string $host Hostname.
     * @return void
     */
    private function recordSuccess(string $host): void
    {
        $key = 'nvoos_graphify_circuit_' . md5($host);
        delete_transient($key);

        if ($this->sourceSlug) {
            Db::updateRemoteSourceSync($this->sourceSlug, null, 'closed');
        }
    }

    /**
     * Attempt to return a cached response for a GET request.
     *
     * @since 1.0.0
     * @param string               $cacheKey Transient key.
     * @param string               $url      URL being requested.
     * @param array<string,mixed>  $args     Request args (by ref, may be modified with conditional headers).
     * @return array<string,mixed>|false Cached response array or false.
     */
    private function getCached(string $cacheKey, string $url, array &$args): array|false
    {
        $metaKey = $cacheKey . '_meta';
        $meta    = get_transient($metaKey);
        if (! is_array($meta)) {
            return false;
        }

        $body = get_transient($cacheKey);
        if (false === $body) {
            return false;
        }

        // Add conditional headers for revalidation.
        if (! isset($args['headers'])) {
            $args['headers'] = array();
        }
        if (! empty($meta['etag'])) {
            $args['headers']['If-None-Match'] = $meta['etag'];
        }
        if (! empty($meta['last_modified'])) {
            $args['headers']['If-Modified-Since'] = $meta['last_modified'];
        }

        // If no validators, return cache hit immediately.
        if (empty($meta['etag']) && empty($meta['last_modified'])) {
            return array(
                'body'    => $body,
                'headers' => $meta['headers'] ?? array(),
                'status'  => 200,
                'cached'  => true,
            );
        }

        return false; // Let the caller do a conditional request.
    }

    /**
     * Store a GET response in the transient cache.
     *
     * @since 1.0.0
     * @param string               $cacheKey        Transient key.
     * @param array<string,mixed>  $result          Response array.
     * @param array<string,mixed>  $responseHeaders Response headers.
     * @return void
     */
    private function storeCache(string $cacheKey, array $result, array $responseHeaders): void
    {
        $ttl = self::DEFAULT_CACHE_TTL;

        $cacheControl = $responseHeaders['cache-control'] ?? '';
        if ($cacheControl && preg_match('/max-age=(\d+)/i', $cacheControl, $m)) {
            $ttl = max(60, min(86400, (int) $m[1]));
        } elseif (! empty($responseHeaders['expires'])) {
            $expires = strtotime($responseHeaders['expires']);
            if ($expires > time()) {
                $ttl = min(86400, $expires - time());
            }
        }

        // Don't cache no-store responses.
        if ($cacheControl && false !== strpos($cacheControl, 'no-store')) {
            return;
        }

        $meta = array(
            'etag'          => $responseHeaders['etag'] ?? '',
            'last_modified' => $responseHeaders['last-modified'] ?? '',
            'headers'       => $responseHeaders,
        );

        set_transient($cacheKey, $result['body'], $ttl);
        set_transient($cacheKey . '_meta', $meta, $ttl);
    }
}
