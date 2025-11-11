<?php
/**
 * HTTP Helper for WP oOS
 *
 * Provides utilities for making HTTP requests with proper handling of
 * loopback addresses and SSL verification.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP Helper class for handling HTTP requests safely.
 */
class WP_MCP_AI_HTTP_Helper {

	/**
	 * Initialize the HTTP helper.
	 *
	 * Adds filters to prevent SSL verification issues with loopback addresses.
	 */
	public static function init() {
		// Add filter to handle loopback addresses properly.
		add_filter( 'http_request_args', array( __CLASS__, 'handle_loopback_requests' ), 10, 2 );
	}

	/**
	 * Handle HTTP requests to loopback addresses.
	 *
	 * Prevents SSL verification errors when making requests to localhost, 127.0.0.1, ::1, etc.
	 * This is necessary because:
	 * 1. WordPress or plugins may force HTTPS for all requests
	 * 2. Loopback addresses typically don't have valid SSL certificates
	 * 3. This causes "SSL certificate subject name mismatch" errors
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array Modified request arguments.
	 */
	public static function handle_loopback_requests( $args, $url ) {
		$parsed_url = wp_parse_url( $url );

		// Check if this is a loopback address.
		if ( ! empty( $parsed_url['host'] ) && self::is_loopback_address( $parsed_url['host'] ) ) {
			// Disable SSL verification for loopback addresses to prevent certificate mismatch errors.
			$args['sslverify'] = false;

			// Allow HTTP (non-SSL) requests to loopback addresses.
			// Some WordPress setups or plugins enforce HTTPS globally, which breaks local requests.
			$args['reject_unsafe_urls'] = false;
		}

		return $args;
	}

	/**
	 * Check if a host is a loopback/localhost address.
	 *
	 * @param string $host Host address to check.
	 * @return bool True if the host is a loopback address, false otherwise.
	 */
	public static function is_loopback_address( $host ) {
		if ( empty( $host ) ) {
			return false;
		}

		// Normalize the host.
		$host = strtolower( trim( $host ) );

		// Remove port number if present.
		if ( strpos( $host, ':' ) !== false ) {
			// For IPv6, only remove port after ].
			if ( strpos( $host, ']' ) !== false ) {
				$host = substr( $host, 0, strrpos( $host, ':' ) );
				$host = trim( $host, '[]' );
			} elseif ( substr_count( $host, ':' ) === 1 ) {
				// For IPv4 with port or simple hostname with port.
				$host = substr( $host, 0, strpos( $host, ':' ) );
			}
			// For IPv6 without port, leave as-is.
		}

		// Check for common localhost names.
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback' ), true ) ) {
			return true;
		}

		// Check for IPv4 loopback (127.0.0.0/8).
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $host );
			if ( isset( $parts[0] ) && '127' === $parts[0] ) {
				return true;
			}
		}

		// Check for IPv6 loopback (::1 and variations).
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// Normalize IPv6 address.
			$normalized = inet_pton( $host );
			if ( false !== $normalized ) {
				// Check if it's the loopback address.
				$loopback = inet_pton( '::1' );
				if ( $normalized === $loopback ) {
					return true;
				}
			}

			// Fallback string check for common representations.
			if ( in_array( $host, array( '::1', '0:0:0:0:0:0:0:1', '0000:0000:0000:0000:0000:0000:0000:0001' ), true ) ) {
				return true;
			}
		}

		return false;
	}
}
