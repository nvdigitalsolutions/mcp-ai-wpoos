<?php
/**
 * Media Worker configuration helper.
 *
 * Single source of truth for resolving the sidecar URL and per-site token
 * (the chain previously duplicated between the sidecar client trait and the
 * usage reporter). Priority is unchanged and behavior-preserving:
 *
 *   URL:   WP_MEDIA_WORKER_URL constant -> wp_mcp_ai_media_worker_url option
 *   Token: WP_MEDIA_WORKER_TOKEN constant -> per-blog option (multisite)
 *          -> wp_mcp_ai_media_worker_token option -> wp_hash( home_url() )
 *
 * @package   WP_MCP_AI
 * @since     1.1.93
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Worker configuration helper class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Media_Worker_Config {

	/**
	 * Resolve the sidecar base URL (constant, then option).
	 *
	 * @since 1.1.93
	 *
	 * @return string Sidecar base URL without a trailing slash, or '' when unset.
	 */
	public static function get_url() {
		if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
			return rtrim( (string) WP_MEDIA_WORKER_URL, '/' );
		}

		$option = get_option( 'wp_mcp_ai_media_worker_url', '' );
		return $option ? rtrim( (string) $option, '/' ) : '';
	}

	/**
	 * Resolve the sidecar auth token.
	 *
	 * Chain: constant -> per-blog option (multisite only) -> site option ->
	 * wp_hash( home_url() ) fallback derived from the WordPress auth salts.
	 *
	 * @since 1.1.93
	 *
	 * @return string Token string (never empty — the salted fallback guarantees one).
	 */
	public static function get_token() {
		if ( defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN ) {
			return (string) WP_MEDIA_WORKER_TOKEN;
		}

		if ( is_multisite() ) {
			$blog_token = get_option( 'wp_mcp_ai_media_worker_token_' . get_current_blog_id(), '' );
			if ( ! empty( $blog_token ) ) {
				return (string) $blog_token;
			}
		}

		$token = get_option( 'wp_mcp_ai_media_worker_token', '' );
		if ( ! empty( $token ) ) {
			return (string) $token;
		}

		return wp_hash( home_url() );
	}

	/**
	 * Whether a sidecar URL is configured (the token always resolves via the
	 * salted fallback, so the URL is the effective on/off switch).
	 *
	 * @since 1.1.93
	 *
	 * @return bool True when a worker URL is configured.
	 */
	public static function is_configured() {
		return '' !== self::get_url();
	}

	/**
	 * Perform an authenticated request against the sidecar.
	 *
	 * Attaches the X-Site-Token and X-Site-Url headers the worker's auth
	 * middleware expects. Returns the decoded JSON body on success.
	 *
	 * @since 1.1.93
	 *
	 * @param string $endpoint API path relative to the worker root (leading slash required).
	 * @param string $method   HTTP method (GET or POST).
	 * @param array  $body     Optional JSON body for POST requests.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array|WP_Error Decoded response array, or WP_Error on failure.
	 */
	public static function request( $endpoint, $method = 'GET', array $body = array(), $timeout = 5 ) {
		$url = self::get_url();
		if ( '' === $url ) {
			return new WP_Error( 'wp_mcp_ai_worker_not_configured', __( 'The Media Worker URL is not configured.', 'mcp-ai-wpoos' ) );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => max( 1, absint( $timeout ) ),
			'headers' => array(
				'X-Site-Token' => self::get_token(),
				'X-Site-Url'   => home_url(),
			),
		);

		if ( 'POST' === strtoupper( $method ) && ! empty( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_worker_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The Media Worker responded with HTTP %d.', 'mcp-ai-wpoos' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_worker_bad_response', __( 'The Media Worker returned an unreadable response.', 'mcp-ai-wpoos' ) );
		}

		return $decoded;
	}
}
