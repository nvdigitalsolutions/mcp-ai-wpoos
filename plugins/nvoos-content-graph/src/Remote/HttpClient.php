<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Schema;
use WP_Error;
use function absint;
use function add_query_arg;
use function apply_filters;
use function current_time;
use function delete_transient;
use function esc_html;
use function esc_url;
use function esc_url_raw;
use function get_bloginfo;
use function get_transient;
use function gethostbyname;
use function in_array;
use function is_array;
use function is_object;
use function is_wp_error;
use function max;
use function md5;
use function method_exists;
use function min;
use function pow;
use function preg_match;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function usleep;
use function wp_json_encode;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_header;
use function wp_remote_retrieve_headers;
use function wp_remote_retrieve_response_code;

/**
 * Shared HTTP client for remote source drivers with:
 *   - SSRF guard (blocks private/loopback IPs)
 *   - ETag/Last-Modified caching via WordPress transients
 *   - Exponential backoff retry (max 3 attempts)
 *   - Circuit breaker per host (closed/open/half-open)
 *
 * @since 1.0.0
 */
class HttpClient {

	/** @var int Max retry attempts. */
	private const MAX_RETRIES = 3;

	/** @var int Base sleep microseconds between retries (1 second). */
	private const RETRY_BASE_US = 1000000;

	/** @var int Number of failures before circuit opens. */
	private const CIRCUIT_THRESHOLD = 5;

	/** @var int Seconds before a half-open retry is allowed. */
	private const CIRCUIT_RESET_TTL = 60;

	/** @var int Default cache TTL in seconds. */
	private const DEFAULT_CACHE_TTL = 300;

	/** @var string Source slug for rate-limit / circuit-breaker accounting. */
	private string $sourceSlug;

	/**
	 * Constructor.
	 *
	 * @param string $sourceSlug Source slug for rate-limit accounting.
	 */
	public function __construct( string $sourceSlug = '' ) {
		$this->sourceSlug = sanitize_key( $sourceSlug );
	}

	/**
	 * Perform a GET request with caching and retry.
	 *
	 * @param string $url  URL to request.
	 * @param array  $args wp_remote_get compatible args.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function get( string $url, array $args = array() ) {
		return $this->request( 'GET', $url, array(), $args );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $url  URL to request.
	 * @param mixed  $body Request body.
	 * @param array  $args wp_remote_post compatible args.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function post( string $url, $body = array(), array $args = array() ) {
		return $this->request( 'POST', $url, $body, $args );
	}

	/**
	 * Force-close the circuit breaker for a given host.
	 *
	 * @param string $host Host to reset.
	 * @return void
	 */
	public function resetCircuit( string $host ): void {
		$key = 'nvoos_content_graph_circuit_' . md5( $host );
		delete_transient( $key );
	}

	// ───────────────────────────────────────────────────────────────
	// Internal request pipeline
	// ───────────────────────────────────────────────────────────────

	/**
	 * Perform the HTTP request with SSRF guard, circuit breaker, caching, and retry.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param mixed  $body   Request body (POST only).
	 * @param array  $args   Additional wp_remote_* args.
	 * @return array|WP_Error
	 */
	private function request( string $method, string $url, $body = array(), array $args = array() ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid URL.', 'nvoos-content-graph' ) );
		}

		// SSRF guard.
		$ssrfCheck = $this->checkSsrf( $url );
		if ( is_wp_error( $ssrfCheck ) ) {
			return $ssrfCheck;
		}

		$host = wp_parse_url( $url, \PHP_URL_HOST );
		$host = strtolower( (string) $host );

		// Circuit breaker check.
		$circuitCheck = $this->checkCircuit( $host );
		if ( is_wp_error( $circuitCheck ) ) {
			return $circuitCheck;
		}

		// Cache check (GET only).
		$cacheKey = null;
		if ( 'GET' === $method ) {
			$cacheKey = 'nvoos_content_graph_httpcache_' . md5( $url . wp_json_encode( $args ) );
			$cached   = $this->getCached( $cacheKey, $url, $args );
			if ( $cached ) {
				return $cached;
			}
		}

