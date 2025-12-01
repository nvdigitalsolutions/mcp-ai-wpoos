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
	 * Adds filters to prevent SSL verification issues with loopback addresses
	 * and to allow HTTP requests to private network addresses.
	 */
	public static function init() {
		// Add filter to handle loopback addresses properly.
		add_filter( 'http_request_args', array( __CLASS__, 'handle_loopback_requests' ), 10, 2 );

		// Add filter to allow requests to private network addresses.
		// WordPress blocks requests to local/private IPs by default for security.
		// This filter explicitly allows them for local AI services like LM Studio, Ollama, etc.
		add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_private_network_requests' ), 10, 3 );

		// Register network interface binding for local AI providers.
		self::register_network_interface_binding();

		// Register connection timeout handler for local AI providers.
		self::register_connection_timeout_handler();
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
	 * Also ensures adequate timeout for private network connections:
	 * 1. Private IPs accessed through proxies (e.g., Cloudflare) may have higher latency
	 * 2. Connection timeouts can occur before the overall request timeout
	 * 3. Uses a minimum of 30 seconds to prevent premature connection failures
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
			// Get settings to check if SSL bypass is enabled.
			$settings           = WP_MCP_AI_Admin_Settings::get_settings();
			$ssl_bypass_enabled = isset( $settings['enable_loopback_ssl_bypass'] ) ? (bool) $settings['enable_loopback_ssl_bypass'] : true;

			if ( $ssl_bypass_enabled ) {
				// Disable SSL verification for local/private addresses to prevent certificate mismatch errors.
				$args['sslverify'] = false;

				// Allow HTTP (non-SSL) requests to local/private addresses.
				// Some WordPress setups or plugins enforce HTTPS globally, which breaks local requests.
				$args['reject_unsafe_urls'] = false;
			}

			// Ensure adequate timeout for private network connections.
			// Private IPs (especially through proxies like Cloudflare) may have higher latency.
			// Use a minimum of 30 seconds to prevent premature connection timeouts.
			if ( isset( $args['timeout'] ) && is_numeric( $args['timeout'] ) ) {
				$args['timeout'] = max( 30, absint( $args['timeout'] ) );
			} else {
				$args['timeout'] = 30;
			}
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

	/**
	 * Allow HTTP requests to private network addresses.
	 *
	 * WordPress blocks requests to local/private IP addresses by default for security.
	 * This filter explicitly allows them for local AI services (LM Studio, Ollama, etc.)
	 * running on the local network.
	 *
	 * This enables connections to:
	 * - LM Studio on private network: 192.168.2.222:1234
	 * - Ollama on LAN: 10.0.0.50:11434
	 * - Crawl4AI on local network: 172.16.0.10:8000
	 * - Any other local AI service on private network addresses
	 *
	 * @param bool   $is_external Whether the request is to an external host.
	 * @param string $host        The host to check.
	 * @param string $url         The URL being requested (unused but part of filter signature).
	 * @return bool True to allow the request, false to block it.
	 */
	public static function allow_private_network_requests( $is_external, $host, $url ) {
		// If WordPress already considers it external, keep that.
		if ( $is_external ) {
			return $is_external;
		}

		// Get settings to check if private network requests are enabled.
		$settings                = WP_MCP_AI_Admin_Settings::get_settings();
		$private_network_enabled = isset( $settings['enable_loopback_private_network_requests'] ) ? (bool) $settings['enable_loopback_private_network_requests'] : true;

		if ( ! $private_network_enabled ) {
			return $is_external;
		}

		// Check if this is a loopback or private network address.
		// If it is, explicitly mark it as "external" so WordPress allows the request.
		if ( self::is_loopback_address( $host ) ) {
			return true;
		}

		return $is_external;
	}

	/**
	 * Validate network interface configuration.
	 *
	 * Checks if the network interface value appears to be misconfigured
	 * (e.g., user entered the destination IP instead of a local interface).
	 *
	 * @param string $interface The network interface value to validate.
	 * @param string $endpoint_url The endpoint URL being accessed.
	 * @return bool True if valid, false if likely misconfigured.
	 */
	private static function is_valid_network_interface( $interface, $endpoint_url ) {
		if ( empty( $interface ) ) {
			return true; // Empty is valid (no binding).
		}

		// Parse the endpoint URL to get the host.
		$parsed_url = wp_parse_url( $endpoint_url );
		if ( empty( $parsed_url['host'] ) ) {
			return true; // Can't validate without a host.
		}

		$endpoint_host = $parsed_url['host'];

		// Check if interface is a private IP but endpoint is localhost/127.0.0.1.
		// This indicates the user wants to reach a server on the private network,.
		// but put the IP in the wrong field.
		if ( filter_var( $interface, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			if ( self::is_private_ipv4_address( $interface ) ) {
				// Check if endpoint is localhost or loopback.
				$is_localhost = in_array(
					strtolower( $endpoint_host ),
					array( 'localhost', 'localhost.localdomain', '127.0.0.1', '::1' ),
					true
				);

				if ( $is_localhost ) {
					WP_MCP_AI_Logger::log_error(
						'Network interface misconfiguration detected.',
						array(
							'interface'     => $interface,
							'endpoint_host' => $endpoint_host,
							'message'       => sprintf(
								'You entered a private IP (%s) in the Network Interface field, but your Endpoint URL is set to localhost. It appears you want to connect to an LM Studio/Ollama server at %s. Please UPDATE the Endpoint URL field to "http://%s:PORT" instead, and leave the Network Interface field EMPTY. The Network Interface field is for binding the SOURCE interface on this WordPress server, not for specifying the destination.',
								$interface,
								$interface,
								$interface
							),
						)
					);
					return false;
				}
			}
		}

		// Check if the interface value matches the endpoint host.
		// This indicates user confusion: they entered the destination address.
		// instead of the local network interface name.
		if ( $interface === $endpoint_host ) {
			WP_MCP_AI_Logger::log_error(
				'Network interface misconfiguration detected.',
				array(
					'interface'     => $interface,
					'endpoint_host' => $endpoint_host,
					'message'       => 'The network interface field should contain a local interface name (e.g., "eth0") or local IP address, not the destination server address. Skipping interface binding to prevent connection errors.',
				)
			);
			return false;
		}

		// If interface looks like an IP address, verify it's not in the endpoint URL.
		if ( filter_var( $interface, FILTER_VALIDATE_IP ) ) {
			// Check if this IP appears anywhere in the endpoint URL.
			if ( strpos( $endpoint_url, $interface ) !== false ) {
				WP_MCP_AI_Logger::log_error(
					'Network interface misconfiguration detected.',
					array(
						'interface'    => $interface,
						'endpoint_url' => $endpoint_url,
						'message'      => 'The network interface IP address matches the destination URL. This field should contain the LOCAL IP address of the WordPress server, not the destination server. Skipping interface binding to prevent connection errors.',
					)
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply network interface binding to cURL requests for local AI providers.
	 *
	 * This filter is applied to the http_api_curl hook to bind HTTP requests
	 * to a specific network interface when configured for Ollama or LM Studio.
	 *
	 * Use case: When WordPress is hosted remotely (e.g., Cloudways) and needs
	 * to route requests through a specific network interface to reach local AI
	 * providers on private network addresses (e.g., 192.168.2.222).
	 *
	 * Important: The network interface field should contain:
	 * - A local interface name (e.g., "eth0", "wlan0")
	 * - A local IP address assigned to the WordPress server (not the destination)
	 *
	 * Common mistake: Users sometimes enter the destination IP address (where
	 * LM Studio/Ollama is running) instead of the local interface. This causes
	 * cURL error 45: "Cannot assign requested address" (errno 99).
	 *
	 * @param resource $handle The cURL handle.
	 * @param array    $parsed_args The HTTP request arguments.
	 * @param string   $url The request URL.
	 * @return resource The modified cURL handle.
	 */
	public static function apply_network_interface_binding( $handle, $parsed_args, $url ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Check if this is an Ollama request.
		if ( isset( $settings['ollama_endpoint_url'] ) && ! empty( $settings['ollama_endpoint_url'] ) ) {
			$ollama_endpoint = untrailingslashit( $settings['ollama_endpoint_url'] );
			if ( strpos( $url, $ollama_endpoint ) === 0 && ! empty( $settings['ollama_network_interface'] ) ) {
				$interface = sanitize_text_field( $settings['ollama_network_interface'] );

				// Validate the interface configuration before applying.
				if ( self::is_valid_network_interface( $interface, $ollama_endpoint ) ) {
					curl_setopt( $handle, CURLOPT_INTERFACE, $interface );
				}
				return $handle;
			}
		}

		// Check if this is an LM Studio request.
		if ( isset( $settings['lm_studio_endpoint_url'] ) && ! empty( $settings['lm_studio_endpoint_url'] ) ) {
			$lm_studio_endpoint = untrailingslashit( $settings['lm_studio_endpoint_url'] );
			if ( strpos( $url, $lm_studio_endpoint ) === 0 && ! empty( $settings['lm_studio_network_interface'] ) ) {
				$interface = sanitize_text_field( $settings['lm_studio_network_interface'] );

				// Validate the interface configuration before applying.
				if ( self::is_valid_network_interface( $interface, $lm_studio_endpoint ) ) {
					curl_setopt( $handle, CURLOPT_INTERFACE, $interface );
				}
				return $handle;
			}
		}

		return $handle;
	}

	/**
	 * Register network interface binding filter.
	 *
	 * This should be called during plugin initialization to enable
	 * network interface binding for local AI providers.
	 */
	public static function register_network_interface_binding() {
		add_filter( 'http_api_curl', array( __CLASS__, 'apply_network_interface_binding' ), 10, 3 );
	}

	/**
	 * Set connection timeout for cURL requests to local AI providers.
	 *
	 * WordPress uses cURL for HTTP requests, which has two separate timeout settings:
	 * - CURLOPT_TIMEOUT: Overall request timeout (set via 'timeout' arg)
	 * - CURLOPT_CONNECTTIMEOUT: Connection establishment timeout (defaults to 10s)
	 *
	 * For local AI providers on private networks (e.g., 192.168.2.222:11434), the
	 * connection phase can take longer than 10 seconds due to network latency,
	 * routing, or firewall rules. This causes "cURL error 28: Timeout was reached"
	 * even when the overall timeout is set to 120 seconds.
	 *
	 * This filter sets CURLOPT_CONNECTTIMEOUT to match the overall timeout for
	 * loopback and private network addresses, preventing premature connection failures.
	 *
	 * @param resource $handle The cURL handle.
	 * @param array    $parsed_args The HTTP request arguments.
	 * @param string   $url The request URL.
	 * @return resource The modified cURL handle.
	 */
	public static function set_connection_timeout( $handle, $parsed_args, $url ) {
		$parsed_url = wp_parse_url( $url );

		// Only apply extended connection timeout for loopback/private network addresses.
		if ( ! empty( $parsed_url['host'] ) && self::is_loopback_address( $parsed_url['host'] ) ) {
			// Get the overall timeout from request args.
			$timeout = isset( $parsed_args['timeout'] ) ? absint( $parsed_args['timeout'] ) : 30;

			// Set connection timeout to match overall timeout.
			// This prevents connection phase from timing out prematurely.
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $timeout );
		}

		return $handle;
	}

	/**
	 * Register connection timeout handler.
	 *
	 * This should be called during plugin initialization to enable
	 * extended connection timeouts for local AI providers.
	 */
	public static function register_connection_timeout_handler() {
		add_filter( 'http_api_curl', array( __CLASS__, 'set_connection_timeout' ), 10, 3 );
	}
}
