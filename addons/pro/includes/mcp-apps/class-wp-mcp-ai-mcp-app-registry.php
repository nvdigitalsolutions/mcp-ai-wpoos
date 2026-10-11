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
	 * Cache TTL for successful discovery results.
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Negative-cache TTL for failed discovery attempts.
	 *
	 * Prevents a down/unreachable app server from stalling every chat request
	 * with a fresh handshake timeout.
	 *
	 * @var int
	 */
	const FAILURE_CACHE_TTL = 60;

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

		// Credentials are stored encrypted at rest; decrypt them for consumers.
		// Legacy plaintext rows decrypt as themselves (idempotent).
		foreach ( $apps as $index => $app ) {
			if ( is_array( $app ) ) {
				$apps[ $index ] = self::decrypt_app_secrets( $app );
			}
		}

		return $apps;
	}

	/**
	 * Load the Remote Site Manager so its crypto helpers are available.
	 *
	 * @since 1.9.x
	 * @return bool True when encrypt_value()/decrypt_value() are callable.
	 */
	protected static function maybe_load_crypto() {
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return true;
		}

		// Prefer the canonical addon constant; fall back to this file's
		// location (addons/pro/includes/mcp-apps/ -> addons/pro/).
		$manager_file = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php'
			: dirname( __DIR__, 2 ) . '/includes/class-wp-mcp-ai-pro-remote-site-manager.php';

		if ( file_exists( $manager_file ) ) {
			require_once $manager_file;
		}

		return class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
	}

	/**
	 * Encrypt the secret fields of an inline app config for storage.
	 *
	 * Uses the same AES-256-CBC scheme as the Remote Sites store (with a
	 * legacy XOR fallback), so credentials share one format across Pro.
	 * Values already encrypted are left untouched. Reference entries carry
	 * no inline secrets and pass through unchanged.
	 *
	 * @since 1.9.x
	 * @param array $app App configuration (sanitized, plaintext secrets).
	 * @return array App configuration with secrets encrypted.
	 */
	protected static function encrypt_app_secrets( array $app ) {
		if ( ! self::maybe_load_crypto() ) {
			return $app;
		}

		$manager = 'WP_MCP_AI_Pro_Remote_Site_Manager';

		if ( ! empty( $app['token'] ) && ! $manager::is_value_encrypted( (string) $app['token'] ) ) {
			$encrypted = $manager::encrypt_value( (string) $app['token'] );
			if ( '' !== $encrypted ) {
				$app['token'] = $encrypted;
			}
		}

		foreach ( array( 'access_token', 'refresh_token' ) as $field ) {
			if ( empty( $app['oauth_data'][ $field ] ) || $manager::is_value_encrypted( (string) $app['oauth_data'][ $field ] ) ) {
				continue;
			}
			$encrypted = $manager::encrypt_value( (string) $app['oauth_data'][ $field ] );
			if ( '' !== $encrypted ) {
				$app['oauth_data'][ $field ] = $encrypted;
			}
		}

		return $app;
	}

	/**
	 * Decrypt the secret fields of a stored app config.
	 *
	 * Idempotent for legacy plaintext values: decrypt_value() returns
	 * non-encrypted strings unchanged.
	 *
	 * @since 1.9.x
	 * @param array $app Stored app configuration.
	 * @return array App configuration with secrets decrypted.
	 */
	protected static function decrypt_app_secrets( array $app ) {
		if ( ! self::maybe_load_crypto() ) {
			return $app;
		}

		$manager = 'WP_MCP_AI_Pro_Remote_Site_Manager';

		if ( ! empty( $app['token'] ) ) {
			$app['token'] = $manager::decrypt_value( (string) $app['token'] );
		}

		foreach ( array( 'access_token', 'refresh_token' ) as $field ) {
			if ( ! empty( $app['oauth_data'][ $field ] ) ) {
				$app['oauth_data'][ $field ] = $manager::decrypt_value( (string) $app['oauth_data'][ $field ] );
			}
		}

		return $app;
	}

	/**
	 * Read the stored OAuth token data for a single app.
	 *
	 * Used by the tool bridge to re-hydrate the registration-time config
	 * snapshot with the freshest credentials before executing a remote
	 * tool, so a refresh persisted by a previous call is not lost.
	 *
	 * @since 1.9.x
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $server_url   MCP server URL of the app.
	 * @return array|null Stored oauth_data array, or null when the app is
	 *                    not found or is a centrally managed reference entry.
	 */
	public function get_stored_oauth_data( $assistant_id, $server_url ) {
		foreach ( $this->get_apps( $assistant_id ) as $app ) {
			if ( ! is_array( $app ) || empty( $app['server_url'] ) || $app['server_url'] !== $server_url ) {
				continue;
			}

			// Reference entries resolve their credentials centrally; the
			// post meta never carries oauth_data for them.
			if ( ! empty( $app['connection_ref'] ) ) {
				return null;
			}

			return isset( $app['oauth_data'] ) && is_array( $app['oauth_data'] ) ? $app['oauth_data'] : array();
		}

		return null;
	}

	/**
	 * Persist refreshed OAuth token data.
	 *
	 * Called after an automatic token refresh so rotated access/refresh
	 * tokens survive the current request.
	 *
	 * Inline apps are updated in post meta. Centrally managed reference
	 * entries (matched by $connection_ref) are updated in the encrypted
	 * Remote Sites store instead — their credentials never touch post meta.
	 *
	 * @since 1.9.x
	 * @param int    $assistant_id   Assistant post ID (0 when unknown).
	 * @param string $server_url     MCP server URL identifying the inline app.
	 * @param array  $oauth_data     Fresh token data from the OAuth client.
	 * @param string $connection_ref Central connection ID for reference entries.
	 * @return bool True when the stored credential changed (or was current).
	 */
	public function update_app_oauth_data( $assistant_id, $server_url, array $oauth_data, $connection_ref = '' ) {
		if ( empty( $oauth_data['access_token'] ) ) {
			return false;
		}

		// Central reference path: the credential lives in the Remote Sites
		// store and needs no assistant or URL context.
		if ( '' !== $connection_ref ) {
			return $this->update_reference_oauth_data( $connection_ref, $oauth_data );
		}

		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id || '' === (string) $server_url ) {
			return false;
		}

		$apps    = $this->get_apps( $assistant_id );
		$changed = false;

		foreach ( $apps as $index => $app ) {
			if ( ! is_array( $app ) ) {
				continue;
			}

			// Reference entries are never written back to post meta.
			if ( ! empty( $app['connection_ref'] ) ) {
				continue;
			}

			if ( empty( $app['server_url'] ) || $app['server_url'] !== $server_url ) {
				continue;
			}

			if ( 'oauth' !== ( isset( $app['auth_type'] ) ? $app['auth_type'] : '' ) ) {
				return false;
			}

			$existing = isset( $app['oauth_data'] ) && is_array( $app['oauth_data'] ) ? $app['oauth_data'] : array();
			$incoming = $oauth_data;

			// Some providers omit scope from the refresh response. Preserve the
			// stored value so the metabox "Scope: …" label does not degrade.
			if ( empty( $incoming['scope'] ) && ! empty( $existing['scope'] ) ) {
				$incoming['scope'] = $existing['scope'];
			}

			$merged = array_merge( $existing, $incoming );

			// No-op when nothing changed (e.g. an in-window refresh attempt).
			if ( $merged != $existing ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Loose comparison ignores key order.
				$apps[ $index ]['oauth_data'] = $merged;
				$changed                      = true;
			}
			break;
		}

		if ( ! $changed ) {
			return false;
		}

		return $this->save_apps( $assistant_id, $apps );
	}

	/**
	 * Persist refreshed OAuth token data into the central Remote Sites store.
	 *
	 * @since 1.9.x
	 * @param string $connection_ref Central mcp_server connection ID.
	 * @param array  $oauth_data     Fresh token data from the OAuth client.
	 * @return bool True on success, false when the connection is unavailable.
	 */
	protected function update_reference_oauth_data( $connection_ref, array $oauth_data ) {
		if ( ! self::maybe_load_crypto() ) {
			return false;
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth( $connection_ref, $oauth_data );
	}

	/**
	 * Resolve an assistant's MCP Apps, expanding global connection references.
	 *
	 * Entries carrying `connection_ref` point at a centrally managed
	 * connection in the Pro Remote Sites store — a `mcp_server` connection, an
	 * Upwork connection running in MCP mode, or a FlowHub connection running
	 * in MCP mode. Resolution decrypts the
	 * central credential on demand (per-request static cache) and merges it
	 * into a runtime config — the credentials are never written back to post
	 * meta.
	 *
	 * A reference that cannot be resolved (missing connection, wrong type, or
	 * Remote Sites unavailable) is skipped and recorded as an error status
	 * snapshot keyed by the reference identity.
	 *
	 * @since 1.1.85
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array<int, array> Runtime app configs (resolved).
	 */
	public function resolve_apps( $assistant_id ) {
		$resolved = array();

		foreach ( $this->get_apps( $assistant_id ) as $app ) {
			if ( ! is_array( $app ) ) {
				continue;
			}

			if ( empty( $app['connection_ref'] ) ) {
				$resolved[] = $app;
				continue;
			}

			$ref_app = $this->resolve_connection_ref( $app );
			if ( null === $ref_app ) {
				$this->record_app_status(
					$assistant_id,
					$app,
					array(
						'last_status' => 'error',
						'last_error'  => __( 'connection_ref not found in Remote Sites', 'mcp-ai-wpoos-pro' ),
					)
				);
				if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'log_warning' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'MCP App reference could not be resolved: connection not found in Remote Sites.',
						array(
							'assistant_id'   => $assistant_id,
							'connection_ref' => isset( $app['connection_ref'] ) ? $app['connection_ref'] : '',
						)
					);
				}
				continue;
			}

			$resolved[] = $ref_app;
		}

		return $resolved;
	}

	/**
	 * Resolve a single `connection_ref` entry against the Remote Sites store.
	 *
	 * Accepts `mcp_server` connections, Upwork connections in MCP mode (the
	 * official Upwork MCP gateway), and FlowHub connections in MCP mode (the
	 * official FlowHub MCP gateway).
	 *
	 * @since 1.1.85
	 * @since 1.1.90 Upwork MCP connections resolve against the official gateway.
	 * @since 1.1.92 FlowHub MCP connections resolve against the official gateway.
	 *
	 * @param array $app Stored MCP App entry with `connection_ref` set.
	 * @return array|null Resolved runtime config, or null when unresolvable.
	 */
	protected function resolve_connection_ref( array $app ) {
		$ref = isset( $app['connection_ref'] ) ? sanitize_key( (string) $app['connection_ref'] ) : '';

		if ( '' === $ref ) {
			return null;
		}

		// Lazy-load the Remote Site Manager — chat-time resolution must not
		// depend on the admin bootstrap having loaded it earlier.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			if ( file_exists( $manager_file ) ) {
				require_once $manager_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return null;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $ref );

		if ( null === $connection ) {
			return null;
		}

		$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';

		if ( 'mcp_server' === $connection_type ) {
			$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_mcp_app_config_from_connection( $connection );
		} elseif ( 'upwork' === $connection_type && 'mcp' === ( isset( $connection['upwork_mode'] ) ? $connection['upwork_mode'] : '' ) ) {
			// Upwork MCP connections authenticate against the official gateway
			// through the MCP Apps OAuth flow; the token blob lives in the
			// encrypted central `mcp_oauth` field like any MCP Server connection.
			$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_upwork_mcp_app_config( $connection );
		} elseif ( 'flowhub' === $connection_type && 'mcp' === ( isset( $connection['flowhub_mode'] ) ? $connection['flowhub_mode'] : '' ) ) {
			// FlowHub MCP connections authenticate against the official gateway
			// through the MCP Apps OAuth flow; the token blob lives in the
			// encrypted central `mcp_oauth` field like any MCP Server connection.
			$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_flowhub_mcp_app_config( $connection );
		} else {
			return null;
		}

		return array_merge(
			$app,
			$config,
			array(
				'label'          => ! empty( $app['label'] ) ? $app['label'] : ( isset( $connection['name'] ) ? $connection['name'] : $ref ),
				'connection_ref' => $ref,
			)
		);
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
			// Keep entries with a usable endpoint OR a global connection
			// reference (reference entries resolve the URL at chat time).
			if ( ! empty( $sanitized['server_url'] ) || ! empty( $sanitized['connection_ref'] ) ) {
				$sanitized_apps[] = $sanitized;
			}
		}

		// Encrypt inline credentials at rest (AES-256-CBC, same scheme as the
		// Remote Sites store). Reference entries carry no inline secrets.
		$encrypted_apps = array();
		foreach ( $sanitized_apps as $app ) {
			$encrypted_apps[] = self::encrypt_app_secrets( $app );
		}

		if ( empty( $encrypted_apps ) ) {
			delete_post_meta( $assistant_id, self::META_KEY );
		} else {
			update_post_meta( $assistant_id, self::META_KEY, $encrypted_apps );
		}

		// Prune connection status records for apps that no longer exist.
		$kept_keys = array();
		foreach ( $encrypted_apps as $app ) {
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

		// The /tools REST list cache may hold pre-bridge listings for this
		// assistant; invalidate it so new apps surface immediately.
		if ( class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			WP_MCP_AI_REST_Cache::invalidate_endpoint( 'tools' );
		}

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
			'label'          => isset( $app['label'] ) ? sanitize_text_field( $app['label'] ) : '',
			'server_url'     => $server_url,
			'auth_type'      => isset( $app['auth_type'] ) && in_array( $app['auth_type'], array( 'none', 'bearer', 'basic', 'header', 'oauth' ), true )
				? $app['auth_type']
				: 'none',
			'token'          => isset( $app['token'] ) ? sanitize_text_field( $app['token'] ) : '',
			'header_name'    => isset( $app['header_name'] ) ? sanitize_text_field( $app['header_name'] ) : '',
			'connection_ref' => isset( $app['connection_ref'] ) ? sanitize_key( $app['connection_ref'] ) : '',
			'enabled'        => isset( $app['enabled'] ) ? (bool) $app['enabled'] : true,
			'timeout'        => isset( $app['timeout'] ) ? max( 1, min( 120, absint( $app['timeout'] ) ) ) : 30,
			'verify_ssl'     => isset( $app['verify_ssl'] ) ? (bool) $app['verify_ssl'] : true,
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
				// Dynamic client ID required for public-client token/refresh
				// requests (e.g. Upwork).
				'client_id'     => isset( $app['oauth_data']['client_id'] ) ? sanitize_text_field( $app['oauth_data']['client_id'] ) : '',
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
	 * for automatic token management and refresh. When $assistant_id is
	 * provided, a successful in-flight token refresh is persisted back to
	 * the assistant's stored app config so the rotated credentials survive
	 * the current request.
	 *
	 * @since 1.8.0
	 * @since 1.9.x Added the $assistant_id parameter for refresh persistence.
	 * @param array $app_config   MCP App configuration.
	 * @param int   $assistant_id Assistant post ID the app belongs to (0 = no persistence).
	 * @return WP_MCP_AI_MCP_App_Client
	 */
	public function create_client( array $app_config, $assistant_id = 0 ) {
		$config = $app_config;

		if ( absint( $assistant_id ) ) {
			$config['assistant_id'] = absint( $assistant_id );
		}

		// For OAuth apps, ensure the token is populated from oauth_data.
		if ( 'oauth' === ( $config['auth_type'] ?? 'none' ) ) {
			if ( empty( $config['token'] ) && ! empty( $config['oauth_data']['access_token'] ) ) {
				$config['token'] = $config['oauth_data']['access_token'];
			}

			// Attach OAuth client for auto-refresh.
			if ( class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
				$oauth_options = array(
					'timeout'    => isset( $config['timeout'] ) ? absint( $config['timeout'] ) : 30,
					'verify_ssl' => isset( $config['verify_ssl'] ) ? (bool) $config['verify_ssl'] : true,
				);

				// Mirror the client's outbound proxy onto the OAuth client so
				// discovery and token refresh honor it too (geo-blocked
				// gateways such as FlowHub).
				if ( ! empty( $config['proxy_url'] ) ) {
					$oauth_options['proxy_url']  = (string) $config['proxy_url'];
					$oauth_options['proxy_auth'] = isset( $config['proxy_auth'] ) ? (string) $config['proxy_auth'] : '';
				}

				$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $config['server_url'], $oauth_options );
				if ( ! empty( $config['oauth_data'] ) && is_array( $config['oauth_data'] ) ) {
					$oauth_client->set_token_data( $config['oauth_data'] );
					// Public clients (e.g. Upwork) require the client ID in
					// token/refresh requests.
					if ( ! empty( $config['oauth_data']['client_id'] ) ) {
						$oauth_client->set_client_id( $config['oauth_data']['client_id'] );
					}
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
				$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $entry['app_config'], $entry['label'], $assistant_id );
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
				$bridge  = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $entry['app_config'], $entry['label'], $assistant_id );
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
		$apps = $this->resolve_apps( $assistant_id );

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

			$tools = $this->discover_tools( $app_config, false, $assistant_id );

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
	 * Uses transient caching to avoid repeated requests. Dialect negotiation
	 * is delegated to {@see WP_MCP_AI_MCP_App_Client::handshake()}: the
	 * stateless server/discover probe runs first, falling back to the legacy
	 * sessionful initialize handshake (which captures Mcp-Session-Id) for
	 * pre-2026-07-28 servers. A cached legacy-dialect hint skips the probe
	 * once the server is known to reject it.
	 *
	 * @since 1.8.0
	 * @since 1.9.1 Added sessionful fallback and $refresh parameter.
	 * @since 1.9.5 Handshake delegated to the client's handshake() (legacy-dialect hint).
	 * @param array $app_config   MCP App configuration.
	 * @param bool  $refresh      Whether to bypass the transient cache.
	 * @param int   $assistant_id Assistant post ID (0 = no refresh persistence).
	 * @return array|WP_Error Array of tool definitions or WP_Error.
	 */
	public function discover_tools( array $app_config, $refresh = false, $assistant_id = 0 ) {
		$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			// Short negative cache: a down/unreachable server must not force
			// every chat request to wait out a fresh handshake timeout.
			$failure = get_transient( $cache_key . '_err' );
			if ( false !== $failure && is_string( $failure ) ) {
				return new WP_Error( 'wp_mcp_ai_mcp_app_discovery_failed', $failure );
			}
		}

		$client = $this->create_client( $app_config, $assistant_id );

		// The client's handshake() encapsulates the probe/fallback logic and
		// the cached legacy-dialect hint, so discovery here is a single call.
		$handshake = $client->handshake();

		if ( is_wp_error( $handshake ) ) {
			$this->cache_discovery_failure( $cache_key, $handshake );
			return $handshake;
		}

		$tools = $client->list_tools();
		if ( is_wp_error( $tools ) ) {
			$this->cache_discovery_failure( $cache_key, $tools );
			return $tools;
		}

		// Cache the results, capped at the server-declared freshness bound
		// (SEP-2549 ttlMs) so servers that change their catalog are
		// re-polled on their own schedule. An absent or zero ttlMs keeps
		// the default TTL: re-discovering on every chat turn would add
		// two handshake round trips per app.
		$ttl = self::resolve_cache_ttl( $client->get_last_tools_ttl_ms() );

		set_transient( $cache_key, $tools, $ttl );
		delete_transient( $cache_key . '_err' );

		return $tools;
	}

	/**
	 * Resolve a discovery cache TTL from a server-declared ttlMs.
	 *
	 * A positive server TTL caps the default at the server's freshness
	 * bound; an absent or zero TTL keeps the default. Pure helper —
	 * unit-tested directly.
	 *
	 * @since 2.x.0
	 *
	 * @param int $server_ttl_ms Server-declared cache TTL in milliseconds.
	 * @return int Cache TTL in seconds.
	 */
	public static function resolve_cache_ttl( $server_ttl_ms ) {
		$ttl = self::CACHE_TTL;

		$server_ttl_ms = absint( $server_ttl_ms );
		if ( $server_ttl_ms > 0 ) {
			$ttl = min( $ttl, max( 1, (int) ceil( $server_ttl_ms / 1000 ) ) );
		}

		return $ttl;
	}

	/**
	 * Record a discovery failure in the short negative cache.
	 *
	 * @since 1.9.4
	 * @param string   $cache_key Discovery transient key.
	 * @param WP_Error $error     Discovery error.
	 * @return void
	 */
	protected function cache_discovery_failure( $cache_key, WP_Error $error ) {
		set_transient( $cache_key . '_err', $error->get_error_message(), self::FAILURE_CACHE_TTL );
	}

	/**
	 * Clear the tool cache for an assistant.
	 *
	 * @since 1.8.0
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public function clear_tool_cache( $assistant_id ) {
		$apps = $this->get_apps( absint( $assistant_id ) );

		if ( ! is_array( $apps ) ) {
			return;
		}

		foreach ( $apps as $app_config ) {
			$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );
			delete_transient( $cache_key );
			delete_transient( $cache_key . '_err' );
		}
	}

	/**
	 * Invalidate the discovery cache for every app pointing at a server URL.
	 *
	 * Called when the remote server pushes `notifications/tools/list_changed`
	 * (via {@see WP_MCP_AI_MCP_App_Client::dispatch_sse_notifications()}) so
	 * the next request re-discovers instead of serving a stale catalog — the
	 * same failure mode measured by the M3 quirks matrix.
	 *
	 * @since 2.x.0
	 *
	 * @param string $server_url Remote MCP server URL.
	 * @return void
	 */
	public function invalidate_discovery_for_url( $server_url ) {
		$server_url = esc_url_raw( (string) $server_url );

		if ( '' === $server_url ) {
			return;
		}

		$assistant_ids = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'any',
				'posts_per_page' => 250, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Scoped, low-cardinality invalidation sweep.
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- scoped, low-cardinality invalidation sweep.
				'fields'         => 'ids',
			)
		);

		foreach ( $assistant_ids as $assistant_id ) {
			$apps = $this->get_apps( $assistant_id );

			foreach ( $apps as $app_config ) {
				$app_url = isset( $app_config['server_url'] ) ? esc_url_raw( (string) $app_config['server_url'] ) : '';

				if ( '' === $app_url || $app_url !== $server_url ) {
					continue;
				}

				$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );
				delete_transient( $cache_key );
				delete_transient( $cache_key . '_err' );
			}
		}
	}

	/**
	 * Handle a JSON-RPC notification pushed by a remote MCP server.
	 *
	 * Subscribed to {@see 'wp_mcp_ai_remote_mcp_notification'} in
	 * mcp-apps-init.php.
	 *
	 * @since 2.x.0
	 *
	 * @param string $method     Notification method.
	 * @param array  $params     Notification params.
	 * @param string $server_url Remote server URL.
	 * @return void
	 */
	public function handle_remote_notification( $method, $params, $server_url ) {
		unset( $params );

		if ( 'notifications/tools/list_changed' !== $method ) {
			return;
		}

		$this->invalidate_discovery_for_url( $server_url );
	}

	/**
	 * Test connection to a specific MCP App.
	 *
	 * @since 1.8.0
	 * @param array $app_config   MCP App configuration.
	 * @param int   $assistant_id Assistant post ID (0 = no refresh persistence).
	 * @return array|WP_Error Connection test result.
	 */
	public function test_connection( array $app_config, $assistant_id = 0 ) {
		$client = $this->create_client( $app_config, $assistant_id );
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
			'|' . ( isset( $app_config['header_name'] ) ? $app_config['header_name'] : '' ) .
			'|' . ( isset( $app_config['connection_ref'] ) ? $app_config['connection_ref'] : '' )
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
		if ( ! $assistant_id || ( empty( $app_config['server_url'] ) && empty( $app_config['connection_ref'] ) ) ) {
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
