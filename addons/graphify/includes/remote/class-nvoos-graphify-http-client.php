<?php
/**
 * NV oOS Graphify — Shared HTTP Client
 *
 * A reusable HTTP client for remote source drivers with:
 *   - SSRF guard (blocks private/loopback IPs)
 *   - ETag/Last-Modified caching via WordPress transients
 *   - Exponential backoff retry (max 3 attempts)
 *   - Circuit breaker per host (closed/open/half-open)
 *   - Rate-limit accounting per source slug
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared HTTP client for remote source drivers.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_HTTP_Client {

	/**
	 * Max retry attempts.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Base sleep microseconds between retries (1 second).
	 *
	 * @var int
	 */
	const RETRY_BASE_US = 1000000;

	/**
	 * Number of failures before circuit opens.
	 *
	 * @var int
	 */
	const CIRCUIT_THRESHOLD = 5;

	/**
	 * Seconds before a half-open retry is allowed.
	 *
	 * @var int
	 */
	const CIRCUIT_RESET_TTL = 60;

	/**
	 * Default cache TTL in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = 300;

	/**
	 * Source slug for rate-limit accounting.
	 *
	 * @var string
	 */
	private $source_slug;

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param string $source_slug Source slug for rate-limit accounting.
	 */
	public function __construct( $source_slug = '' ) {
		$this->source_slug = sanitize_key( $source_slug );
	}

	/**
	 * Perform a GET request with caching and retry.
	 *
	 * @since 0.6.0
	 *
	 * @param string $url  URL to request.
	 * @param array  $args wp_remote_get compatible args.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function get( $url, $args = array() ) {
		return $this->request( 'GET', $url, array(), $args );
	}

	/**
	 * Perform a POST request.
	 *
	 * @since 0.6.0
	 *
	 * @param string $url  URL to request.
	 * @param array  $body Request body.
	 * @param array  $args wp_remote_post compatible args.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function post( $url, $body = array(), $args = array() ) {
		return $this->request( 'POST', $url, $body, $args );
	}

	/**
	 * Force-close the circuit breaker for a given host.
	 *
	 * @since 0.6.0
	 *
	 * @param string $host Host to reset.
	 * @return void
	 */
	public function reset_circuit( $host ) {
		$key = 'nvoos_graphify_circuit_' . md5( $host );
		delete_transient( $key );
	}

	/**
	 * Perform the HTTP request with SSRF guard, circuit breaker, caching, and retry.
	 *
	 * @since 0.6.0
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $body   Request body (POST only).
	 * @param array  $args   Additional wp_remote_* args.
	 * @return array|WP_Error
	 */
	private function request( $method, $url, $body = array(), $args = array() ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid URL.', 'nvoos-graphify' ) );
		}

		// SSRF guard.
		$ssrf_check = $this->check_ssrf( $url );
		if ( is_wp_error( $ssrf_check ) ) {
			return $ssrf_check;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( (string) $host );

		// Circuit breaker check.
		$circuit_check = $this->check_circuit( $host );
		if ( is_wp_error( $circuit_check ) ) {
			return $circuit_check;
		}

		// Cache check (GET only).
		$cache_key = null;
		if ( 'GET' === $method ) {
			$cache_key = 'nvoos_graphify_httpcache_' . md5( $url . wp_json_encode( $args ) );
			$cached    = $this->get_cached( $cache_key, $url, $args );
			if ( $cached ) {
				return $cached;
			}
		}

		// Build request args.
		$request_args = array_merge(
			array(
				'timeout'    => 15,
				'user-agent' => 'NV-oOS-Graphify/' . NVOOS_GRAPHIFY_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
			),
			$args
		);

		if ( 'POST' === $method ) {
			$request_args['body'] = $body;
		}

		// Retry loop with exponential backoff.
		$attempt    = 0;
		$last_error = null;
		while ( $attempt < self::MAX_RETRIES ) {
			if ( $attempt > 0 ) {
				// Exponential backoff: 1s, 2s, 4s.
				usleep( self::RETRY_BASE_US * (int) pow( 2, $attempt - 1 ) );
			}
			++$attempt;

			if ( 'GET' === $method ) {
				$raw = wp_remote_get( $url, $request_args );
			} else {
				$raw = wp_remote_post( $url, $request_args );
			}

			if ( is_wp_error( $raw ) ) {
				$last_error = $raw;
				$this->record_failure( $host );
				continue;
			}

			$status = wp_remote_retrieve_response_code( $raw );

			// Server errors (5xx) are retried; 4xx and 2xx/3xx are not.
			if ( $status >= 500 ) {
				$last_error = new WP_Error( 'http_' . $status, sprintf( 'HTTP %d from %s', $status, esc_url( $url ) ) );
				$this->record_failure( $host );
				continue;
			}

			// Not-Modified (304): the cached copy is still valid. Serve it
			// instead of the empty 304 body — drivers would otherwise ingest
			// nothing, and the empty body would overwrite the cache entry.
			if ( 304 === $status && 'GET' === $method && null !== $cache_key ) {
				$cached_body = get_transient( $cache_key );
				if ( false !== $cached_body && '' !== $cached_body ) {
					$this->record_success( $host );

					$response_headers = wp_remote_retrieve_headers( $raw );
					$headers_array    = array();
					if ( is_object( $response_headers ) && method_exists( $response_headers, 'getAll' ) ) {
						$headers_array = $response_headers->getAll();
					} elseif ( is_array( $response_headers ) ) {
						$headers_array = $response_headers;
					}

					return array(
						'body'    => $cached_body,
						'headers' => $headers_array,
						'status'  => 200,
						'cached'  => true,
					);
				}
			}

			// Success — reset failure count.
			$this->record_success( $host );

			$response_headers = wp_remote_retrieve_headers( $raw );
			$headers_array    = array();
			if ( is_object( $response_headers ) && method_exists( $response_headers, 'getAll' ) ) {
				$headers_array = $response_headers->getAll();
			} elseif ( is_array( $response_headers ) ) {
				$headers_array = $response_headers;
			}

			$result = array(
				'body'    => wp_remote_retrieve_body( $raw ),
				'headers' => $headers_array,
				'status'  => $status,
				'cached'  => false,
			);

			// Cache GET responses.
			if ( 'GET' === $method && null !== $cache_key ) {
				$this->store_cache( $cache_key, $result, $headers_array );
			}

			return $result;
		}

		return $last_error instanceof WP_Error ? $last_error : new WP_Error( 'http_error', __( 'Request failed after retries.', 'nvoos-graphify' ) );
	}

	/**
	 * Check for SSRF risk; return WP_Error if the URL targets a private/loopback address.
	 *
	 * @since 0.6.0
	 *
	 * @param string $url URL to check.
	 * @return true|WP_Error True if safe, WP_Error if blocked.
	 */
	private function check_ssrf( $url ) {
		/**
		 * Allow bypassing the SSRF guard for private remotes (e.g. local development).
		 *
		 * @since 0.6.0
		 *
		 * @param bool   $allow Whether to allow private IPs.
		 * @param string $url   The URL being requested.
		 */
		if ( apply_filters( 'nvoos_graphify_allow_private_remotes', false, $url ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error( 'ssrf_invalid_host', __( 'Could not parse host from URL.', 'nvoos-graphify' ) );
		}
		$host = strtolower( $host );

		// Direct loopback / localhost checks (before DNS resolution).
		if ( 'localhost' === $host || '::1' === $host ) {
			return new WP_Error( 'ssrf_blocked', __( 'SSRF guard: loopback address blocked.', 'nvoos-graphify' ) );
		}

		// Resolve to IP for range checks.
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			// Could not resolve — block to be safe.
			return new WP_Error( 'ssrf_unresolvable', __( 'SSRF guard: could not resolve host.', 'nvoos-graphify' ) );
		}

		if ( $this->is_private_ip( $ip ) ) {
			return new WP_Error( 'ssrf_blocked', __( 'SSRF guard: private IP address blocked.', 'nvoos-graphify' ) );
		}

		return true;
	}

	/**
	 * Check whether an IP address is in a private/loopback range.
	 *
	 * @since 0.6.0
	 *
	 * @param string $ip IPv4 or IPv6 address string.
	 * @return bool True if private.
	 */
	private function is_private_ip( $ip ) {
		// IPv6 loopback.
		if ( '::1' === $ip ) {
			return true;
		}

		// Use filter_var for standard private-range check (handles IPv4 and mapped IPv6).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check the circuit breaker state for a host.
	 *
	 * @since 0.6.0
	 *
	 * @param string $host Hostname.
	 * @return true|WP_Error True if allowed, WP_Error if circuit is open.
	 */
	private function check_circuit( $host ) {
		$key     = 'nvoos_graphify_circuit_' . md5( $host );
		$circuit = get_transient( $key );

		if ( false === $circuit ) {
			return true; // No circuit info = closed.
		}

		if ( ! is_array( $circuit ) ) {
			return true;
		}

		$state = isset( $circuit['state'] ) ? $circuit['state'] : 'closed';

		if ( 'open' === $state ) {
			// Check if reset TTL has passed.
			$opened_at = isset( $circuit['opened_at'] ) ? (int) $circuit['opened_at'] : 0;
			if ( time() - $opened_at >= self::CIRCUIT_RESET_TTL ) {
				// Transition to half-open — allow one test request.
				$circuit['state'] = 'half-open';
				set_transient( $key, $circuit, self::CIRCUIT_RESET_TTL * 2 );
				return true;
			}
			/* translators: %s hostname */
			return new WP_Error( 'circuit_open', sprintf( __( 'Circuit breaker open for host: %s', 'nvoos-graphify' ), esc_html( $host ) ) );
		}

		return true;
	}

	/**
	 * Record a request failure and potentially open the circuit.
	 *
	 * @since 0.6.0
	 *
	 * @param string $host Hostname.
	 * @return void
	 */
	private function record_failure( $host ) {
		$key     = 'nvoos_graphify_circuit_' . md5( $host );
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
		if ( $this->source_slug && 'open' === $circuit['state'] ) {
			NV_oOS_Graphify_DB::set_circuit_state( $this->source_slug, 'open' );
		}
	}

	/**
	 * Record a request success and close the circuit.
	 *
	 * @since 0.6.0
	 *
	 * @param string $host Hostname.
	 * @return void
	 */
	private function record_success( $host ) {
		$key = 'nvoos_graphify_circuit_' . md5( $host );
		delete_transient( $key );

		if ( $this->source_slug ) {
			NV_oOS_Graphify_DB::set_circuit_state( $this->source_slug, 'closed' );
		}
	}

	/**
	 * Attempt to return a cached response for a GET request.
	 *
	 * @since 0.6.0
	 *
	 * @param string $cache_key Transient key for cached body.
	 * @param string $url       URL being requested.
	 * @param array  $args      Request args (may be modified with conditional headers).
	 * @return array|false Cached response array or false if no valid cache.
	 */
	private function get_cached( $cache_key, $url, &$args ) {
		$meta_key = $cache_key . '_meta';
		$meta     = get_transient( $meta_key );
		if ( ! is_array( $meta ) ) {
			return false;
		}

		$body = get_transient( $cache_key );
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

		// If ETag or Last-Modified set, we'll do a conditional request upstream.
		// For a simple cache hit (no validators), return immediately.
		if ( empty( $meta['etag'] ) && empty( $meta['last_modified'] ) ) {
			return array(
				'body'    => $body,
				'headers' => isset( $meta['headers'] ) ? $meta['headers'] : array(),
				'status'  => 200,
				'cached'  => true,
			);
		}

		return false; // Let the caller do a conditional request.
	}

	/**
	 * Store a GET response in the transient cache.
	 *
	 * @since 0.6.0
	 *
	 * @param string $cache_key      Transient key.
	 * @param array  $result         Response array from wp_remote_get.
	 * @param array  $response_headers Response headers.
	 * @return void
	 */
	private function store_cache( $cache_key, array $result, array $response_headers ) {
		// Never cache an empty body — a 304 (or a zero-length 200) must not
		// overwrite a previously cached response body.
		if ( '' === $result['body'] ) {
			return;
		}

		// Determine TTL from Cache-Control / Expires.
		$ttl = self::DEFAULT_CACHE_TTL;

		$cache_control = isset( $response_headers['cache-control'] ) ? $response_headers['cache-control'] : '';
		if ( $cache_control && preg_match( '/max-age=(\d+)/i', $cache_control, $m ) ) {
			$ttl = max( 60, min( 86400, (int) $m[1] ) );
		} elseif ( ! empty( $response_headers['expires'] ) ) {
			$expires = strtotime( $response_headers['expires'] );
			if ( $expires > time() ) {
				$ttl = min( 86400, $expires - time() );
			}
		}

		// Don't cache no-store responses.
		if ( $cache_control && false !== strpos( $cache_control, 'no-store' ) ) {
			return;
		}

		$meta = array(
			'etag'          => isset( $response_headers['etag'] ) ? $response_headers['etag'] : '',
			'last_modified' => isset( $response_headers['last-modified'] ) ? $response_headers['last-modified'] : '',
			'headers'       => $response_headers,
		);

		set_transient( $cache_key, $result['body'], $ttl );
		set_transient( $cache_key . '_meta', $meta, $ttl );
	}
}
