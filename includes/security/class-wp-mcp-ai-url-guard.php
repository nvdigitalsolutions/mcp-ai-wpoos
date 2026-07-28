<?php
/**
 * URL Guard — SSRF protection for outbound HTTP requests.
 *
 * Validates URLs before the plugin makes outbound HTTP connections (MCP server
 * probing, webhook delivery, remote site queries). Blocks private/loopback
 * IPs, cloud metadata endpoints, and internal network ranges.
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

if ( ! class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
	/**
	 * Validates outbound URLs for SSRF risks.
	 *
	 * Usage:
	 *   $check = WP_MCP_AI_Url_Guard::validate( $url );
	 *   if ( is_wp_error( $check ) ) { return $check; }
	 */
	class WP_MCP_AI_Url_Guard {

		/**
		 * Private IPv4 ranges blocked by this guard (CIDR notation).
		 *
		 * @var array<string>
		 */
		const BLOCKED_IPV4_RANGES = array(
			'10.0.0.0/8',       // Private (RFC 1918).
			'172.16.0.0/12',    // Private (RFC 1918).
			'192.168.0.0/16',   // Private (RFC 1918).
			'127.0.0.0/8',      // Loopback.
			'169.254.0.0/16',   // Link-local (AWS metadata, etc.).
			'0.0.0.0/8',        // Current network.
			'100.64.0.0/10',    // Carrier-grade NAT (RFC 6598).
			'198.18.0.0/15',    // Benchmarking (RFC 2544).
			'224.0.0.0/4',      // Multicast.
			'240.0.0.0/4',      // Reserved.
		);

		/**
		 * Blocked hostnames (cloud metadata services, etc.).
		 *
		 * @var array<string>
		 */
		const BLOCKED_HOSTNAMES = array(
			'169.254.169.254',              // AWS / GCP / Azure metadata.
			'metadata.google.internal',     // GCP metadata (host header variant).
			'instance-data.ec2.internal',   // AWS IMDSv1 fallback.
			'metadata.azure.internal',      // Azure metadata.
		);

		/**
		 * Filter: allow sites to customize blocked ranges.
		 */
		const FILTER_BLOCKED_RANGES = 'wp_mcp_ai_url_guard_blocked_ranges';

		/**
		 * Filter: allow sites to customize blocked hostnames.
		 */
		const FILTER_BLOCKED_HOSTNAMES = 'wp_mcp_ai_url_guard_blocked_hostnames';

		/**
		 * Validate that a URL is safe for outbound HTTP requests.
		 *
		 * @param string $url The URL to validate.
		 * @return true|WP_Error True if safe, WP_Error with details if blocked.
		 */
		public static function validate( $url ) {
			if ( empty( $url ) || ! is_string( $url ) ) {
				return new WP_Error(
					'url_guard_invalid_url',
					__( 'Invalid URL provided.', 'mcp-ai-wpoos' )
				);
			}

			$parsed = wp_parse_url( $url );

			if ( false === $parsed || empty( $parsed['host'] ) ) {
				return new WP_Error(
					'url_guard_unparseable',
					__( 'URL could not be parsed.', 'mcp-ai-wpoos' )
				);
			}

			$host = $parsed['host'];

			// Check blocked hostnames first (fast, no DNS lookup).
			$hostname_check = self::check_blocked_hostnames( $host );
			if ( is_wp_error( $hostname_check ) ) {
				return $hostname_check;
			}

			// Resolve hostname to IP.
			$ip = self::resolve_host( $host );
			if ( false === $ip ) {
				return new WP_Error(
					'url_guard_dns_failed',
					sprintf(
						/* translators: %s: hostname */
						__( 'Could not resolve hostname: %s', 'mcp-ai-wpoos' ),
						esc_html( $host )
					)
				);
			}

			// Check IP against blocked ranges.
			$ip_check = self::check_blocked_ip( $ip );
			if ( is_wp_error( $ip_check ) ) {
				return $ip_check;
			}

			return true;
		}

		/**
		 * Check whether a hostname matches any blocked entry.
		 *
		 * @param string $host Hostname from the URL.
		 * @return true|WP_Error
		 */
		private static function check_blocked_hostnames( $host ) {
			$blocked = apply_filters(
				self::FILTER_BLOCKED_HOSTNAMES,
				self::BLOCKED_HOSTNAMES
			);

			$normalized = strtolower( trim( $host ) );

			foreach ( $blocked as $blocked_host ) {
				if ( $normalized === strtolower( $blocked_host ) ) {
					return new WP_Error(
						'url_guard_blocked_hostname',
						sprintf(
							/* translators: %s: hostname */
							__( 'Connection to %s is blocked for security reasons.', 'mcp-ai-wpoos' ),
							esc_html( $host )
						)
					);
				}
			}

			return true;
		}

		/**
		 * Check whether an IP address falls within any blocked CIDR range.
		 *
		 * @param string $ip Resolved IP address.
		 * @return true|WP_Error
		 */
		private static function check_blocked_ip( $ip ) {
			$blocked = apply_filters(
				self::FILTER_BLOCKED_RANGES,
				self::BLOCKED_IPV4_RANGES
			);

			foreach ( $blocked as $cidr ) {
				if ( self::cidr_match( $ip, $cidr ) ) {
					return new WP_Error(
						'url_guard_blocked_ip',
						sprintf(
							/* translators: %1$s: IP, %2$s: CIDR range */
							__( 'IP address %1$s is in a blocked range (%2$s).', 'mcp-ai-wpoos' ),
							esc_html( $ip ),
							esc_html( $cidr )
						)
					);
				}
			}

			return true;
		}

		/**
		 * Resolve a hostname to an IPv4 address.
		 *
		 * Uses gethostbyname() which returns the unmodified hostname on failure.
		 * We handle both the failure case and IPv6-only hosts.
		 *
		 * @param string $host Hostname.
		 * @return string|false IP address or false on failure.
		 */
		private static function resolve_host( $host ) {
			// Quick check: is it already an IP?
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $host;
			}

			// If it's an IPv6 address, try to map to IPv4.
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				// IPv6 loopback or link-local — blocked.
				$ipv6_check = self::check_blocked_ipv6( $host );
				if ( is_wp_error( $ipv6_check ) ) {
					return false;
				}
				return $host;
			}

			$ip = gethostbyname( $host );

			// gethostbyname returns the hostname unchanged on failure.
			if ( $ip === $host ) {
				return false;
			}

			return $ip;
		}

		/**
		 * Check whether an IPv6 address is blocked.
		 *
		 * @param string $ip IPv6 address.
		 * @return true|WP_Error
		 */
		private static function check_blocked_ipv6( $ip ) {
			$normalized = strtolower( $ip );

			// Loopback.
			if ( '::1' === $normalized ) {
				return new WP_Error( 'url_guard_blocked_ipv6_loopback', __( 'IPv6 loopback addresses are blocked.', 'mcp-ai-wpoos' ) );
			}

			// Link-local (fe80::/10).
			if ( 0 === strpos( $normalized, 'fe8' ) || 0 === strpos( $normalized, 'fe9' ) || 0 === strpos( $normalized, 'fea' ) || 0 === strpos( $normalized, 'feb' ) ) {
				return new WP_Error( 'url_guard_blocked_ipv6_link_local', __( 'IPv6 link-local addresses are blocked.', 'mcp-ai-wpoos' ) );
			}

			// Unique local (fc00::/7).
			if ( 0 === strpos( $normalized, 'fc' ) || 0 === strpos( $normalized, 'fd' ) ) {
				return new WP_Error( 'url_guard_blocked_ipv6_ula', __( 'IPv6 unique local addresses are blocked.', 'mcp-ai-wpoos' ) );
			}

			return true;
		}

		/**
		 * Check if an IPv4 address falls within a CIDR range.
		 *
		 * @param string $ip   IPv4 address (e.g. '192.168.1.1').
		 * @param string $cidr CIDR range (e.g. '192.168.0.0/16').
		 * @return bool True if the IP matches the range.
		 */
		private static function cidr_match( $ip, $cidr ) {
			list( $subnet, $mask ) = explode( '/', $cidr, 2 );
			$mask = (int) $mask;

			if ( $mask < 0 || $mask > 32 ) {
				return false;
			}

			$ip_long     = ip2long( $ip );
			$subnet_long = ip2long( $subnet );

			if ( false === $ip_long || false === $subnet_long ) {
				return false;
			}

			// Create the netmask and apply it.
			$netmask = -1 << ( 32 - $mask );

			return ( $ip_long & $netmask ) === ( $subnet_long & $netmask );
		}
	}
}
