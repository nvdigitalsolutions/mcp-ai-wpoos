<?php
/**
 * Global Helper Functions
 *
 * Early-available utility functions used throughout the plugin and during the
 * class-loading chain (before the DI container and service layer are available).
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	/**
	 * Check if Open Operator System (NV oOS) Core is loaded.
	 *
	 * This function serves as a marker for add-ons (like Open Operator System Pro) to verify that
	 * the core plugin is active and ready before registering their features.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always returns true when plugin is loaded.
	 */
	function wp_mcp_ai_core_loaded() {
		return true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
	/**
	 * Check if the private/custom base-mode entry point is active.
	 *
	 * Returns true only when WP_MCP_AI_BASE_VERSION is explicitly set to true,
	 * which happens via mcp-ai-wpoos-base.php. That entry point is excluded from
	 * the WordPress.org distribution ZIP (.distignore) so it never fires for
	 * WordPress.org users — all tools are always available there.
	 *
	 * This function is NOT used to gate AI tools. It is used solely by internal
	 * helpers that need to conditionally show settings for Pro addon features
	 * (e.g. Site Creator, JetEngine CPT AI) that are provided by the Pro addon
	 * and are absent from a private base-only build.
	 *
	 * @return bool True only when the private base-mode entry point is active.
	 */
	function wp_mcp_ai_is_base_version() {
		return defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION;
	}
}

if ( ! function_exists( 'wp_mcp_ai_is_jetengine_available' ) ) {
	/**
	 * Check if JetEngine plugin is available and active.
	 *
	 * @since 1.1.1
	 * @return bool Whether JetEngine is available.
	 */
	function wp_mcp_ai_is_jetengine_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}
}

if ( ! function_exists( 'wp_mcp_ai_should_load_integrations' ) ) {
	/**
	 * Determine whether third-party plugin integrations should be loaded.
	 *
	 * Integration classes are always loaded — they guard themselves against
	 * missing dependencies internally. This ensures that tools for WooCommerce,
	 * JetEngine, Cloudways, QuickBooks, etc. are available to any site that has
	 * those plugins active, regardless of whether the Pro addon is installed.
	 *
	 * The Pro addon (PHP 8.1+) adds genuinely new tools on top of these; it does
	 * not "unlock" tools that are already present in the base plugin.
	 *
	 * @since 1.1.0
	 * @return bool Always true — integration files are always loaded.
	 */
	function wp_mcp_ai_should_load_integrations() {
		return true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_required_chat_capability' ) ) {
	/**
	 * Retrieve the capability required to access the chat interface.
	 *
	 * Site owners can filter the returned capability to relax access controls.
	 * For example, allow subscribers (with the `read` capability) or even
	 * unauthenticated visitors by returning `'public'` or an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID, when known.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_required_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );
		$context      = $context ? sanitize_key( $context ) : 'general';

		/**
		 * Filters the capability required to use the front-end chat interface.
		 *
		 * Returning `'public'`, `false`, or an empty string disables the capability
		 * check, making the chat available to all visitors who satisfy the
		 * authentication requirements.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability  Capability required to access the chat. Defaults to `edit_posts`.
		 * @param int    $assistant_id Assistant post ID, when available.
		 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
		 */
		$capability = apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', $assistant_id, $context );

		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return $capability;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_effective_chat_capability' ) ) {
	/**
	 * Get the effective capability required for a specific assistant.
	 *
	 * This function checks the assistant's required_capability meta first,
	 * then falls back to the global capability filter.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_effective_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );

		// Check if assistant has a specific capability requirement.
		if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$required_capability = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY, true );

			if ( is_string( $required_capability ) ) {
				$required_capability = WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( $required_capability );

				// If assistant has a specific capability set (even if empty), use it.
				if ( '' !== $required_capability ) {
					return $required_capability;
				}
			}
		}

		// Fall back to the global capability setting.
		return wp_mcp_ai_get_required_chat_capability( $assistant_id, $context );
	}
}

if ( ! function_exists( 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	/**
	 * Provide a fallback Crawl4AI base URL from the environment when available.
	 *
	 * @param string $base_url Base URL stored in the plugin settings.
	 * @param array  $settings Entire plugin settings array.
	 * @param array  $context  Execution context passed to the tool.
	 * @return string
	 */
	function wp_mcp_ai_filter_crawl4ai_base_url( $base_url, $settings, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter callback signature requires these parameters.
		if ( ! empty( $base_url ) ) {
			return $base_url;
		}

		if ( defined( 'WP_MCP_AI_CRAWL4AI_BASE_URL' ) && WP_MCP_AI_CRAWL4AI_BASE_URL ) {
			return WP_MCP_AI_CRAWL4AI_BASE_URL;
		}

		$environment_candidates = array(
			'WP_MCP_AI_CRAWL4AI_BASE_URL',
			'CRAWL4AI_BASE_URL',
		);

		foreach ( $environment_candidates as $env_key ) {
			$candidate = getenv( $env_key );
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return $candidate;
			}
		}

		return $base_url;
	}
}

