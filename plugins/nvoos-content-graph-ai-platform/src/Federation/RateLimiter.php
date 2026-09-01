<?php
/**
 * Rate Limiter for Federation Directory REST API endpoints.
 *
 * Prevents enumeration attacks on public peer discovery endpoints.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/class-wp-mcp-ai-federation-rate-limiter.php (since 1.1.1).
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Federation Rate Limiter Class
 *
 * Implements IP-based rate limiting using WordPress transients
 * specifically for public Federation Directory endpoints.
 */
class RateLimiter {

	/**
	 * Default rate limit: 60 requests per minute.
	 */
	const DEFAULT_LIMIT = 60;

	/**
	 * Default time window in seconds.
	 */
	const DEFAULT_WINDOW = 60;

	/**
	 * Check if request should be rate limited.
	 *
	 * @param string $endpoint The endpoint being accessed.
	 * @param int    $limit    Request limit per window (default 60).
	 * @param int    $window   Time window in seconds (default 60).
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit( $endpoint, $limit = self::DEFAULT_LIMIT, $window = self::DEFAULT_WINDOW ) {
		// Allow admins to bypass rate limiting. The fleet-management helper
		// is a base-plugin function; in standalone mode fall back to the
		// manage_options capability.
		if ( function_exists( 'wp_mcp_ai_user_can_manage_fleet' ) ) {
			if ( wp_mcp_ai_user_can_manage_fleet() ) {
				return true;
			}
		} elseif ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Get client IP address.
		$ip = $this->get_client_ip();

		// Create unique transient key.
		$transient_key = 'wp_mcp_ai_fed_rate_limit_' . md5( $endpoint . '_' . $ip );

		// Get current request count.
		$requests = get_transient( $transient_key );

		if ( false === $requests ) {
			// First request in this window.
			set_transient( $transient_key, 1, $window );
			return true;
		}

		if ( $requests >= $limit ) {
			// Rate limit exceeded.
			return new \WP_Error(
				'wp_mcp_ai_rate_limit_exceeded',
				sprintf(
					/* translators: %1$d: rate limit, %2$d: time window in seconds */
					__( 'Rate limit exceeded. You are limited to %1$d requests per %2$d seconds. Please try again later.', 'nvoos-content-graph-ai-platform' ),
					$limit,
					$window
				),
				array(
					'status'        => 429,
					'retry_after'   => $window,
					'limit'         => $limit,
					'window'        => $window,
					'requests_made' => $requests,
				)
			);
		}

		// Increment request count.
		set_transient( $transient_key, $requests + 1, $window );

		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		// Check for proxy headers.
		$ip_headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Standard proxy header.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $ip_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

				// Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip_list = explode( ',', $ip );
					$ip      = trim( $ip_list[0] );
				}

				// Validate IP address.
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0'; // Fallback.
	}

	/**
	 * Add rate limit headers to response.
	 *
	 * @param \WP_REST_Response $response REST response object.
	 * @param string            $endpoint Endpoint being accessed.
	 * @param int               $limit    Rate limit.
	 * @param int               $window   Time window.
	 * @return \WP_REST_Response Modified response with rate limit headers.
	 */
	public function add_rate_limit_headers( $response, $endpoint, $limit, $window ) {
		$ip            = $this->get_client_ip();
		$transient_key = 'wp_mcp_ai_fed_rate_limit_' . md5( $endpoint . '_' . $ip );
		$requests      = get_transient( $transient_key );
		$requests      = ( false === $requests ) ? 0 : $requests;
		$remaining     = max( 0, $limit - $requests );

		$response->header( 'X-RateLimit-Limit', (string) $limit );
		$response->header( 'X-RateLimit-Remaining', (string) $remaining );
		$response->header( 'X-RateLimit-Reset', (string) ( time() + $window ) );

		return $response;
	}
}
