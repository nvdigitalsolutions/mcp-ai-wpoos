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
	 * Post meta key for per-app connection status (last test results).
	 *
	 * Stored as an array keyed by md5( server_url|auth_type|header_name ).
	 *
	 * @var string
	 */
	const STATUS_META_KEY = '_wp_mcp_ai_mcp_app_status';

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
	 *      string of host names; hard override when defined),
	 *    - the `wp_mcp_ai_mcp_app_allowed_hosts` filter (array of host
	 *      names), or
	 *    - the "MCP App Allowed Hosts" setting under Settings → Security
	 *      Center → Network & Headers (newline-separated host names),
	 *    only URLs whose host is a member of the allowlist are accepted.
	 *    Allowlist matching is case-insensitive on the hostname only and
	 *    supports a leading `*.` wildcard (e.g. `*.example.com`). The filter
	 *    output and the saved setting are merged; the constant, when defined,
	 *    replaces both.
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
	 * Build the resolved hostname allowlist from constant + filter + settings.
	 *
	 * The UI setting (Settings → Security Center → Network & Headers) is only
	 * consulted when the constant is not defined, so operators can hard-cap
	 * the allowlist regardless of what an admin saves in the UI.
	 *
	 * @since 1.8.0
	 * @since 1.9.1 Added the saved-settings merge.
	 *
	 * @return array<int, string> Lower-cased list of host patterns.
	 */
	protected static function get_allowed_hosts() {
		$hosts = array();

		$constant_defined = defined( 'WP_MCP_AI_MCP_APP_ALLOWED_HOSTS' ) && is_string( WP_MCP_AI_MCP_APP_ALLOWED_HOSTS );
		if ( $constant_defined ) {
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
			$hosts = array();
		}

		// Admins can self-serve via the Security Center unless the constant
		// is defined (hard override).
		if ( ! $constant_defined ) {
			$setting_hosts = self::get_allowed_hosts_from_settings();
			if ( ! empty( $setting_hosts ) ) {
				$hosts = array_unique( array_merge( $hosts, $setting_hosts ) );
			}
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
	 * Read the MCP App Allowed Hosts setting from the plugin settings.
	 *
	 * Accepts newline- or comma-separated hostnames as saved by the Security
	 * Center textarea field.
	 *
	 * @since 1.9.1
	 *
	 * @return array<int, string> Lower-cased list of host patterns.
	 */
	protected static function get_allowed_hosts_from_settings() {
		$value = '';

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( isset( $settings['mcp_app_allowed_hosts'] ) && is_string( $settings['mcp_app_allowed_hosts'] ) ) {
				$value = $settings['mcp_app_allowed_hosts'];
			}
		} else {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			if ( is_array( $settings ) && isset( $settings['mcp_app_allowed_hosts'] ) && is_string( $settings['mcp_app_allowed_hosts'] ) ) {
				$value = $settings['mcp_app_allowed_hosts'];
			}
		}

		$hosts = array();
		foreach ( preg_split( '/[\r\n,]+/', $value ) as $candidate ) {
			$candidate = strtolower( trim( $candidate ) );
			if ( '' !== $candidate ) {
				$hosts[] = $candidate;
			}
		}

		return $hosts;
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

		// Prune connection status records for apps that no longer exist.
		$kept_keys = array();
		foreach ( $sanitized_apps as $app ) {
			$kept_keys[] = $this->get_app_status_key( $app );
		}
		$kept_keys = array_flip( $kept_keys );
		$statuses  = $this->get_app_status( $assistant_id );
		if ( ! empty( $statuses ) ) {
			$pruned = array_intersect_key( $statuses, $kept_keys );
			if ( $pruned !== $statuses ) {
				update_post_meta( $assistant_id, self::STATUS_META_KEY, $pruned );
			}
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
			'auth_type'   => isset( $app['auth_type'] ) && in_array( $app['auth_type'], array( 'none', 'bearer', 'basic', 'header', 'oauth' ), true )
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
		$registered_slugs = array();

		foreach ( $this->collect_remote_tools( $assistant_id ) as $entry ) {
			foreach ( $entry['tools'] as $remote_tool ) {
				$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $entry['app_config'], $entry['label'] );
				$slug   = $bridge->get_slug();

				// Avoid duplicate registration.
				if ( $registry->get_tool( $slug ) ) {
					continue;
				}

				$registry->register_tool( $bridge );
				$registered_slugs[] = $slug;
			}

			// Persist a success status with the live tool count so the metabox
			// badge reflects reality, not just a successful handshake.
			$this->record_app_status(
				$assistant_id,
				$entry['app_config'],
				array(
					'last_status' => 'ok',
					'last_error'  => '',
					'tool_count'  => count( $entry['tools'] ),
				)
			);
		}

		return $registered_slugs;
	}

	/**
	 * Compute the local bridge tool slugs for an assistant's MCP Apps.
	 *
	 * Does not register anything — used to expose bridged tools in the chat
	 * payload (see the wp_mcp_ai_chat_effective_tools filter in
	 * mcp-apps-init.php) without depending on registration order.
	 *
	 * Discovery results are transient-cached, so repeat calls within the
	 * cache window are cheap.
	 *
	 * @since 1.9.2
	 * @param int $assistant_id Assistant post ID.
	 * @return array<int, string> Local bridge tool slugs (e.g. mcp_app_elementor_read_page).
	 */
	public function get_remote_tool_slugs( $assistant_id ) {
		$slugs = array();

		foreach ( $this->collect_remote_tools( $assistant_id ) as $entry ) {
			foreach ( $entry['tools'] as $remote_tool ) {
				$bridge  = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $entry['app_config'], $entry['label'] );
				$slugs[] = $bridge->get_slug();
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Collect discovered tools from all enabled MCP Apps for an assistant.
	 *
	 * Applies the enabled/server_url/allowlist guards, records per-app
	 * connection status snapshots (errors and empty-tool successes), and
	 * returns the discovery results grouped per app.
	 *
	 * @since 1.9.2
	 * @param int $assistant_id Assistant post ID.
	 * @return array<int, array{app_config: array, tools: array, label: string}>
	 */
	protected function collect_remote_tools( $assistant_id ) {
		$apps = $this->get_apps( $assistant_id );

		if ( empty( $apps ) ) {
			return array();
		}

		$collected = array();

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
				$message = __( 'Server URL is not on the current allowlist.', 'mcp-ai-wpoos-pro' );
				$this->record_app_status(
					$assistant_id,
					$app_config,
					array(
						'last_status' => 'error',
						'last_error'  => $message,
					)
				);
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Skipping MCP App tool discovery: server URL is not on the current allowlist.',
						array( 'server_url' => $app_config['server_url'] )
					);
				}
				continue;
			}

			$tools = $this->discover_tools( $app_config );

			if ( is_wp_error( $tools ) ) {
				$this->record_app_status(
					$assistant_id,
					$app_config,
					array(
						'last_status' => 'error',
						'last_error'  => $tools->get_error_message(),
					)
				);
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					WP_MCP_AI_Logger::log_warning(
						sprintf( 'MCP App tool discovery failed: %s', $tools->get_error_message() ),
						array(
							'server_url' => $app_config['server_url'],
							'label'      => isset( $app_config['label'] ) ? $app_config['label'] : '',
						)
					);
				}
				continue;
			}

			if ( empty( $tools ) ) {
				$this->record_app_status(
					$assistant_id,
					$app_config,
					array(
						'last_status' => 'ok',
						'last_error'  => '',
						'tool_count'  => 0,
					)
				);
				continue;
			}

			$collected[] = array(
				'app_config' => $app_config,
				'tools'      => $tools,
				'label'      => ! empty( $app_config['label'] ) ? $app_config['label'] : wp_parse_url( $app_config['server_url'], PHP_URL_HOST ),
			);
		}

		return $collected;
	}

	/**
	 * Discover tools from a single MCP App server.
	 *
	 * Uses transient caching to avoid repeated requests. Attempts the
	 * stateless server/discover handshake first, falling back to the legacy
	 * sessionful initialize handshake (which captures Mcp-Session-Id) for
	 * pre-2026-07-28 servers.
	 *
	 * @since 1.8.0
	 * @since 1.9.1 Added sessionful fallback and $refresh parameter.
	 * @param array $app_config MCP App configuration.
	 * @param bool  $refresh    Whether to bypass the transient cache.
	 * @return array|WP_Error Array of tool definitions or WP_Error.
	 */
	public function discover_tools( array $app_config, $refresh = false ) {
		$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$client = $this->create_client( $app_config );

		$init_result = $client->discover();
		if ( is_wp_error( $init_result ) ) {
			$error_data = $init_result->get_error_data();
			$rpc_code   = is_array( $error_data ) && isset( $error_data['rpc_code'] ) ? $error_data['rpc_code'] : 0;
			$message    = strtolower( $init_result->get_error_message() );

			// Sessionful servers reject server/discover with -32601 (unknown
			// method) or -32600 (e.g. "Missing Mcp-Session-Id header").
			if ( -32601 === $rpc_code || -32600 === $rpc_code || false !== strpos( $message, 'session' ) ) {
				$init_result = $client->initialize();
				if ( is_wp_error( $init_result ) ) {
					return $init_result;
				}
			} else {
				return $init_result;
			}
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

	/**
	 * Build the status-meta key for an app configuration.
	 *
	 * Keyed on the connection identity (URL + auth shape) so statuses survive
	 * row reordering and are invalidated when the connection changes. The
	 * token is deliberately excluded.
	 *
	 * @since 1.9.1
	 * @param array $app_config MCP App configuration.
	 * @return string MD5 status key.
	 */
	public function get_app_status_key( array $app_config ) {
		return md5(
			( isset( $app_config['server_url'] ) ? $app_config['server_url'] : '' ) .
			'|' . ( isset( $app_config['auth_type'] ) ? $app_config['auth_type'] : '' ) .
			'|' . ( isset( $app_config['header_name'] ) ? $app_config['header_name'] : '' )
		);
	}

	/**
	 * Record a connection status snapshot for an app.
	 *
	 * Skips the meta write when the meaningful fields are unchanged, so the
	 * per-chat tool-registration path does not rewrite post meta on every
	 * request.
	 *
	 * @since 1.9.1
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $app_config   MCP App configuration.
	 * @param array $status       Status fields: last_status, last_error, tool_count, protocol, server_name, latency_ms.
	 * @return bool True when the status was stored.
	 */
	public function record_app_status( $assistant_id, array $app_config, array $status ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id || empty( $app_config['server_url'] ) ) {
			return false;
		}

		$key = $this->get_app_status_key( $app_config );
		$all = $this->get_app_status( $assistant_id );

		$defaults             = array(
			'last_status' => '',
			'last_error'  => '',
			'tool_count'  => null,
			'protocol'    => '',
			'server_name' => '',
			'latency_ms'  => null,
			'checked_at'  => 0,
		);
		$status               = array_intersect_key( wp_parse_args( $status, $defaults ), $defaults );
		$status['checked_at'] = time();

		// Skip the write when nothing meaningful changed (e.g. every chat
		// request re-recording an identical success).
		if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
			$previous_snap = array_intersect_key( $all[ $key ], $defaults );
			unset( $previous_snap['checked_at'] );
			$new_snap = $status;
			unset( $new_snap['checked_at'] );
			if ( $previous_snap === $new_snap ) {
				return true;
			}
		}

		$all[ $key ] = $status;
		update_post_meta( $assistant_id, self::STATUS_META_KEY, $all );

		return true;
	}

	/**
	 * Get connection status snapshots for an assistant.
	 *
	 * @since 1.9.1
	 * @param int $assistant_id Assistant post ID.
	 * @return array Status entries keyed by md5 connection identity.
	 */
	public function get_app_status( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return array();
		}

		$status = get_post_meta( $assistant_id, self::STATUS_META_KEY, true );

		return is_array( $status ) ? $status : array();
	}
}