if ( ! has_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	add_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url', 5, 3 );
}

if ( ! function_exists( 'wp_mcp_ai_user_can_manage_fleet' ) ) {
	/**
	 * Check whether the current user has the capability to manage fleet-wide
	 * operations (federation, asset inventory, dependency scanning).
	 *
	 * On a single-site installation `manage_options` (site admin) is sufficient.
	 * On a multisite network these operations affect the whole network, so
	 * `manage_network_options` (super admin) is required instead.
	 *
	 * The cap can be overridden via the `wp_mcp_ai_fleet_capability` filter
	 * when custom role configurations are in use.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the current user may manage fleet operations.
	 */
	function wp_mcp_ai_user_can_manage_fleet() {
		$default_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filters the capability required for fleet-wide management operations.
		 *
		 * Allows custom role configurations to adjust the required capability.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability slug. Default `'manage_network_options'`
		 *                           on multisite, `'manage_options'` on single-site.
		 */
		$cap = apply_filters( 'wp_mcp_ai_fleet_capability', $default_cap );
		$cap = is_string( $cap ) ? sanitize_key( $cap ) : $default_cap;

		return current_user_can( $cap );
	}
}

if ( ! function_exists( 'wp_mcp_ai_fleet_capability' ) ) {
	/**
	 * Return the capability string required to manage fleet operations.
	 *
	 * Identical to `wp_mcp_ai_user_can_manage_fleet()` but returns the cap slug
	 * rather than a boolean — useful when passing a capability to WordPress API
	 * functions such as `add_submenu_page()` that accept a string.
	 *
	 * @since 1.0.0
	 *
	 * @return string Capability slug.
	 */
	function wp_mcp_ai_fleet_capability() {
		$default_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

		/** This filter is documented in includes/bootstrap/helpers.php */
		$cap = apply_filters( 'wp_mcp_ai_fleet_capability', $default_cap );

		return ( is_string( $cap ) && '' !== $cap ) ? sanitize_key( $cap ) : $default_cap;
	}
}

