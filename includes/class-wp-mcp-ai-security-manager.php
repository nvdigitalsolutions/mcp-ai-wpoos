<?php
/**
 * Security Manager
 *
 * Centralized security control logic for the plugin.
 * Implements security checks based on settings from the Security tab.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Manager class
 *
 * Provides centralized security control methods for:
 * - Authentication requirements
 * - IP filtering (whitelist/blacklist)
 * - HTTPS enforcement
 * - Role-based access control
 * - Security audit logging
 * - Security headers
 */
class WP_MCP_AI_Security_Manager {

	/**
	 * Settings repository instance
	 *
	 * @var WP_MCP_AI_Settings_Repository
	 */
	private $settings;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->settings = wp_mcp_ai_get_settings_repository();
	}

	/**
	 * Check if global authentication is required.
	 *
	 * @return bool True if authentication is required for all access.
	 */
	public function is_global_auth_required() {
		return (bool) $this->settings->get( 'require_authentication_all', false );
	}

	/**
	 * Check if guest access is allowed.
	 *
	 * @return bool True if guest tokens are permitted.
	 */
	public function is_guest_access_allowed() {
		return (bool) $this->settings->get( 'allow_guest_access', true );
	}

	/**
	 * Check if logged-in users bypass authentication checks.
	 *
	 * @return bool True if logged-in users automatically get access.
	 */
	public function should_bypass_for_logged_in() {
		return (bool) $this->settings->get( 'bypass_auth_for_logged_in', true );
	}

	/**
	 * Check if authentication is required for a specific endpoint type.
	 *
	 * @param string $endpoint_type Endpoint type: 'chat', 'tool', 'assistant', 'transcript', 'file'.
	 * @return bool True if authentication is required.
	 */
	public function is_auth_required_for_endpoint( $endpoint_type ) {
		// If global auth is required, always return true.
		if ( $this->is_global_auth_required() ) {
			return true;
		}

		// Check endpoint-specific settings.
		$setting_map = array(
			'chat'       => 'require_auth_chat_endpoints',
			'tool'       => 'require_auth_tool_execution',
			'assistant'  => 'require_auth_assistant_management',
			'transcript' => 'require_auth_transcripts',
			'file'       => 'require_auth_file_operations',
		);

		if ( isset( $setting_map[ $endpoint_type ] ) ) {
			return (bool) $this->settings->get( $setting_map[ $endpoint_type ], false );
		}

		return false;
	}

	/**
	 * Check if media URL protection is enabled.
	 *
	 * @return bool True if direct media URLs require authentication.
	 */
	public function is_media_protection_enabled() {
		return (bool) $this->settings->get( 'protect_media_urls', false );
	}

	/**
	 * Check if attachment page protection is enabled.
	 *
	 * @return bool True if attachment pages require authentication.
	 */
	public function is_attachment_protection_enabled() {
		return (bool) $this->settings->get( 'protect_attachment_pages', false );
	}

	/**
	 * Check if public thumbnails are allowed.
	 *
	 * @return bool True if thumbnails can be accessed without authentication.
	 */
	public function are_public_thumbnails_allowed() {
		return (bool) $this->settings->get( 'allow_public_thumbnails', true );
	}

	/**
	 * Get list of protected file extensions.
	 *
	 * @return array Array of file extensions to protect (without dots).
	 */
	public function get_protected_file_extensions() {
		$extensions = $this->settings->get( 'protected_file_extensions', '' );

		if ( empty( $extensions ) ) {
			return array(); // Empty means protect all files.
		}

		// Parse comma-separated list.
		$extensions = array_map( 'trim', explode( ',', $extensions ) );
		$extensions = array_map( 'strtolower', $extensions );
		$extensions = array_filter( $extensions );

		return $extensions;
	}

	/**
	 * Check if a user has access based on role restrictions.
	 *
	 * @param int $user_id User ID to check.
	 * @return bool True if user has access based on role restrictions.
	 */
	public function check_role_access( $user_id ) {
		// Get restricted roles.
		$restricted_roles = $this->settings->get( 'restrict_to_roles', array() );

		// If no restrictions, allow access.
		if ( empty( $restricted_roles ) || ! is_array( $restricted_roles ) ) {
			return true;
		}

		// Check if user has one of the allowed roles.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$user_roles = (array) $user->roles;
		$has_role   = ! empty( array_intersect( $user_roles, $restricted_roles ) );

		return $has_role;
	}

	/**
	 * Check if a user meets the minimum capability requirement.
	 *
	 * @param int $user_id User ID to check.
	 * @return bool True if user meets capability requirement.
	 */
	public function check_capability_requirement( $user_id ) {
		$min_capability = $this->settings->get( 'minimum_capability', '' );

		// If no requirement, allow access.
		if ( empty( $min_capability ) ) {
			return true;
		}

		return user_can( $user_id, $min_capability );
	}

	/**
	 * Check if an IP address is allowed based on whitelist/blacklist.
	 *
	 * @param string $ip IP address to check.
	 * @return bool|WP_Error True if allowed, WP_Error if blocked.
	 */
	public function check_ip_access( $ip ) {
		// Check blacklist first (explicit deny).
		if ( $this->settings->get( 'enable_ip_blacklist', false ) ) {
			$blacklist = $this->get_ip_list( 'ip_blacklist' );
			if ( $this->ip_in_list( $ip, $blacklist ) ) {
				return new WP_Error(
					'ip_blacklisted',
					__( 'Access denied: Your IP address has been blocked.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
				);
			}
		}

		// Check whitelist (explicit allow).
		if ( $this->settings->get( 'enable_ip_whitelist', false ) ) {
			$whitelist = $this->get_ip_list( 'ip_whitelist' );
			if ( ! $this->ip_in_list( $ip, $whitelist ) ) {
				return new WP_Error(
					'ip_not_whitelisted',
					__( 'Access denied: Your IP address is not whitelisted.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Get IP list from settings.
	 *
	 * @param string $setting_key Setting key for the IP list.
	 * @return array Array of IP addresses/ranges.
	 */
	private function get_ip_list( $setting_key ) {
		$list = $this->settings->get( $setting_key, '' );

		if ( empty( $list ) ) {
			return array();
		}

		// Parse line-separated list.
		$ips = array_filter( array_map( 'trim', explode( "\n", $list ) ) );

		return $ips;
	}

	/**
	 * Check if an IP is in a list (supports CIDR notation).
	 *
	 * @param string $ip   IP address to check.
	 * @param array  $list List of IPs/ranges.
	 * @return bool True if IP is in the list.
	 */
	private function ip_in_list( $ip, $list ) {
		foreach ( $list as $entry ) {
			if ( $this->ip_matches( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an IP matches an entry (IP or CIDR range).
	 *
	 * @param string $ip    IP address to check.
	 * @param string $entry IP or CIDR range.
	 * @return bool True if IP matches.
	 */
	private function ip_matches( $ip, $entry ) {
		// Direct match.
		if ( $ip === $entry ) {
			return true;
		}

		// Check if entry is CIDR notation.
		if ( strpos( $entry, '/' ) !== false ) {
			return $this->ip_in_cidr( $ip, $entry );
		}

		return false;
	}

	/**
	 * Check if an IP is in a CIDR range.
	 *
	 * @param string $ip   IP address to check.
	 * @param string $cidr CIDR notation (e.g., 192.168.1.0/24 or 2001:db8::/32).
	 * @return bool True if IP is in CIDR range.
	 */
	private function ip_in_cidr( $ip, $cidr ) {
		// Validate CIDR format.
		if ( strpos( $cidr, '/' ) === false ) {
			return false;
		}

		$parts = explode( '/', $cidr );
		if ( count( $parts ) !== 2 ) {
			return false; // Malformed CIDR.
		}

		list($subnet, $mask) = $parts;

		// Check if this is IPv6.
		if ( strpos( $ip, ':' ) !== false || strpos( $subnet, ':' ) !== false ) {
			return $this->ipv6_in_cidr( $ip, $subnet, $mask );
		}

		// IPv4 handling.
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		// Validate IP addresses.
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask_long = -1 << ( 32 - (int) $mask );

		// Check if IP is in subnet.
		return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
	}

	/**
	 * Check if an IPv6 address is in a CIDR range.
	 *
	 * @param string $ip     IPv6 address.
	 * @param string $subnet IPv6 subnet.
	 * @param string $mask   CIDR mask (prefix length).
	 * @return bool True if IP is in CIDR range.
	 */
	private function ipv6_in_cidr( $ip, $subnet, $mask ) {
		// Convert to binary strings.
		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		$mask_int = (int) $mask;
		if ( $mask_int < 0 || $mask_int > 128 ) {
			return false;
		}

		// Compare binary strings bit by bit up to mask length.
		$full_bytes = floor( $mask_int / 8 );
		$remainder  = $mask_int % 8;

		// Compare full bytes.
		for ( $i = 0; $i < $full_bytes; $i++ ) {
			if ( $ip_bin[ $i ] !== $subnet_bin[ $i ] ) {
				return false;
			}
		}

		// Compare remainder bits if any.
		if ( $remainder > 0 && $full_bytes < strlen( $ip_bin ) ) {
			$mask_byte = ~( ( 1 << ( 8 - $remainder ) ) - 1 );
			if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask_byte ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $mask_byte ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if HTTPS is required.
	 *
	 * @return bool|WP_Error True if HTTPS check passes, WP_Error if HTTP is blocked.
	 */
	public function check_https_requirement() {
		if ( ! $this->settings->get( 'require_https', false ) ) {
			return true;
		}

		if ( ! is_ssl() ) {
			return new WP_Error(
				'https_required',
				__( 'Access denied: HTTPS is required for all API requests.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Log a security event.
	 *
	 * @param string $event_type  Event type: 'auth_success', 'auth_failure', 'ip_block', 'file_access', etc.
	 * @param array  $event_data  Event details.
	 * @param int    $user_id     User ID (0 for unauthenticated).
	 * @return void
	 */
	public function log_security_event( $event_type, $event_data = array(), $user_id = 0 ) {
		// Check if security audit logging is enabled.
		if ( ! $this->settings->get( 'enable_security_audit_log', false ) ) {
			return;
		}

		// Check if we should log this event type.
		if ( 'auth_success' === $event_type && ! $this->settings->get( 'log_successful_auth', false ) ) {
			return;
		}

		if ( 'file_access' === $event_type && ! $this->settings->get( 'log_file_access', false ) ) {
			return;
		}

		// Prepare log entry.
		$log_entry = array(
			'timestamp'  => current_time( 'mysql' ),
			'event_type' => sanitize_key( $event_type ),
			'user_id'    => absint( $user_id ),
			'ip_address' => $this->get_client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'data'       => $event_data,
		);

		// Get existing logs.
		$logs = get_option( 'wp_mcp_ai_security_audit_log', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		// Add new entry.
		$logs[] = $log_entry;

		// Trim logs based on retention policy.
		$retention_days = absint( $this->settings->get( 'audit_log_retention_days', 90 ) );
		if ( $retention_days > 0 ) {
			$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
			$logs        = array_filter(
				$logs,
				function ( $entry ) use ( $cutoff_date ) {
					return isset( $entry['timestamp'] ) && $entry['timestamp'] >= $cutoff_date;
				}
			);
		}

		// Keep only last 10000 entries to prevent DB bloat.
		if ( count( $logs ) > 10000 ) {
			$logs = array_slice( $logs, -10000 );
		}

		// Save logs.
		update_option( 'wp_mcp_ai_security_audit_log', $logs, false );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Proxy/load balancer.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Get first IP if multiple (X-Forwarded-For can contain multiple IPs).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Get security headers to add to responses.
	 *
	 * @return array Array of header name => value pairs.
	 */
	public function get_security_headers() {
		$headers = array();

		// Check if security headers are enabled.
		if ( ! $this->settings->get( 'enable_security_headers', true ) ) {
			return $headers;
		}

		// Always add these OWASP-recommended headers.
		$headers['X-Content-Type-Options'] = 'nosniff';
		$headers['X-Frame-Options']        = 'DENY'; // Legacy fallback.
		$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';

		// Add CSP frame-ancestors.
		$csp_frame = $this->settings->get( 'csp_frame_ancestors', 'none' );
		if ( ! empty( $csp_frame ) && 'none' !== $csp_frame ) {
			$headers['Content-Security-Policy'] = "frame-ancestors '{$csp_frame}';";
		} elseif ( 'none' === $csp_frame ) {
			$headers['Content-Security-Policy'] = "frame-ancestors 'none';";
		}

		// Add HSTS if enabled.
		if ( $this->settings->get( 'enable_hsts', false ) && is_ssl() ) {
			$max_age                              = absint( $this->settings->get( 'hsts_max_age', 31536000 ) );
			$headers['Strict-Transport-Security'] = "max-age={$max_age}; includeSubDomains";
		}

		return $headers;
	}

	/**
	 * Check if a user passes all security checks.
	 *
	 * @param int    $user_id User ID (0 for unauthenticated).
	 * @param string $context Context: 'rest_api', 'media', 'attachment', etc.
	 * @return bool|WP_Error True if all checks pass, WP_Error otherwise.
	 */
	public function check_user_access( $user_id, $context = 'rest_api' ) {
		// Check IP access.
		$ip_check = $this->check_ip_access( $this->get_client_ip() );
		if ( is_wp_error( $ip_check ) ) {
			$this->log_security_event( 'ip_block', array( 'context' => $context ), $user_id );
			return $ip_check;
		}

		// Check HTTPS requirement.
		$https_check = $this->check_https_requirement();
		if ( is_wp_error( $https_check ) ) {
			$this->log_security_event( 'https_violation', array( 'context' => $context ), $user_id );
			return $https_check;
		}

		// If user is not authenticated, checks stop here.
		if ( 0 === $user_id ) {
			return true;
		}

		// Check role restrictions.
		if ( ! $this->check_role_access( $user_id ) ) {
			$this->log_security_event( 'role_denied', array( 'context' => $context ), $user_id );
			return new WP_Error(
				'insufficient_role',
				__( 'Access denied: Your user role does not have permission to access this resource.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Check capability requirement.
		if ( ! $this->check_capability_requirement( $user_id ) ) {
			$this->log_security_event( 'capability_denied', array( 'context' => $context ), $user_id );
			return new WP_Error(
				'insufficient_capability',
				__( 'Access denied: You do not have sufficient capabilities to access this resource.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
