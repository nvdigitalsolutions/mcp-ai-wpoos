<?php
/**
 * MCP App Registry.
 *
 * Manages MCP App configurations per assistant and coordinates
 * tool discovery and registration from remote MCP servers.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for managing MCP App connections per assistant.
 *
 * Stores MCP App configurations as post meta on the assistant CPT,
 * handles connection testing, tool discovery, and bridging remote
 * tools into the local tool registry.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_MCP_App_Registry {

	/**
	 * Post meta key for MCP Apps configuration.
	 *
	 * @var string
	 */
	const META_KEY = '_wp_mcp_ai_mcp_apps';

	/**
	 * Transient prefix for cached tool discovery results.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_mcp_app_tools_';

	/**
	 * Cache duration in seconds (5 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Maximum number of MCP Apps per assistant.
	 *
	 * @var int
	 */
	const MAX_APPS_PER_ASSISTANT = 10;

	/**
	 * Validate whether a remote MCP server URL is allowed.
	 *
	 * Performs three layers of checks:
	 *
	 * 1. **Scheme** — only `http`/`https` are accepted. `javascript:`,
	 *    `data:`, `file:`, etc. are rejected.
	 * 2. **Host present** — the URL must include a non-empty host.
	 * 3. **Hostname allowlist** (optional) — when an allowlist is configured
	 *    via either:
	 *    - the `WP_MCP_AI_MCP_APP_ALLOWED_HOSTS` constant (comma-separated
	 *      string of host names), or
	 *    - the `wp_mcp_ai_mcp_app_allowed_hosts` filter (array of host
	 *      names),
	 *    only URLs whose host is a member of the allowlist are accepted.
	 *    Allowlist matching is case-insensitive on the hostname only and
	 *    supports a leading `*.` wildcard (e.g. `*.example.com`).
	 *
	 *    When the resolved allowlist is empty, the call is permissive
	 *    (returns `true`) but a warning is logged so operators can spot
	 *    unconfigured deployments.
	 *
	 * @since 1.8.0
	 *
	 * @param string $url The remote MCP server URL to validate.
	 * @return true|WP_Error True when the URL is allowed; WP_Error otherwise.
	 */
	public static function is_url_allowed( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';

		if ( '' === $url ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_url_empty',
				__( 'MCP App server URL is empty.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_url_malformed',
				__( 'MCP App server URL is malformed.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_url_scheme',
				__( 'MCP App server URL must use the http or https scheme.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$host = strtolower( $parts['host'] );

		$allowlist = self::get_allowed_hosts();

		if ( empty( $allowlist ) ) {
			// No allowlist configured: permissive but logged so operators can
			// notice that the deployment is open to arbitrary upstream MCP
			// servers. Use log_warning when available; fall back to debug log.
			if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'MCP App allowlist is empty — accepting arbitrary upstream MCP server. Configure WP_MCP_AI_MCP_APP_ALLOWED_HOSTS or the wp_mcp_ai_mcp_app_allowed_hosts filter to restrict access.',
					array( 'host' => $host )
				);
			}
			return true;
		}

		foreach ( $allowlist as $pattern ) {
			if ( self::host_matches_pattern( $host, $pattern ) ) {
				return true;
			}
		}

		return new WP_Error(
			'wp_mcp_ai_mcp_app_url_not_allowed',
			sprintf(
				/* translators: %s: host name. */
				__( 'MCP App server host %s is not on the configured allowlist.', 'mcp-ai-wpoos-pro' ),
				$host
			),
			array(
				'status' => 403,
				'host'   => $host,
			)
		);
	}

	/**
	 * Build the resolved hostname allowlist from constant + filter.
	 *
	 * @since 1.8.0
	 *
	 * @return array<int, string> Lower-cased list of host patterns.
	 */
	protected static function get_allowed_hosts() {
		$hosts = array();

		if ( defined( 'WP_MCP_AI_MCP_APP_ALLOWED_HOSTS' ) && is_string( WP_MCP_AI_MCP_APP_ALLOWED_HOSTS ) ) {
			foreach ( explode( ',', WP_MCP_AI_MCP_APP_ALLOWED_HOSTS ) as $candidate ) {
				$candidate = strtolower( trim( $candidate ) );
				if ( '' !== $candidate ) {
					$hosts[] = $candidate;
				}
			}
		}

		/**
		 * Filters the allowlist of remote MCP App server hosts.
		 *
		 * Each entry is a hostname (case-insensitive). A leading `*.`
		 * acts as a one-level wildcard — e.g. `*.example.com` matches
		 * `mcp.example.com` and `api.example.com` but not
		 * `example.com` itself.
		 *
		 * Returning an empty array disables strict enforcement and is
		 * the default permissive (with logged warning) behaviour.
		 *
		 * @since 1.8.0
		 *
		 * @param array $hosts Hostnames seeded from the
		 *                     `WP_MCP_AI_MCP_APP_ALLOWED_HOSTS` constant.
		 */
		$hosts = apply_filters( 'wp_mcp_ai_mcp_app_allowed_hosts', $hosts );

		if ( ! is_array( $hosts ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $hosts as $host ) {
			if ( ! is_string( $host ) ) {
				continue;
			}
			$host = strtolower( trim( $host ) );
			if ( '' !== $host ) {
				$normalized[] = $host;
			}
		}

		return $normalized;
	}

	/**
	 * Check whether a host matches an allowlist pattern.
	 *
	 * Supports an optional `*.` prefix for one-level wildcard matching.
	 *
	 * @since 1.8.0
	 *
	 * @param string $host    Lower-cased host extracted from the URL.
	 * @param string $pattern Lower-cased allowlist entry.
	 * @return bool True on match.
	 */
	protected static function host_matches_pattern( $host, $pattern ) {
		if ( '' === $host || '' === $pattern ) {
			return false;
		}

		if ( 0 === strpos( $pattern, '*.' ) ) {
			$suffix = substr( $pattern, 1 ); // Includes the leading dot, e.g. ".example.com".
			// Wildcard matches any single subdomain (or deeper). Require that
			// $host ends with the suffix and has at least one char before it.
			$suffix_length = strlen( $suffix );
			$host_length   = strlen( $host );
			if ( $host_length <= $suffix_length ) {
				return false;
			}
			return substr( $host, -$suffix_length ) === $suffix;
		}

		return $host === $pattern;
	}

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.8.0
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get MCP Apps configured for an assistant.
	 *
	 * @since 1.8.0
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of MCP App configurations.
	 */
	public function get_apps( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return array();
		}

		$apps = get_post_meta( $assistant_id, self::META_KEY, true );

		if ( ! is_array( $apps ) ) {
			return array();
		}

		return $apps;
	}

	/**
	 * Save MCP Apps configuration for an assistant.
	 *
	 * @since 1.8.0
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $apps         Array of MCP App configurations.
	 * @return bool True on success.
	 */
	public function save_apps( $assistant_id, array $apps ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return false;
		}

		// Enforce maximum apps limit.
		$apps = array_slice( $apps, 0, self::MAX_APPS_PER_ASSISTANT );

		// Sanitize each app configuration.
		$sanitized_apps = array();
		foreach ( $apps as $app ) {
			$sanitized = self::sanitize_app_config( $app );
			if ( ! empty( $sanitized['server_url'] ) ) {
				$sanitized_apps[] = $sanitized;
			}
		}

		if ( empty( $sanitized_apps ) ) {
			delete_post_meta( $assistant_id, self::META_KEY );
		} else {
			update_post_meta( $assistant_id, self::META_KEY, $sanitized_apps );
		}

		// Clear cached tools for this assistant.
		$this->clear_tool_cache( $assistant_id );

		return true;
	}

	/**
	 * Sanitize a single MCP App configuration.
	 *
	 * @since 1.8.0
	 * @param array $app Raw app configuration.
	 * @return array Sanitized app configuration.
	 */
	public static function sanitize_app_config( $app ) {
		if ( ! is_array( $app ) ) {
			return array();
		}

		$server_url = isset( $app['server_url'] ) ? esc_url_raw( $app['server_url'] ) : '';

		// Drop the URL when it fails the allowlist / scheme validation.
		// save_apps() then skips entries with an empty server_url.
		if ( '' !== $server_url ) {
			$allowed = self::is_url_allowed( $server_url );
			if ( is_wp_error( $allowed ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					WP_MCP_AI_Logger::log_warning(
						sprintf( 'MCP App URL rejected at save: %s', $allowed->get_error_message() ),
						array( 'server_url' => $server_url )
					);
				}
				$server_url = '';
			}
		}

		$sanitized = array(
			'label'       => isset( $app['label'] ) ? sanitize_text_field( $app['label'] ) : '',
			'server_url'  => $server_url,
			'auth_type'   => isset( $app['auth_type'] ) && in_array( $app['auth_type'], array( 'none', 'bearer', 'header', 'oauth' ), true )
				? $app['auth_type']
				: 'none',
			'token'       => isset( $app['token'] ) ? sanitize_text_field( $app['token'] ) : '',
			'header_name' => isset( $app['header_name'] ) ? sanitize_text_field( $app['header_name'] ) : '',
			'enabled'     => isset( $app['enabled'] ) ? (bool) $app['enabled'] : true,
			'timeout'     => isset( $app['timeout'] ) ? max( 1, min( 120, absint( $app['timeout'] ) ) ) : 30,
			'verify_ssl'  => isset( $app['verify_ssl'] ) ? (bool) $app['verify_ssl'] : true,
		);

		// Store OAuth token data when using OAuth auth_type.
		if ( 'oauth' === $sanitized['auth_type'] && ! empty( $app['oauth_data'] ) && is_array( $app['oauth_data'] ) ) {
			$sanitized['oauth_data'] = array(
				'access_token'  => isset( $app['oauth_data']['access_token'] ) ? sanitize_text_field( $app['oauth_data']['access_token'] ) : '',
				'refresh_token' => isset( $app['oauth_data']['refresh_token'] ) ? sanitize_text_field( $app['oauth_data']['refresh_token'] ) : '',
				'token_type'    => isset( $app['oauth_data']['token_type'] ) ? sanitize_text_field( $app['oauth_data']['token_type'] ) : 'Bearer',
				'expires_in'    => isset( $app['oauth_data']['expires_in'] ) ? absint( $app['oauth_data']['expires_in'] ) : 3600,
				'scope'         => isset( $app['oauth_data']['scope'] ) ? sanitize_text_field( $app['oauth_data']['scope'] ) : '',
				'issued_at'     => isset( $app['oauth_data']['issued_at'] ) ? absint( $app['oauth_data']['issued_at'] ) : time(),
			);
		} elseif ( 'oauth' === $sanitized['auth_type'] ) {
			// Preserve existing oauth_data from previous config if not being updated.
			$sanitized['oauth_data'] = isset( $app['oauth_data'] ) && is_array( $app['oauth_data'] ) ? $app['oauth_data'] : array();
		}

		return $sanitized;
	}

	/**
	 * Create an MCP App Client from a configuration.
	 *
	 * When the config uses auth_type 'oauth', attaches an OAuth client
	 * for automatic token management and refresh.
	 *
	 * @since 1.8.0
	 * @param array $app_config MCP App configuration.
	 * @return WP_MCP_AI_MCP_App_Client
	 */
	public function create_client( array $app_config ) {
		$config = $app_config;

		// For OAuth apps, ensure the token is populated from oauth_data.
		if ( 'oauth' === ( $config['auth_type'] ?? 'none' ) ) {
			if ( empty( $config['token'] ) && ! empty( $config['oauth_data']['access_token'] ) ) {
				$config['token'] = $config['oauth_data']['access_token'];
			}

			// Attach OAuth client for auto-refresh.
			if ( class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
				$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $config['server_url'] );
				if ( ! empty( $config['oauth_data'] ) && is_array( $config['oauth_data'] ) ) {
					$oauth_client->set_token_data( $config['oauth_data'] );
				}
				$config['oauth_client'] = $oauth_client;
				$config['oauth_data']   = isset( $config['oauth_data'] ) ? $config['oauth_data'] : array();
			}
		}

		return new WP_MCP_AI_MCP_App_Client( $config );
	}

	/**
	 * Discover and register tools from MCP Apps for an assistant.
	 *
	 * Connects to each enabled MCP App, discovers available tools,
	 * and registers bridge tools in the local registry.
	 *
	 * @since 1.8.0
	 * @param int                     $assistant_id Assistant post ID.
	 * @param WP_MCP_AI_Tool_Registry $registry     Tool registry instance.
	 * @return array Array of registered bridge tool slugs.
	 */
	public function register_remote_tools( $assistant_id, $registry ) {
		$apps = $this->get_apps( $assistant_id );

		if ( empty( $apps ) ) {
			return array();
		}

		$registered_slugs = array();

		foreach ( $apps as $app_config ) {
			if ( empty( $app_config['enabled'] ) ) {
				continue;
			}

			if ( empty( $app_config['server_url'] ) ) {
				continue;
			}

			// Defense-in-depth: re-validate against the allowlist in case the
			// stored config predates the current allowlist configuration.
			if ( is_wp_error( self::is_url_allowed( $app_config['server_url'] ) ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Skipping MCP App tool discovery: server URL is not on the current allowlist.',
						array( 'server_url' => $app_config['server_url'] )
					);
				}
				continue;
			}

			$tools = $this->discover_tools( $app_config );

			if ( is_wp_error( $tools ) || empty( $tools ) ) {
				continue;
			}

			$label = ! empty( $app_config['label'] ) ? $app_config['label'] : wp_parse_url( $app_config['server_url'], PHP_URL_HOST );

			foreach ( $tools as $remote_tool ) {
				$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $app_config, $label );
				$slug   = $bridge->get_slug();

				// Avoid duplicate registration.
				if ( $registry->get_tool( $slug ) ) {
					continue;
				}

				$registry->register_tool( $bridge );
				$registered_slugs[] = $slug;
			}
		}

		return $registered_slugs;
	}

	/**
	 * Discover tools from a single MCP App server.
	 *
	 * Uses transient caching to avoid repeated requests.
	 *
	 * @since 1.8.0
	 * @param array $app_config MCP App configuration.
	 * @return array|WP_Error Array of tool definitions or WP_Error.
	 */
	public function discover_tools( array $app_config ) {
		$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$client = $this->create_client( $app_config );

		$init_result = $client->initialize();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		$tools = $client->list_tools();
		if ( is_wp_error( $tools ) ) {
			return $tools;
		}

		// Cache the results.
		set_transient( $cache_key, $tools, self::CACHE_TTL );

		return $tools;
	}

	/**
	 * Clear the tool cache for an assistant.
	 *
	 * @since 1.8.0
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public function clear_tool_cache( $assistant_id ) {
		$apps = get_post_meta( absint( $assistant_id ), self::META_KEY, true );

		if ( ! is_array( $apps ) ) {
			return;
		}

		foreach ( $apps as $app_config ) {
			$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );
			delete_transient( $cache_key );
		}
	}

	/**
	 * Test connection to a specific MCP App.
	 *
	 * @since 1.8.0
	 * @param array $app_config MCP App configuration.
	 * @return array|WP_Error Connection test result.
	 */
	public function test_connection( array $app_config ) {
		$client = $this->create_client( $app_config );
		return $client->test_connection();
	}
}