if ( ! function_exists( 'wp_mcp_ai_run_process' ) ) {
	/**
	 * Run an external process using proc_open with array-form argv (no shell expansion).
	 *
	 * This is the only approved way to spawn external processes inside the plugin.
	 * Using array-form argv with proc_open bypasses the shell entirely: the OS
	 * executes the binary directly, so special characters in arguments cannot be
	 * interpreted as shell metacharacters — even without escapeshellarg().
	 *
	 * Security notes:
	 * - The caller is responsible for ensuring $args[0] is an absolute or
	 *   whitelisted binary path.
	 * - The working directory should be set to the plugin directory or a temp dir,
	 *   never to an attacker-controlled path.
	 * - Do NOT call this function unless WP_MCP_AI_ALLOW_SHELL_TOOLS is true AND
	 *   the current user has `manage_options` capability.
	 *
	 * @since 1.1.9
	 *
	 * @param array       $args     Command and arguments as an array, e.g. array('git', 'status').
	 *                              $args[0] must be the binary; subsequent entries are arguments.
	 * @param string|null $cwd      Working directory or null to keep the current directory.
	 * @param int         $timeout  Seconds before the child process is killed. Default 30.
	 * @return array {
	 *     @type string $stdout    Captured standard output.
	 *     @type string $stderr    Captured standard error.
	 *     @type int    $exit_code Process exit status (0 = success).
	 *     @type bool   $success   True when exit_code is 0.
	 *     @type bool   $timed_out True when the process was killed due to timeout.
	 * }
	 */
	function wp_mcp_ai_run_process( array $args, $cwd = null, $timeout = 30 ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ), // stdin.
			1 => array( 'pipe', 'w' ), // stdout.
			2 => array( 'pipe', 'w' ), // stderr.
		);

		// proc_open() accepts an array for $command only on PHP 7.4+ (the minimum
		// for this plugin), which avoids shell expansion entirely.
		$process = proc_open( $args, $descriptors, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
				// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- Not a tool execute() return; process-result utility function.
				return array(
					'stdout'    => '',
					'stderr'    => 'Failed to start process.',
					'exit_code' => -1,
					'success'   => false,
					'timed_out' => false,
				);
		}

			// Close stdin immediately — we don't need it.
			fclose( $pipes[0] );

			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$stdout    = '';
			$stderr    = '';
			$start     = microtime( true );
			$timed_out = false;

		while ( true ) {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}

			if ( ( microtime( true ) - $start ) >= $timeout ) {
				$timed_out = true;
				proc_terminate( $process );
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}

			// Read partial output to prevent pipe buffer deadlock.
			$chunk = fread( $pipes[1], 8192 );
			if ( false !== $chunk ) {
				$stdout .= $chunk;
			}
			$chunk = fread( $pipes[2], 8192 );
			if ( false !== $chunk ) {
				$stderr .= $chunk;
			}

			usleep( 10000 ); // 10 ms.
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => $exit_code,
			'success'   => 0 === $exit_code && ! $timed_out,
			'timed_out' => $timed_out,
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_find_binary' ) ) {
	/**
	 * Probe whether a system binary exists by running `binary --version` (or the
	 * caller-supplied probe argument) through proc_open — no shell involved.
	 *
	 * Replaces the pattern `shell_exec('which binary 2>/dev/null')` that several
	 * Pro tools used for binary detection.
	 *
	 * @since 1.1.9
	 *
	 * @param string $binary       Binary name (e.g. 'wkhtmltopdf', 'pdftk', 'git').
	 * @param string $probe_arg    Argument to pass to the binary to test it exits 0.
	 *                             Defaults to '--version'.
	 * @return bool True when the binary can be found and executed.
	 */
	function wp_mcp_ai_find_binary( $binary, $probe_arg = '--version' ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$result = wp_mcp_ai_run_process( array( $binary, $probe_arg ), null, 5 );
		return $result['ok'];
	}
}

