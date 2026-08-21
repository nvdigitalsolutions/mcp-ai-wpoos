<?php
/**
 * IETF RateLimit response headers for chat rate limiting.
 *
 * Attaches draft-ietf-httpapi-ratelimit-headers fields (RateLimit-Policy /
 * RateLimit) plus Retry-After to plugin REST responses that carry a
 * rate-limited error, so well-behaved clients can adapt their retry policy
 * instead of hammering the endpoint.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds rate-limit response headers on the REST layer.
 *
 * The header values mirror the wp_mcp_ai_chat_rate_limit /
 * wp_mcp_ai_chat_rate_limit_window filters applied in the OOS bridge so
 * advertised limits always match enforced limits.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Rate_Limit_Headers {

	/**
	 * Register the rest_post_dispatch hook.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_headers' ), 10, 3 );
	}

	/**
	 * Add RateLimit/RateLimit-Policy/Retry-After headers when the response
	 * signals a rate limit.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Response|WP_Error $response REST response.
	 * @param WP_REST_Server            $server   REST server instance.
	 * @param WP_REST_Request           $request  Current request.
	 * @return WP_REST_Response|WP_Error Unchanged response (headers attached when applicable).
	 */
	public static function add_headers( $response, $server, $request ) {
		unset( $server );

		// Only plugin routes.
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/mcp-ai/' ) && 0 !== strpos( $route, '/nvoos-' ) ) {
			return $response;
		}

		if ( ! $response instanceof WP_REST_Response && ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}

		$retry_after = self::detect_retry_after( $response );
		if ( null === $retry_after ) {
			return $response;
		}

		$max_requests = max( 1, (int) apply_filters( 'wp_mcp_ai_chat_rate_limit', 60 ) );
		$window       = max( 1, (int) apply_filters( 'wp_mcp_ai_chat_rate_limit_window', 60 ) );

		$response->header( 'RateLimit-Policy', 'quota;q=' . $max_requests . ';w=' . $window );
		$response->header( 'RateLimit', 'quota;r=0;t=' . $retry_after );
		$response->header( 'Retry-After', (string) $retry_after );

		return $response;
	}

	/**
	 * Detect whether a response signals a rate limit and extract the
	 * retry-after seconds.
	 *
	 * Covers: HTTP 429 status; normalized error arrays carrying a
	 * rate_limited/rate_limit_exceeded code; raw WP_Error objects and their
	 * JSON envelope shape.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Response|WP_HTTP_Response $response REST response.
	 * @return int|null Retry-after seconds, or null when not rate limited.
	 */
	private static function detect_retry_after( $response ) {
		if ( 429 === (int) $response->get_status() ) {
			$headers = $response->get_headers();
			$retry   = isset( $headers['Retry-After'] ) ? $headers['Retry-After'] : ( isset( $headers['retry-after'] ) ? $headers['retry-after'] : 60 );
			if ( is_array( $retry ) ) {
				$retry = reset( $retry );
			}
			return max( 0, absint( $retry ) );
		}

		$data      = $response->get_data();
		$envelopes = is_array( $data ) ? array( $data, isset( $data['data'] ) ? $data['data'] : null, isset( $data['response'] ) ? $data['response'] : null ) : array( $data );

		foreach ( $envelopes as $envelope ) {
			if ( $envelope instanceof WP_Error ) {
				$error_data = $envelope->get_error_data();
				$retry      = is_array( $error_data ) && isset( $error_data['retry_after'] ) ? $error_data['retry_after'] : 60;
				return max( 0, absint( $retry ) );
			}

			if ( ! is_array( $envelope ) ) {
				continue;
			}

			// Normalized error shapes: a top-level code key, or an error
			// object carrying code and data with the retry-after value.
			$code = isset( $envelope['code'] ) ? $envelope['code'] : ( isset( $envelope['error']['code'] ) ? $envelope['error']['code'] : '' );
			if ( 'rate_limited' === $code || 'rate_limit_exceeded' === $code ) {
				$retry = isset( $envelope['data']['retry_after'] ) ? $envelope['data']['retry_after'] : ( isset( $envelope['error']['data']['retry_after'] ) ? $envelope['error']['data']['retry_after'] : 60 );
				return max( 0, absint( $retry ) );
			}

			// Raw WP_Error JSON envelopes expose the code under the errors
			// key with retry-after details under error_data.
			if ( isset( $envelope['errors'] ) && is_array( $envelope['errors'] ) ) {
				foreach ( array_keys( $envelope['errors'] ) as $error_code ) {
					if ( 'rate_limited' === $error_code || 'rate_limit_exceeded' === $error_code ) {
						$retry = isset( $envelope['error_data'][ $error_code ]['retry_after'] ) ? $envelope['error_data'][ $error_code ]['retry_after'] : 60;
						return max( 0, absint( $retry ) );
					}
				}
			}
		}

		return null;
	}
}
