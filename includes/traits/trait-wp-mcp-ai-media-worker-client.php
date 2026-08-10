<?php
/**
 * Media Worker HTTP Client Trait
 *
 * Provides a shared HTTP client for communicating with the optional
 * Design Stack Media Worker sidecar. When the sidecar is available,
 * heavy NPM-package-dependent operations are offloaded via HTTP.
 * When unavailable, the existing filter-based and node-services/
 * subprocess mechanisms continue to work unchanged.
 *
 * This trait is designed to be mixed into service classes that currently
 * depend on NPM packages (Prettier, FFmpeg, MJML, OCR, Nodemailer, etc.)
 * to add a zero-config, opt-in sidecar acceleration layer.
 *
 * ## Usage (in a service class):
 *
 * ```php
 * class WP_MCP_AI_Prettier_Service {
 *     use WP_MCP_AI_Media_Worker_Client;
 *
 *     public function format_code( $code, $options = [] ) {
 *         // 1. Try existing filter (backward compatibility)
 *         $result = apply_filters( 'wp_mcp_ai_prettier_format_code', false, $params );
 *         if ( false !== $result ) return $result;
 *
 *         // 2. Try media-worker sidecar (NEW -- zero config if Docker)
 *         $result = $this->sidecar_request( '/api/code/format', $params );
 *         if ( ! is_wp_error( $result ) ) return $result;
 *
 *         // 3. Fall back to local Node.js (existing behavior)
 *         if ( $this->is_available() ) return $this->execute_locally( $params );
 *
 *         // 4. Ultimate fallback
 *         return new WP_Error( 501, 'Configure Node.js or the Media Worker sidecar.' );
 *     }
 * }
 * ```
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait WP_MCP_AI_Media_Worker_Client {

	/**
	 * Cached sidecar availability flag.
	 *
	 * @var bool|null
	 */
	private $sidecar_available = null;

	/**
	 * Send a request to the Media Worker sidecar.
	 *
	 * Returns a WP_Error if the sidecar is unavailable, the request fails,
	 * or the response indicates an error. Returns the decoded JSON body
	 * on success.
	 *
	 * @param string $endpoint API path (e.g., '/api/code/format').
	 * @param array  $body     Request payload.
	 * @param array  $options  Optional overrides.
	 *                         - timeout: int (default: 30 for sync, 60 for async)
	 *                         - method: string (default: 'POST').
	 * @return array|WP_Error Decoded response body or error.
	 */
	protected function sidecar_request( $endpoint, array $body = array(), array $options = array() ) {
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30;
		$method  = isset( $options['method'] ) ? strtoupper( $options['method'] ) : 'POST';

		$request_url = rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Site-Token' => $this->get_sidecar_token(),
				'X-Site-Url'   => home_url(),
			),
		);

		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		/**
		 * Filter: modify the sidecar request arguments before sending.
		 *
		 * @param array  $args     HTTP request args (method, timeout, headers, body).
		 * @param string $endpoint The API endpoint path.
		 * @param array  $body     The request payload.
		 */
		$args = apply_filters( 'wp_mcp_ai_sidecar_request_args', $args, $endpoint, $body );

		$response = wp_remote_request( $request_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->sidecar_available = false;
			return $response;
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( 200 !== $status && 202 !== $status ) {
			$error_msg = isset( $decoded['error'] )
				? $decoded['error']
				: sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 200 ) );

			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				$error_msg,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		$this->sidecar_available = true;

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_invalid_json',
				__( 'Media Worker returned invalid JSON.', 'mcp-ai-wpoos' )
			);
		}

		return $decoded;
	}

	/**
	 * Check whether the Media Worker sidecar is reachable.
	 *
	 * Caches the result for the duration of the request to avoid
	 * repeated HTTP calls. Returns false if the URL is not configured.
	 *
	 * @return bool True if the sidecar responded to /api/health.
	 */
	protected function is_sidecar_available() {
		if ( null !== $this->sidecar_available ) {
			return $this->sidecar_available;
		}

		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			$this->sidecar_available = false;
			return false;
		}

		$response = wp_remote_get(
			rtrim( $url, '/' ) . '/api/health',
			array( 'timeout' => 3 )
		);

		if ( is_wp_error( $response ) ) {
			$this->sidecar_available = false;
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->sidecar_available = ( 200 === $status && isset( $body['status'] ) && 'ok' === $body['status'] );
		return $this->sidecar_available;
	}

	/**
	 * Get the sidecar URL from the WordPress constant or option.
	 *
	 * Priority:
	 *   1. WP_MEDIA_WORKER_URL constant (set in wp-config.php for Docker)
	 *   2. wp_mcp_ai_media_worker_url option (set via admin UI)
	 *   3. Empty string (sidecar disabled)
	 *
	 * @return string Sidecar base URL or empty string.
	 */
	protected function get_sidecar_url() {
		if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
			return rtrim( WP_MEDIA_WORKER_URL, '/' );
		}

		$option = get_option( 'wp_mcp_ai_media_worker_url', '' );
		return $option ? rtrim( $option, '/' ) : '';
	}

	/**
	 * Get a lightweight site token for sidecar authentication.
	 *
	 * Defaults to a hash of home_url() via wp_hash(), which internally uses
	 * WordPress auth salts (AUTH_KEY / SECURE_AUTH_KEY). If salts are rotated,
	 * the default token will change and must be re-synced with the sidecar.
	 * Set the wp_mcp_ai_media_worker_token option to a stable shared secret
	 * to avoid this coupling.
	 *
	 * @return string Token string.
	 */
	protected function get_sidecar_token() {
		$token = get_option( 'wp_mcp_ai_media_worker_token', '' );
		if ( ! empty( $token ) ) {
			return $token;
		}

		return wp_hash( home_url() );
	}
}