if ( ! function_exists( 'wp_mcp_ai_run_shell' ) ) {
	/**
	 * Run a pre-built shell command string using proc_open (not exec/shell_exec).
	 *
	 * Use this ONLY when the command string has already been assembled with
	 * escapeshellarg() / escapeshellcmd() for all variable parts. Prefer the
	 * array-form wp_mcp_ai_run_process() for new code.
	 *
	 * @since 1.1.9
	 *
	 * @param string      $command  Shell command string (must be pre-escaped).
	 * @param string|null $cwd      Working directory or null for current directory.
	 * @param int         $timeout  Seconds before the child process is killed. Default 30.
	 * @return array Same keys as wp_mcp_ai_run_process().
	 */
	function wp_mcp_ai_run_shell( $command, $cwd = null, $timeout = 30 ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
				// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- Not a tool execute() return; process-result utility function.
				return array(
					'stdout'    => '',
					'stderr'    => 'Failed to start process.',
					'exit_code' => -1,
					'success'   => false,
					'timed_out' => false,
				);
		}

			fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$start     = microtime( true );
		$timed_out = false;

		while ( true ) {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}
			if ( ( microtime( true ) - $start ) >= $timeout ) {
				$timed_out = true;
				proc_terminate( $process );
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}
			$chunk = fread( $pipes[1], 8192 );
			if ( false !== $chunk ) {
				$stdout .= $chunk;
			}
			$chunk = fread( $pipes[2], 8192 );
			if ( false !== $chunk ) {
				$stderr .= $chunk;
			}
			usleep( 10000 );
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => $exit_code,
			'success'   => 0 === $exit_code && ! $timed_out,
			'timed_out' => $timed_out,
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_check_ajax_rate_limit' ) ) {
	/**
	 * Transient-based per-IP rate limiter for nopriv AJAX handlers.
	 *
	 * Uses the request IP to key a one-minute sliding-window counter.
	 * Calls `wp_send_json_error()` and exits when the limit is exceeded;
	 * otherwise returns void so the caller can continue normally.
	 *
	 * Usage (call immediately after check_ajax_referer):
	 *
	 *   wp_mcp_ai_check_ajax_rate_limit( 'my_action' );
	 *
	 * @param string $action      Short slug that identifies the protected action.
	 *                            Must be safe for use in transient keys.
	 * @param int    $max_per_min Maximum allowed requests per minute from a
	 *                            single IP address. Default 20.
	 */
	function wp_mcp_ai_check_ajax_rate_limit( $action, $max_per_min = 20 ) {
	// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- REMOTE_ADDR is set by the TCP stack, not the client.
		$ip_raw  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ip_hash = substr( md5( $ip_raw ), 0, 16 ); // Non-cryptographic; used only to shorten the transient key.
		$key     = 'wp_mcp_ai_rl_' . sanitize_key( $action ) . '_' . $ip_hash;

		/**
		 * Filter the per-action rate limit applied to nopriv AJAX handlers.
		 *
		 * @param int    $max_per_min Maximum requests per minute for this action.
		 * @param string $action      The action slug being rate-limited.
		 */
		$max_per_min = (int) apply_filters( 'wp_mcp_ai_ajax_rate_limit', $max_per_min, $action );

		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, MINUTE_IN_SECONDS );
			return;
		}

		if ( (int) $count >= $max_per_min ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many requests. Please slow down.', 'mcp-ai-wpoos' ),
					'code'    => 'rate_limit_exceeded',
				),
				429
			);
		}

		set_transient( $key, (int) $count + 1, MINUTE_IN_SECONDS );
	}
}

if ( ! function_exists( 'wp_mcp_ai_validate_path' ) ) {
	/**
	 * Resolve and bound-check a user-supplied file path.
	 *
	 * Prevents path-traversal attacks by resolving the path to its canonical
	 * real form and then verifying it starts with (i.e. is contained within)
	 * the supplied allowed root.
	 *
	 * Typical allowed roots:
	 *   - `wp_upload_dir()['basedir']`  — uploaded-file tools
	 *   - `WP_CONTENT_DIR`             — theme/plugin file tools
	 *   - `WP_MCP_AI_PATH`             — plugin-internal file tools
	 *
	 * Usage:
	 *   $safe = wp_mcp_ai_validate_path( $arguments['file_path'], wp_upload_dir()['basedir'] );
	 *   if ( is_wp_error( $safe ) ) { return $safe; }
	 *   // use $safe (the canonical absolute path)
	 *
	 * @param string $path         Path supplied by the caller (may be relative, symlinked, etc.).
	 * @param string $allowed_root Absolute directory that $path must be contained in.
	 * @return string|WP_Error Canonical absolute path on success; WP_Error on failure.
	 */
	function wp_mcp_ai_validate_path( $path, $allowed_root ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return new WP_Error(
				'wp_mcp_ai_empty_path',
				__( 'File path must not be empty.', 'mcp-ai-wpoos' )
			);
		}

		// realpath() resolves symlinks and eliminates `..` sequences.
		// It returns false if the file does not exist.
		$resolved = realpath( $path );
		if ( false === $resolved ) {
			return new WP_Error(
				'wp_mcp_ai_path_not_found',
				__( 'The specified file or directory does not exist.', 'mcp-ai-wpoos' )
			);
		}

		// Normalize both paths so cross-platform slash styles don't bypass the check.
		$resolved_norm = wp_normalize_path( $resolved );
		$root_norm     = trailingslashit( wp_normalize_path( (string) $allowed_root ) );

		if ( 0 !== strpos( $resolved_norm, $root_norm ) ) {
			return new WP_Error(
				'wp_mcp_ai_path_outside_root',
				__( 'The specified path is not within the allowed directory.', 'mcp-ai-wpoos' )
			);
		}

		return $resolved;
	}
}

