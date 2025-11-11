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
	 * Handle HTTP requests to loopback and private network addresses.
	 *
	 * Prevents SSL verification errors when making requests to localhost, 127.0.0.1, ::1,
	 * or private network addresses (192.168.x.x, 10.x.x.x, 172.16-31.x.x).
	 * This is necessary because:
	 * 1. WordPress or plugins may force HTTPS for all requests
	 * 2. Local and private network addresses typically don't have valid SSL certificates
	 * 3. This causes "SSL certificate subject name mismatch" errors
	 *
	 * Supports all common local LLM and development server configurations:
	 * - Ollama on localhost: localhost:11434
	 * - Ollama on local network: 192.168.1.100:11434
	 * - LM Studio: localhost:1234 or 10.0.0.50:1234
	 * - Crawl4AI: localhost:8000
	 * - Any other local/private network address with any port
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array Modified request arguments.
	 */
	public static function handle_loopback_requests( $args, $url ) {
		$parsed_url = wp_parse_url( $url );

		// Check if this is a loopback or private network address.
		if ( ! empty( $parsed_url['host'] ) && self::is_loopback_address( $parsed_url['host'] ) ) {
			// Disable SSL verification for local/private addresses to prevent certificate mismatch errors.
			$args['sslverify'] = false;

			// Allow HTTP (non-SSL) requests to local/private addresses.
			// Some WordPress setups or plugins enforce HTTPS globally, which breaks local requests.
			$args['reject_unsafe_urls'] = false;
		}

		return $args;
	}

	/**
	 * Check if a host is a loopback/localhost or private network address.
	 *
	 * Detects loopback addresses in various formats:
	 * - IPv4: 127.0.0.1, 127.0.0.2, any 127.x.x.x
	 * - IPv6: ::1, 0:0:0:0:0:0:0:1, [::1]
	 * - Hostnames: localhost, localhost.localdomain, ip6-localhost, ip6-loopback
	 * - With ports: localhost:11434, 127.0.0.1:1234, [::1]:8000
	 *
	 * Also detects private network addresses (RFC 1918):
	 * - IPv4: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
	 * - IPv6: fc00::/7 (Unique Local Addresses)
	 *
	 * Common local LLM configurations are automatically supported:
	 * - Ollama on localhost: localhost:11434
	 * - Ollama on LAN: 192.168.1.100:11434
	 * - LM Studio: 10.0.0.50:1234
	 * - Crawl4AI: 172.16.0.10:8000
	 *
	 * @param string $host Host address to check (may include port).
	 * @return bool True if the host is a loopback or private network address, false otherwise.
	 */
	public static function is_loopback_address( $host ) {
		if ( empty( $host ) ) {
			return false;
		}

		// Normalize the host.
		$host = strtolower( trim( $host ) );

		// Remove port number if present (supports Ollama:11434, LM Studio:1234, etc.).
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

			// Check for private IPv4 addresses (RFC 1918).
			if ( self::is_private_ipv4_address( $host ) ) {
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

				// Check for private IPv6 addresses (Unique Local Addresses fc00::/7).
				if ( self::is_private_ipv6_address( $normalized ) ) {
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

	/**
	 * Check if an IPv4 address is a private network address (RFC 1918).
	 *
	 * Private IPv4 ranges:
	 * 10.0.0.0/8 (10.0.0.0 - 10.255.255.255)
	 * 172.16.0.0/12 (172.16.0.0 - 172.31.255.255)
	 * 192.168.0.0/16 (192.168.0.0 - 192.168.255.255)
	 *
	 * @param string $ip IPv4 address to check.
	 * @return bool True if the address is private, false otherwise.
	 */
	private static function is_private_ipv4_address( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		$parts = array_map( 'intval', explode( '.', $ip ) );

		// 10.0.0.0/8 (10.0.0.0 - 10.255.255.255).
		if ( 10 === $parts[0] ) {
			return true;
		}

		// 172.16.0.0/12 (172.16.0.0 - 172.31.255.255).
		if ( 172 === $parts[0] && $parts[1] >= 16 && $parts[1] <= 31 ) {
			return true;
		}

		// 192.168.0.0/16 (192.168.0.0 - 192.168.255.255).
		if ( 192 === $parts[0] && 168 === $parts[1] ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if an IPv6 address is a Unique Local Address (fc00::/7).
	 *
	 * Unique Local Addresses (ULA) are the IPv6 equivalent of private IPv4 addresses.
	 * Range: fc00::/7 (fc00:: - fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff)
	 *
	 * @param string $binary Binary representation from inet_pton().
	 * @return bool True if the address is a ULA, false otherwise.
	 */
	private static function is_private_ipv6_address( $binary ) {
		if ( false === $binary || strlen( $binary ) !== 16 ) {
			return false;
		}

		// Check first byte for fc00::/7 range (0xfc or 0xfd).
		$first_byte = ord( $binary[0] );

		return ( 0xfc === $first_byte || 0xfd === $first_byte );
	}
}