		// Build request args. Redirects are disabled at the transport level
		// and followed manually below so every hop passes the SSRF guard.
		$requestArgs = array_merge(
			array(
				'timeout'     => 15,
				'user-agent'  => 'NV-oOS-Content Graph/' . NVOOS_CONTENT_GRAPH_VERSION,
				'redirection' => 0,
			),
			$args
		);

		if ( 'POST' === $method ) {
			$requestArgs['body'] = $body;
		}

		// Retry loop with exponential backoff.
		$attempt    = 0;
		$redirects  = 0;
		$lastError  = null;
		$currentUrl = $url;
		while ( $attempt < self::MAX_RETRIES ) {
			if ( $attempt > 0 ) {
				// Exponential backoff: 1s, 2s, 4s.
				usleep( self::RETRY_BASE_US * (int) pow( 2, $attempt - 1 ) );
			}
			++$attempt;

			if ( 'GET' === $method ) {
				$raw = wp_remote_get( $currentUrl, $requestArgs );
			} else {
				$raw = wp_remote_post( $currentUrl, $requestArgs );
			}

			if ( is_wp_error( $raw ) ) {
				$lastError = $raw;
				$this->recordFailure( $host );
				continue;
			}

			$status = wp_remote_retrieve_response_code( $raw );

			// Server errors (5xx) are retried; 4xx and 2xx/3xx are not.
			if ( $status >= 500 ) {
				/* translators: %d HTTP status code, %s URL */
				$lastError = new WP_Error( 'http_' . $status, sprintf( __( 'HTTP %1$d from %2$s', 'nvoos-content-graph' ), $status, esc_url( $currentUrl ) ) );
				$this->recordFailure( $host );
				continue;
			}

			// Follow redirects manually (max 3) so the SSRF guard re-runs on
			// every hop — a public URL redirecting to a private address must
			// be blocked just like a direct request to it.
			if ( $status >= 300 && $status < 400 && $redirects < 3 ) {
				$location = wp_remote_retrieve_header( $raw, 'location' );
				$nextUrl  = $this->resolveRedirect( $currentUrl, (string) $location );
				if ( '' !== $nextUrl ) {
					$ssrf = $this->checkSsrf( $nextUrl );
					if ( is_wp_error( $ssrf ) ) {
						$lastError = $ssrf;
						break;
					}
					++$redirects;
					$currentUrl = $nextUrl;
					--$attempt; // A redirect hop does not consume a retry.
					continue;
				}
			}

			// Not-Modified (304): the cached copy is still valid. Serve it
			// instead of the empty 304 body — drivers would otherwise ingest
			// nothing, and the empty body would overwrite the cache entry.
			if ( 304 === $status && 'GET' === $method && null !== $cacheKey ) {
				$cachedBody = get_transient( $cacheKey );
				if ( false !== $cachedBody && '' !== $cachedBody ) {
					$this->recordSuccess( $host );

					$responseHeaders = wp_remote_retrieve_headers( $raw );
					$headersArray    = array();
					if ( is_object( $responseHeaders ) && method_exists( $responseHeaders, 'getAll' ) ) {
						$headersArray = $responseHeaders->getAll();
					} elseif ( is_array( $responseHeaders ) ) {
						$headersArray = $responseHeaders;
					}

					return array(
						'body'    => $cachedBody,
						'headers' => $headersArray,
						'status'  => 200,
						'cached'  => true,
					);
				}
			}

			// Success — reset failure count.
			$this->recordSuccess( $host );

			$responseHeaders = wp_remote_retrieve_headers( $raw );
			$headersArray    = array();
			if ( is_object( $responseHeaders ) && method_exists( $responseHeaders, 'getAll' ) ) {
				$headersArray = $responseHeaders->getAll();
			} elseif ( is_array( $responseHeaders ) ) {
				$headersArray = $responseHeaders;
			}

			$result = array(
				'body'    => wp_remote_retrieve_body( $raw ),
				'headers' => $headersArray,
				'status'  => $status,
				'cached'  => false,
			);

			// Cache GET responses.
			if ( 'GET' === $method && null !== $cacheKey ) {
				$this->storeCache( $cacheKey, $result, $headersArray );
			}

			return $result;
		}