if ( ! function_exists( 'wp_mcp_ai_is_safe_outbound_url' ) ) {
	/**
	 * Validate that a URL is safe to fetch as an outbound HTTP request.
	 *
	 * Thin wrapper around WP_MCP_AI_Url_Guard::validate() — the single
	 * canonical SSRF chokepoint. Guards against Server-Side Request Forgery
	 * (SSRF) by:
	 *  1. Requiring a valid http/https URL.
	 *  2. Blocking any host that resolves to a loopback, private-network,
	 *     or link-local IP address (including the AWS/GCP instance-metadata
	 *     endpoint 169.254.169.254).
	 *  3. DNS-resolving ALL A records for the hostname so DNS-rebinding to
	 *     a private IP on any record is also caught.
	 *  4. Blocking IPv6 loopback, link-local, and unique-local addresses.
	 *
	 * Operators may whitelist specific hostnames via the filter
	 * `wp_mcp_ai_http_allowed_host`.
	 *
	 * Usage:
	 *   if ( ! wp_mcp_ai_is_safe_outbound_url( $url ) ) {
	 *       return new WP_Error( 'wp_mcp_ai_unsafe_url', __( 'The URL resolves to a blocked address.', 'mcp-ai-wpoos' ) );
	 *   }
	 *
	 * @param string $url URL to validate.
	 * @return bool True if the URL is safe to fetch; false if it should be blocked.
	 */
	function wp_mcp_ai_is_safe_outbound_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
			// Fail closed when the guard class is unavailable.
			return false;
		}

		return ! is_wp_error( WP_MCP_AI_Url_Guard::validate( $url ) );
	}
}

if ( ! function_exists( 'wp_mcp_ai_remote_get' ) ) {
	/**
	 * SSRF-guarded wrapper around wp_remote_get().
	 *
	 * Validates the URL through WP_MCP_AI_Url_Guard before dispatching the
	 * request. Tools that fetch user- or agent-supplied URLs MUST use this
	 * wrapper (or wp_mcp_ai_remote_post()) instead of calling wp_remote_get()
	 * directly — enforced by the WPMCPAI.HTTP.RequireGuardedHttp PHPCS sniff.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional. wp_remote_get() arguments.
	 * @return array|WP_Error HTTP response array or WP_Error (blocked URL or transport error).
	 */
	function wp_mcp_ai_remote_get( $url, $args = array() ) {
		/**
		 * Filter: skip the URL guard for explicitly audited call sites.
		 *
		 * Intended ONLY for callers whose URLs are hardcoded provider
		 * endpoints (see the 2026-04 F-SSRF-01 audit register). Returning
		 * true bypasses validation — never use for user-supplied URLs.
		 *
		 * @param bool   $skip Whether to skip validation. Default false.
		 * @param string $url  The URL being fetched.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_http_skip_url_guard', false, $url ) ) {
			$check = WP_MCP_AI_Url_Guard::validate( $url );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout'     => 10,
				'redirection' => 3,
			)
		);

		return wp_remote_get( $url, $args );
	}
}

if ( ! function_exists( 'wp_mcp_ai_remote_post' ) ) {
	/**
	 * SSRF-guarded wrapper around wp_remote_post().
	 *
	 * @since 1.2.0
	 *
	 * @param string $url  URL to post to.
	 * @param array  $args Optional. wp_remote_post() arguments.
	 * @return array|WP_Error HTTP response array or WP_Error (blocked URL or transport error).
	 */
	function wp_mcp_ai_remote_post( $url, $args = array() ) {
		if ( ! apply_filters( 'wp_mcp_ai_http_skip_url_guard', false, $url ) ) {
			$check = WP_MCP_AI_Url_Guard::validate( $url );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout'     => 10,
				'redirection' => 3,
			)
		);

		return wp_remote_post( $url, $args );
	}
}

