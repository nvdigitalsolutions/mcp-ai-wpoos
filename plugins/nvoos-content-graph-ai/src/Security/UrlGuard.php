<?php
/**
 * URL Guard for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-url-guard.php` (behaviour-preserving;
 * base copy retained permanently — ecosystem port plan D-NOBASE). Blocked
 * CIDR ranges, blocked hostnames, error codes, and the three
 * `wp_mcp_ai_url_guard_*` / `wp_mcp_ai_http_allowed_host` filters keep
 * their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - The known-private-hostname shortcut delegates to the base
 *   `WP_MCP_AI_HTTP_Helper::is_loopback_address()` in monolith installs.
 *   Standalone installs skip the shortcut — the DNS-rebinding checks
 *   still block localhost/loopback via the 127.0.0.0/8 CIDR range after
 *   resolution.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates outbound URLs for SSRF risks.
 *
 * Usage:
 *   $check = UrlGuard::validate( $url );
 *   if ( is_wp_error( $check ) ) { return $check; }
 *
 * @since 1.1.0
 */
class UrlGuard {

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
	 * Filter: allow sites to whitelist specific hostnames.
	 */
	const FILTER_ALLOWED_HOSTS = 'wp_mcp_ai_http_allowed_host';

	/**
	 * Validate that a URL is safe for outbound HTTP requests.
	 *
	 * This is the single canonical SSRF chokepoint for the plugin. It:
	 *  1. Requires a valid http/https URL.
	 *  2. Blocks cloud metadata endpoints and operator-defined hostnames.
	 *  3. Blocks loopback/private/link-local/multicast IPv4 ranges (CIDR).
	 *  4. Blocks IPv6 loopback, link-local, and unique-local addresses.
	 *  5. Resolves ALL A records for a hostname and rejects if any resolve
	 *     to a blocked address (DNS-rebinding defence).
	 *
	 * Operators may whitelist specific hostnames via the
	 * `wp_mcp_ai_http_allowed_host` filter.
	 *
	 * @param string $url The URL to validate.
	 * @return true|WP_Error True if safe, WP_Error with details if blocked.
	 */
	public static function validate( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return new \WP_Error(
				'url_guard_invalid_url',
				__( 'Invalid URL provided.', 'nvoos-content-graph-ai' )
			);
		}

		// Require a valid URL with http or https scheme.
		$sanitized = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( ! $sanitized ) {
			return new \WP_Error(
				'url_guard_invalid_scheme',
				__( 'Only HTTP and HTTPS URLs are allowed.', 'nvoos-content-graph-ai' )
			);
		}

		$parsed = wp_parse_url( $sanitized );

		if ( false === $parsed || empty( $parsed['host'] ) ) {
			return new \WP_Error(
				'url_guard_unparseable',
				__( 'URL could not be parsed.', 'nvoos-content-graph-ai' )
			);
		}

		$host = strtolower( $parsed['host'] );

		// Operator whitelist — checked before any blocking.
		$allowed_hosts = (array) apply_filters( self::FILTER_ALLOWED_HOSTS, array(), $host, $url );
		if ( in_array( $host, $allowed_hosts, true ) ) {
			return true;
		}

		// Check blocked hostnames first (fast, no DNS lookup).
		$hostname_check = self::check_blocked_hostnames( $host );
		if ( is_wp_error( $hostname_check ) ) {
			return $hostname_check;
		}

		// Reject known-private hostnames without a DNS lookup.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_HTTP_Helper' ) && \WP_MCP_AI_HTTP_Helper::is_loopback_address( $host ) ) {
			return new \WP_Error(
				'url_guard_blocked_hostname',
				sprintf(
					/* translators: %s: hostname */
					__( 'Connection to %s is blocked for security reasons.', 'nvoos-content-graph-ai' ),
					esc_html( $host )
				)
			);
		}

		// Resolve hostname to IP(s).
		$ips = self::resolve_host_all( $host );
		if ( false === $ips || empty( $ips ) ) {
			// Use a generic message so the response cannot be used as an
			// internal-hostname enumeration oracle. The hostname is only
			// exposed in the error data when WP_DEBUG is enabled.
			$error_data = array( 'reason' => 'dns_failed' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$error_data['host'] = $host;
			}

			return new \WP_Error(
				'url_guard_dns_failed',
				__( 'The URL could not be validated.', 'nvoos-content-graph-ai' ),
				$error_data
			);
		}

		// Check EVERY resolved IP — a host is blocked if any of its
		// records point at a blocked address (DNS-rebinding defence).
		foreach ( $ips as $ip ) {
			$ip_check = self::check_blocked_ip( $ip );
			if ( is_wp_error( $ip_check ) ) {
				return $ip_check;
			}
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
			if ( strtolower( $blocked_host ) === $normalized ) {
				return new \WP_Error(
					'url_guard_blocked_hostname',
					sprintf(
						/* translators: %s: hostname */
						__( 'Connection to %s is blocked for security reasons.', 'nvoos-content-graph-ai' ),
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
		// IPv6 addresses have their own block logic.
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::check_blocked_ipv6( $ip );
		}

		$blocked = apply_filters(
			self::FILTER_BLOCKED_RANGES,
			self::BLOCKED_IPV4_RANGES
		);

		foreach ( $blocked as $cidr ) {
			if ( self::cidr_match( $ip, $cidr ) ) {
				return new \WP_Error(
					'url_guard_blocked_ip',
					sprintf(
						/* translators: %1$s: IP, %2$s: CIDR range */
						__( 'IP address %1$s is in a blocked range (%2$s).', 'nvoos-content-graph-ai' ),
						esc_html( $ip ),
						esc_html( $cidr )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Resolve a hostname to all of its IP addresses.
	 *
	 * Returns every A record via gethostbynamel() so DNS-rebinding to a
	 * private IP on a secondary record is also caught. Literal IPv4/IPv6
	 * hosts are returned as single-element arrays.
	 *
	 * @param string $host Hostname.
	 * @return array<string>|false Array of IPs or false on failure.
	 */
	private static function resolve_host_all( $host ) {
		// Quick check: is it already an IPv4 literal?
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return array( $host );
		}

		// IPv6 literal (strip brackets wp_parse_url may leave in place).
		$unbracketed = trim( $host, '[]' );
		if ( false !== filter_var( $unbracketed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return array( $unbracketed );
		}

		$ips = gethostbynamel( $host );

		// gethostbynamel returns false on failure.
		if ( false === $ips || empty( $ips ) ) {
			return false;
		}

		return $ips;
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
			return new \WP_Error( 'url_guard_blocked_ipv6_loopback', __( 'IPv6 loopback addresses are blocked.', 'nvoos-content-graph-ai' ) );
		}

		// Link-local (fe80::/10).
		if ( 0 === strpos( $normalized, 'fe8' ) || 0 === strpos( $normalized, 'fe9' ) || 0 === strpos( $normalized, 'fea' ) || 0 === strpos( $normalized, 'feb' ) ) {
			return new \WP_Error( 'url_guard_blocked_ipv6_link_local', __( 'IPv6 link-local addresses are blocked.', 'nvoos-content-graph-ai' ) );
		}

		// Unique local (fc00::/7).
		if ( 0 === strpos( $normalized, 'fc' ) || 0 === strpos( $normalized, 'fd' ) ) {
			return new \WP_Error( 'url_guard_blocked_ipv6_ula', __( 'IPv6 unique local addresses are blocked.', 'nvoos-content-graph-ai' ) );
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
		$mask                  = (int) $mask;

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