		return $lastError instanceof WP_Error ? $lastError : new WP_Error( 'http_error', __( 'Request failed after retries.', 'nvoos-content-graph' ) );
	}

	// ───────────────────────────────────────────────────────────────
	// Redirect handling
	// ───────────────────────────────────────────────────────────────

	/**
	 * Resolve a Location header value into an absolute URL for re-validation.
	 *
	 * @param string $currentUrl The URL that returned the redirect.
	 * @param string $location   Raw Location header value.
	 * @return string Absolute URL, or '' when it cannot be resolved safely.
	 */
	private function resolveRedirect( string $currentUrl, string $location ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return '';
		}

		$resolved = \WP_Http::make_absolute_url( $location, $currentUrl );
		if ( false === $resolved ) {
			return '';
		}

		$resolved = esc_url_raw( $resolved );
		return is_string( $resolved ) ? $resolved : '';
	}

	// ───────────────────────────────────────────────────────────────
	// SSRF guard
	// ───────────────────────────────────────────────────────────────

	/**
	 * Check for SSRF risk; return WP_Error if the URL targets a private/loopback address.
	 *
	 * @param string $url URL to check.
	 * @return true|WP_Error True if safe, WP_Error if blocked.
	 */
	private function checkSsrf( string $url ) {
		/**
		 * Allow bypassing the SSRF guard for private remotes (e.g. local development).
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $allow Whether to allow private IPs.
		 * @param string $url   The URL being requested.
		 */
		if ( apply_filters( Schema::FILTER_ALLOW_PRIVATE_URLS, false, $url ) ) {
			return true;
		}

		$host = wp_parse_url( $url, \PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error( 'ssrf_invalid_host', __( 'Could not parse host from URL.', 'nvoos-content-graph' ) );
		}
		$host = strtolower( $host );

		// Direct loopback / localhost checks (before DNS resolution).
		if ( 'localhost' === $host || '::1' === $host ) {
			return new WP_Error( 'ssrf_blocked', __( 'SSRF guard: loopback address blocked.', 'nvoos-content-graph' ) );
		}

		// Resolve to IP for range checks.
		$ip = \filter_var( $host, \FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! \filter_var( $ip, \FILTER_VALIDATE_IP ) ) {
			// Could not resolve — block to be safe.
			return new WP_Error( 'ssrf_unresolvable', __( 'SSRF guard: could not resolve host.', 'nvoos-content-graph' ) );
		}

		if ( $this->isPrivateIp( $ip ) ) {
			return new WP_Error( 'ssrf_blocked', __( 'SSRF guard: private IP address blocked.', 'nvoos-content-graph' ) );
		}

		return true;
	}

	/**
	 * Check whether an IP address is in a private/loopback range.
	 *
	 * @param string $ip IPv4 or IPv6 address string.
	 * @return bool True if private.
	 */
	private function isPrivateIp( string $ip ): bool {
		if ( '::1' === $ip ) {
			return true;
		}

		if ( ! \filter_var( $ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return false;
	}

	// ───────────────────────────────────────────────────────────────
	// Circuit breaker
	// ───────────────────────────────────────────────────────────────

	/**
	 * Check the circuit breaker state for a host.
	 *
	 * @param string $host Hostname.
	 * @return true|WP_Error True if allowed, WP_Error if circuit is open.
	 */
	private function checkCircuit( string $host ) {
		$key     = 'nvoos_content_graph_circuit_' . md5( $host );
		$circuit = get_transient( $key );

		if ( false === $circuit ) {
			return true; // No circuit info = closed.
		}

		if ( ! is_array( $circuit ) ) {
			return true;
		}

		$state = $circuit['state'] ?? 'closed';

		if ( 'open' === $state ) {
			$openedAt = isset( $circuit['opened_at'] ) ? (int) $circuit['opened_at'] : 0;
			if ( time() - $openedAt >= self::CIRCUIT_RESET_TTL ) {
				// Transition to half-open — allow one test request.
				$circuit['state'] = 'half-open';
				set_transient( $key, $circuit, self::CIRCUIT_RESET_TTL * 2 );
				return true;
			}
			/* translators: %s hostname */
			return new WP_Error( 'circuit_open', sprintf( __( 'Circuit breaker open for host: %s', 'nvoos-content-graph' ), esc_html( $host ) ) );
		}

		return true;
	}

	/**
	 * Record a request failure and potentially open the circuit.
	 *
	 * @param string $host Hostname.
	 * @return void
	 */
	private function recordFailure( string $host ): void {
		$key     = 'nvoos_content_graph_circuit_' . md5( $host );
		$circuit = get_transient( $key );
		if ( ! is_array( $circuit ) ) {
			$circuit = array(
				'state'     => 'closed',
				'failures'  => 0,
				'opened_at' => 0,
			);
		}
		$circuit['failures'] = isset( $circuit['failures'] ) ? (int) $circuit['failures'] + 1 : 1;
		if ( $circuit['failures'] >= self::CIRCUIT_THRESHOLD ) {
			$circuit['state']     = 'open';
			$circuit['opened_at'] = time();
		}
		set_transient( $key, $circuit, self::CIRCUIT_RESET_TTL * 10 );

		// Update DB circuit state if source slug is known.
		if ( $this->sourceSlug && 'open' === $circuit['state'] && \class_exists( Db::class ) ) {
			Db::updateRemoteSourceSync( $this->sourceSlug, null, 'open' );
		}
	}

	/**
	 * Record a request success and close the circuit.
	 *
	 * @param string $host Hostname.
	 * @return void
	 */
	private function recordSuccess( string $host ): void {
		$key = 'nvoos_content_graph_circuit_' . md5( $host );
		delete_transient( $key );

		if ( $this->sourceSlug && \class_exists( Db::class ) ) {
			Db::updateRemoteSourceSync( $this->sourceSlug, null, 'closed' );
		}
	}

	// ───────────────────────────────────────────────────────────────
	// HTTP caching
	// ───────────────────────────────────────────────────────────────

	/**
	 * Attempt to return a cached response for a GET request.
	 *
	 * @param string $cacheKey Transient key for cached body.
	 * @param string $url      URL being requested.
	 * @param array  $args     Request args (may be modified with conditional headers).
	 * @return array|false Cached response array or false if no valid cache.
	 */
	private function getCached( string $cacheKey, string $url, array &$args ) {
		$metaKey = $cacheKey . '_meta';
		$meta    = get_transient( $metaKey );
		if ( ! is_array( $meta ) ) {
			return false;
		}

		$body = get_transient( $cacheKey );
		if ( false === $body ) {
			return false;
		}

		// Add conditional headers for revalidation.
		if ( ! isset( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		if ( ! empty( $meta['etag'] ) ) {
			$args['headers']['If-None-Match'] = $meta['etag'];
		}
		if ( ! empty( $meta['last_modified'] ) ) {
			$args['headers']['If-Modified-Since'] = $meta['last_modified'];
		}

		// If no validators, return cache hit immediately.
		if ( empty( $meta['etag'] ) && empty( $meta['last_modified'] ) ) {
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
	 * @param string $cacheKey        Transient key.
	 * @param array  $result           Response array from wp_remote_get.
	 * @param array  $responseHeaders  Response headers.
	 * @return void
	 */
	private function storeCache( string $cacheKey, array $result, array $responseHeaders ): void {
		// Never cache an empty body — a 304 (or a zero-length 200) must not
		// overwrite a previously cached response body.
		if ( '' === $result['body'] ) {
			return;
		}

		$ttl = self::DEFAULT_CACHE_TTL;

		$cacheControl = $responseHeaders['cache-control'] ?? '';
		if ( $cacheControl && preg_match( '/max-age=(\d+)/i', $cacheControl, $m ) ) {
			$ttl = max( 60, min( 86400, (int) $m[1] ) );
		} elseif ( ! empty( $responseHeaders['expires'] ) ) {
			$expires = strtotime( $responseHeaders['expires'] );
			if ( $expires > time() ) {
				$ttl = min( 86400, $expires - time() );
			}
		}

		// Don't cache no-store responses.
		if ( $cacheControl && false !== strpos( $cacheControl, 'no-store' ) ) {
			return;
		}

		$meta = array(
			'etag'          => $responseHeaders['etag'] ?? '',
			'last_modified' => $responseHeaders['last-modified'] ?? '',
			'headers'       => $responseHeaders,
		);

		set_transient( $cacheKey, $result['body'], $ttl );
		set_transient( $cacheKey . '_meta', $meta, $ttl );
	}
}