if ( ! function_exists( 'wp_mcp_ai_check_api_rate_limit' ) ) {
	/**
	 * Enforce a global (shared across all users) outbound API rate limit.
	 *
	 * Uses a transient to count calls in the current minute. Unlike
	 * `wp_mcp_ai_check_ajax_rate_limit()` (which is per-IP for nopriv AJAX),
	 * this helper is keyed only by the API slug so it represents the total
	 * call-rate the plugin makes to that external service per minute.
	 *
	 * Usage:
	 *   $err = wp_mcp_ai_check_api_rate_limit( 'yahoo_fantasy', 20 );
	 *   if ( is_wp_error( $err ) ) { return $err; }
	 *
	 * @param string $api_slug    Short identifier for the external API (e.g. 'yahoo_fantasy').
	 * @param int    $max_per_min Maximum outbound calls allowed per minute.
	 * @return null|WP_Error Null when the call is allowed; WP_Error when the limit is exceeded.
	 */
	function wp_mcp_ai_check_api_rate_limit( $api_slug, $max_per_min = 20 ) {
		$key = 'wp_mcp_ai_api_rl_' . sanitize_key( $api_slug );

		/**
		 * Filters the per-API outbound rate limit.
		 *
		 * @param int    $max_per_min Maximum calls per minute.
		 * @param string $api_slug    The API slug being rate-limited.
		 */
		$max_per_min = (int) apply_filters( 'wp_mcp_ai_api_rate_limit', $max_per_min, $api_slug );

		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, MINUTE_IN_SECONDS );
			return null;
		}

		if ( (int) $count >= $max_per_min ) {
			return new WP_Error(
				'wp_mcp_ai_api_rate_limit_exceeded',
				sprintf(
				/* translators: %s: external API name */
					__( 'Rate limit exceeded for the %s API. Please wait a moment and try again.', 'mcp-ai-wpoos' ),
					esc_html( $api_slug )
				)
			);
		}

		set_transient( $key, (int) $count + 1, MINUTE_IN_SECONDS );
		return null;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_temp_dir' ) ) {
	/**
	 * Return (and initialise) the plugin-owned temp directory.
	 *
	 * The directory sits inside the WordPress uploads folder so it shares the same
	 * filesystem permissions as other WordPress-managed files, is outside the
	 * system-wide /tmp, and can be locked down with an .htaccess rule to block
	 * direct HTTP access.
	 *
	 * Subsequent calls skip the filesystem work once the transient flag confirms
	 * the directory has already been prepared.
	 *
	 * @since 1.2.0
	 * @return string|WP_Error Absolute path with trailing slash, or WP_Error on failure.
	 */
	function wp_mcp_ai_get_temp_dir() {
		$upload_dir = wp_upload_dir( null, true, false );
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_temp_dir', $upload_dir['error'] );
		}

		$temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';

		// Check whether the directory has already been set up this request.
		static $initialised = false;
		if ( $initialised ) {
			return trailingslashit( $temp_dir );
		}

		if ( ! file_exists( $temp_dir ) ) {
			if ( ! wp_mkdir_p( $temp_dir ) ) {
				return new WP_Error(
					'wp_mcp_ai_temp_dir_create',
					sprintf(
					/* translators: %s: directory path */
						__( 'Could not create plugin temp directory: %s', 'mcp-ai-wpoos' ),
						esc_html( $temp_dir )
					)
				);
			}
			// Restrict directory permissions (0750 = owner rwx, group r-x, other ---).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
			@chmod( $temp_dir, 0750 );
		}

		// Drop an .htaccess to deny direct HTTP access on Apache hosts.
		$htaccess = $temp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
			@file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
		}

		$initialised = true;
		return trailingslashit( $temp_dir );
	}
}

if ( ! function_exists( 'wp_mcp_ai_tempnam' ) ) {
	/**
	 * Create a uniquely-named temp file inside the plugin temp directory.
	 *
	 * Mirrors the signature of PHP's {@see tempnam()} but writes into the
	 * plugin-owned directory returned by {@see wp_mcp_ai_get_temp_dir()}.
	 *
	 * @since 1.2.0
	 * @param string $prefix Short prefix for the filename.
	 * @param string $ext    Optional file extension including the leading dot, e.g. '.pdf'.
	 * @return string|WP_Error Absolute path to the newly created file, or WP_Error on failure.
	 */
	function wp_mcp_ai_tempnam( $prefix = 'tmp', $ext = '' ) {
		$dir = wp_mcp_ai_get_temp_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// Build a collision-free name: prefix + random hex + optional extension.
		$name      = sanitize_file_name( $prefix ) . wp_generate_password( 12, false ) . $ext;
		$unique    = wp_unique_filename( $dir, $name );
		$full_path = $dir . $unique;

		// Create the file immediately so the path is reserved (mirrors tempnam behaviour).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		if ( false === file_put_contents( $full_path, '' ) ) {
			return new WP_Error(
				'wp_mcp_ai_tempnam',
				sprintf(
				/* translators: %s: file path */
					__( 'Could not create temp file: %s', 'mcp-ai-wpoos' ),
					esc_html( $full_path )
				)
			);
		}

		return $full_path;
	}
}

if ( ! function_exists( 'wp_mcp_ai_log' ) ) :
	/**
	 * Convenience wrapper around WP_MCP_AI_Logger for recording log entries.
	 *
	 * Accepts a message string and an optional severity level so callers do not
	 * need to reach for the class directly.  Missing-function guards in callers
	 * mean this helper is optional, but defining it here ensures it exists early
	 * in the request lifecycle.
	 *
	 * @since 1.2.0
	 *
	 * @param string $message Human-readable log message.
	 * @param string $level   Severity / event type.  One of 'info', 'error',
	 *                        'warning', 'critical', 'debug', or an arbitrary
	 *                        event-type string.  Default 'info'.
	 */
	function wp_mcp_ai_log( $message, $level = 'info' ) {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		$level = strtolower( (string) $level );

		switch ( $level ) {
			case WP_MCP_AI_Logger::LEVEL_ERROR:
			case WP_MCP_AI_Logger::LEVEL_CRITICAL:
				WP_MCP_AI_Logger::log_error( $message );
				break;

			case WP_MCP_AI_Logger::LEVEL_WARNING:
				WP_MCP_AI_Logger::log_warning( $message );
				break;

			default:
				WP_MCP_AI_Logger::log_event( $level, $message );
				break;
		}
	}
	endif;

if ( ! function_exists( 'wp_mcp_ai_validate_ai_provider_url' ) ) :
	/**
	 * Validate that a URL targets an allowed AI provider host.
	 *
	 * Used by AJAX handlers that test connections to local AI providers
	 * (Ollama, LM Studio, iSAMS, etc.) to prevent SSRF to internal network
	 * services and cloud metadata endpoints.
	 *
	 * Allows localhost/127.0.0.1 by default (common for local AI).
	 * Blocks RFC 1918, loopback (except 127.0.0.1), link-local IPs,
	 * and known cloud metadata endpoints.
	 *
	 * @since 1.1.43
	 *
	 * @param string $url The URL to validate.
	 * @return true|WP_Error True if the URL is safe, WP_Error otherwise.
	 */
	function wp_mcp_ai_validate_ai_provider_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'Invalid URL provided.', 'mcp-ai-wpoos' )
			);
		}

		// Block known cloud metadata endpoints.
		$blocked_patterns = array(
			'169.254.169.254',
			'metadata.google.internal',
			'100.100.100.200',
		);

		foreach ( $blocked_patterns as $pattern ) {
			if ( false !== strpos( $host, $pattern ) ) {
				// Log blocked SSRF attempt to security audit log.
				if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
					WP_MCP_AI_Security_Audit_Logger::log_event(
						WP_MCP_AI_Security_Audit_Logger::EVENT_BLOCKED_SSRF,
						get_current_user_id(),
						array(
							'url'     => $url,
							'host'    => $host,
							'reason'  => 'cloud_metadata_endpoint',
							'pattern' => $pattern,
						)
					);
				}

				return new WP_Error(
					'wp_mcp_ai_blocked_host',
					__( 'Connections to cloud metadata services are not allowed.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Resolve hostname to IP and validate it is not a private/reserved address.
		$ip = gethostbyname( $host );

		// Allow localhost/127.0.0.1 and common Docker hostnames.
		$allowed_hosts = apply_filters(
			'wp_mcp_ai_allowed_ai_provider_hosts',
			array( 'localhost', '127.0.0.1', 'host.docker.internal' )
		);

		$allowed_ips = array_map( 'gethostbyname', $allowed_hosts );
		if ( in_array( $ip, $allowed_ips, true ) ) {
			return true;
		}

		// Reject URLs that resolve to private, reserved, or link-local IPs.
		if (
			false === filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			)
		) {
			// Log blocked SSRF attempt to security audit log.
			if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					WP_MCP_AI_Security_Audit_Logger::EVENT_BLOCKED_SSRF,
					get_current_user_id(),
					array(
						'url'    => $url,
						'host'   => $host,
						'ip'     => $ip,
						'reason' => 'private_or_reserved_ip',
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_blocked_host',
				sprintf(
					/* translators: %s: hostname */
					__( 'Connections to %s are not allowed. Use the wp_mcp_ai_allowed_ai_provider_hosts filter to whitelist this host.', 'mcp-ai-wpoos' ),
					esc_html( $host )
				)
			);
		}

		return true;
	}
	endif;

if ( ! function_exists( 'wp_mcp_ai_safe_unserialize' ) ) :
	/**
	 * Safely unserialize data, restricting to allowed classes only.
	 *
	 * Prefer this over maybe_unserialize() when deserializing stored data
	 * from the database (options, post meta, transients) to prevent PHP
	 * object injection if an attacker gains write access to the database.
	 *
	 * Falls back gracefully: returns the original data if unserialization
	 * fails or the data was not a serialized string.
	 *
	 * @since 1.1.43
	 *
	 * @param string|mixed $data Data that may be a serialized string.
	 * @return mixed Unserialized value, or $data if not a serialized string.
	 */
	function wp_mcp_ai_safe_unserialize( $data ) {
		if ( ! is_string( $data ) || '' === $data ) {
			return $data;
		}

		// Quick check: only process if it looks like a serialized string.
		if ( ! is_serialized( $data ) ) {
			return $data;
		}

		// Unserialize with no allowed classes (prevents object instantiation).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Safe: allowed_classes is explicitly set to false.
		$result = @unserialize( $data, array( 'allowed_classes' => false ) );

		// If unserialize fails and the string was not literally 'b:0;' (serialized
		// false), return the original data instead of false to avoid data loss.
		if ( false === $result && 'b:0;' !== $data ) {
			return $data;
		}

		return $result;
	}
	endif;

if ( ! function_exists( 'wp_mcp_ai_sanitize_filename_strict' ) ) :
	/**
	 * Sanitize a filename strictly for safe filesystem operations.
	 *
	 * Removes path traversal sequences, null bytes, control characters,
	 * and any character not in the safe set [a-zA-Z0-9._-]. Whitespace
	 * and other unsafe characters are replaced with underscores, multiple
	 * underscores are collapsed, and leading/trailing underscores and
	 * periods are trimmed. The result is truncated to 255 characters.
	 * If the sanitized result is empty, returns 'file'.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filename The filename to sanitize.
	 * @return string Sanitized filename safe for filesystem use.
	 */
	function wp_mcp_ai_sanitize_filename_strict( $filename ) {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- Intentional use of regex and string operations for filename sanitization.

		if ( ! is_string( $filename ) || '' === $filename ) {
			return 'file';
		}

		$original = $filename;

		// Remove path traversal sequences.
		$filename = str_replace( array( '../', '..\\' ), '', $filename );

		// Strip null bytes.
		$filename = str_replace( "\0", '', $filename );

		// Strip control characters (ASCII 0-31).
		$filename = preg_replace( '/[\x00-\x1F]/', '', $filename );

		// Replace any character NOT in [a-zA-Z0-9._-] with underscore.
		$filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename );

		// Collapse multiple underscores into one.
		$filename = preg_replace( '/_{2,}/', '_', $filename );

		// Trim leading/trailing underscores and periods.
		$filename = trim( $filename, '_.' );

		// Truncate to 255 characters.
		if ( strlen( $filename ) > 255 ) {
			$filename = substr( $filename, 0, 255 );
		}

		// If the result is empty, return a safe default.
		if ( '' === $filename ) {
			$filename = 'file';
		}

		// phpcs:enable

		/**
		 * Filters the sanitized filename.
		 *
		 * Allows overriding the strict sanitization result for specific cases.
		 *
		 * @since 1.2.0
		 *
		 * @param string $filename The sanitized filename.
		 * @param string $original The original unsanitized filename.
		 */
		return apply_filters( 'wp_mcp_ai_sanitize_filename', $filename, $original );
	}
	endif;
