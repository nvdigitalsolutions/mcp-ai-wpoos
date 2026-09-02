<?php
/**
 * Request Guard for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-request-guard.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). SSE counter keys, REST hook priorities, body-size and
 * JSON-depth limits, error-verbosity stripping, and asset-version
 * stripping keep their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - Settings reads go through `get_setting()` — the base settings
 *   repository in monolith installs, the content-graph settings store
 *   in standalone installs.
 * - The unified SSE connection path delegates to the base
 *   `WP_MCP_AI_SSE_Rate_Limiter` in monolith installs and the ported
 *   `SseRateLimiter` (Wave D1b) standalone; the legacy transient counter
 *   remains as the final fallback.
 * - `register()` is registered standalone-only by `Plugin.php` — the
 *   base plugin owns the same REST hooks in monolith installs.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates incoming REST requests against admin-configured limits.
 *
 * @since 1.1.0
 */
class RequestGuard {

	/**
	 * Transient prefix for SSE connection counters.
	 */
	const SSE_COUNTER_PREFIX = 'wp_mcp_ai_sse_connections_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		// Validate request body size and JSON depth before any callback runs.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'validate_request' ), 10, 3 );

		// Wrap callbacks in try/catch to prevent unhandled exceptions.
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'wrap_dispatch' ), 10, 4 );

		// Filter error responses to strip detail when verbosity is 'safe'.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_error_verbosity' ), 20, 3 );

		// Track SSE connections. Fires when stream starts and ends.
		add_action( 'wp_mcp_ai_sse_stream_started', array( __CLASS__, 'acquire_sse_slot' ), 10, 2 );
		add_action( 'wp_mcp_ai_sse_stream_chunk_sent', array( __CLASS__, 'refresh_sse_slot' ), 10, 2 );
		add_action( 'wp_mcp_ai_sse_stream_ended', array( __CLASS__, 'release_sse_slot' ), 10, 2 );

		// Strip asset version strings when enabled.
		if ( self::is_asset_version_stripping_enabled() ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'strip_asset_version' ), 10, 2 );
			add_filter( 'style_loader_src', array( __CLASS__, 'strip_asset_version' ), 10, 2 );
		}
	}

	// ----------------------------------------------------------------
	// Request Body Size & JSON Depth
	// ----------------------------------------------------------------

	/**
	 * Validate the request before dispatching to the route callback.
	 *
	 * Hooked on `rest_pre_dispatch` at priority 10.
	 *
	 * @param mixed           $result  Current pre-dispatch result (null by default).
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed|WP_Error Null to continue, WP_Error to reject.
	 */
	public static function validate_request( $result, $server, $request ) {
		// Only validate plugin routes.
		if ( ! self::is_plugin_route( $request ) ) {
			return $result;
		}

		// Check request body size.
		$size_check = self::validate_body_size( $request );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		// Check JSON depth for POST/PUT/PATCH requests.
		if ( in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$depth_check = self::validate_json_depth( $request );
			if ( is_wp_error( $depth_check ) ) {
				return $depth_check;
			}
		}

		return $result;
	}

	/**
	 * Check if the request targets a plugin REST route.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	private static function is_plugin_route( $request ) {
		if ( is_string( $request ) ) {
			$route = $request;
		} elseif ( $request instanceof \WP_REST_Request ) {
			$route = $request->get_route();
		} else {
			return false;
		}

		// Match mcp-ai/* and nvoos-* namespaces.
		return 0 === strpos( $route, '/mcp-ai/' )
			|| 0 === strpos( $route, '/nvoos-' );
	}

	/**
	 * Validate request body size against the configured limit.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	private static function validate_body_size( $request ) {
		$max_kb = self::get_setting( 'max_request_body_size_kb', 1024 );

		if ( $max_kb <= 0 ) {
			return true; // No limit configured.
		}

		$max_bytes = $max_kb * 1024;

		// Check Content-Length header first (fast path).
		$content_length = $request->get_header( 'content-length' );
		if ( null !== $content_length ) {
			$content_length = absint( $content_length );
			if ( $content_length > $max_bytes ) {
				return new \WP_Error(
					'request_body_too_large',
					sprintf(
						/* translators: 1=actual size in KB, 2=max size in KB */
						__( 'Request body too large: %1$d KB exceeds the maximum of %2$d KB.', 'nvoos-content-graph-ai' ),
						ceil( $content_length / 1024 ),
						$max_kb
					),
					array( 'status' => 413 )
				);
			}
		}

		// Fallback: check actual body length (slower but accurate).
		$body = $request->get_body();
		// WP_REST_Request::get_body() returns null when no body was sent.
		// Passing null to strlen() is deprecated as of PHP 8.1 — normalise
		// before measuring so bodyless requests (GET, HEAD, empty POSTs)
		// never trip the deprecation.
		$body = is_string( $body ) ? $body : '';
		if ( strlen( $body ) > $max_bytes ) {
			return new \WP_Error(
				'request_body_too_large',
				sprintf(
					/* translators: 1=actual size in KB, 2=max size in KB */
					__( 'Request body too large: %1$d KB exceeds the maximum of %2$d KB.', 'nvoos-content-graph-ai' ),
					ceil( strlen( $body ) / 1024 ),
					$max_kb
				),
				array( 'status' => 413 )
			);
		}

		return true;
	}

	/**
	 * Validate JSON nesting depth in the request body.
	 *
	 * Uses a lightweight stream-based approach to avoid buffering the
	 * entire request body twice. Only parses the JSON to check depth,
	 * not to validate structure.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	private static function validate_json_depth( $request ) {
		$max_depth = self::get_setting( 'max_json_depth', 32 );

		if ( $max_depth <= 0 ) {
			return true; // No limit configured.
		}

		$body = $request->get_body();

		if ( empty( $body ) ) {
			return true;
		}

		// Quick check: is it even JSON?
		$trimmed = trim( $body );
		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return true; // Not JSON, let WordPress handle it.
		}

		$depth = self::measure_json_depth( $body );

		if ( $depth > $max_depth ) {
			return new \WP_Error(
				'json_too_deep',
				sprintf(
					/* translators: 1=actual depth, 2=max depth */
					__( 'JSON nesting depth %1$d exceeds the maximum of %2$d.', 'nvoos-content-graph-ai' ),
					$depth,
					$max_depth
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Measure the maximum nesting depth of a JSON string.
	 *
	 * Uses character-by-character scanning without fully deserializing.
	 * Handles strings, escaped characters, and Unicode escapes.
	 *
	 * @param string $json Raw JSON string.
	 * @return int Maximum nesting depth found.
	 */
	private static function measure_json_depth( $json ) {
		$depth     = 0;
		$max       = 0;
		$in_string = false;
		$escape    = false;
		$len       = strlen( $json );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $json[ $i ];

			if ( $in_string ) {
				if ( $escape ) {
					// Skip the escaped character.
					$escape = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escape = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_string = false;
				}
				continue;
			}

			// Outside a string — track structural characters.
			switch ( $char ) {
				case '"':
					$in_string = true;
					break;
				case '{':
				case '[':
					++$depth;
					if ( $depth > $max ) {
						$max = $depth;
					}
					break;
				case '}':
				case ']':
					--$depth;
					break;
			}
		}

		return $max;
	}

	// ----------------------------------------------------------------
	// SSE Connection Limits
	// ----------------------------------------------------------------

	/**
	 * Acquire an SSE connection slot. Rejects if at capacity.
	 *
	 * Delegates to the active SSE rate limiter when available (unified
	 * tracking). Falls back to legacy transient-based tracking when no
	 * limiter is loaded.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $params Stream parameters.
	 * @return void
	 */
	public static function acquire_sse_slot( $job_id, $params ) {
		// $params is kept for backward compatibility with the hook signature.
		unset( $params );

		// Unified path: delegate to the active SSE rate limiter.
		$limiter = static::get_sse_rate_limiter();
		if ( null !== $limiter ) {
			$allowed = $limiter->check_connection_allowed();
			if ( is_wp_error( $allowed ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_die() pattern mirrors existing codebase convention for early-exit rejection.
				wp_die( $allowed, 429 );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$token = $limiter->register_connection();
			// Store token for release_sse_slot().
			set_transient( 'wp_mcp_ai_sse_slot_token_' . sanitize_key( $job_id ), $token, 3600 );
			return;
		}

		// Legacy path: transient-based counter.
		$max   = self::get_setting( 'max_sse_connections_per_user', 5 );
		$key   = self::get_sse_counter_key();
		$count = absint( get_transient( $key ) );

		if ( $max > 0 && $count >= $max ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_die() pattern mirrors existing codebase convention.
			wp_die(
				new \WP_Error(
					'sse_connection_limit',
					sprintf(
						/* translators: %d: max connections */
						__( 'Maximum %d concurrent SSE connections reached. Please wait for an existing connection to close.', 'nvoos-content-graph-ai' ),
						$max
					),
					array( 'status' => 429 )
				),
				429
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Use 5-min TTL (matches SSE MAX_DURATION). Self-cleaning if
		// the 'ended' hook never fires (crash / connection drop).
		set_transient( $key, $count + 1, 300 );
	}

	/**
	 * Refresh the SSE slot TTL on each chunk sent (keeps transient alive).
	 *
	 * @param string $job_id    Job identifier.
	 * @param string $event_type Event type.
	 * @return void
	 */
	public static function refresh_sse_slot( $job_id, $event_type ) {
		$key   = self::get_sse_counter_key();
		$count = absint( get_transient( $key ) );

		if ( $count > 0 ) {
			set_transient( $key, $count, 300 );
		}
	}

	/**
	 * Release an SSE connection slot when the stream ends.
	 *
	 * Delegates to the active SSE rate limiter when available. Falls back
	 * to the legacy transient counter.
	 *
	 * @param string $job_id  Job identifier.
	 * @param mixed  ...$args Additional arguments (outcome, summary).
	 * @return void
	 */
	public static function release_sse_slot( $job_id, ...$args ) {
		// Unified path: delegate to the active SSE rate limiter.
		$limiter = static::get_sse_rate_limiter();
		if ( null !== $limiter ) {
			$token = get_transient( 'wp_mcp_ai_sse_slot_token_' . sanitize_key( $job_id ) );
			delete_transient( 'wp_mcp_ai_sse_slot_token_' . sanitize_key( $job_id ) );
			if ( $token ) {
				$limiter->release_connection( $token );
			}
		}

		// Legacy cleanup: remove old counter keys if they exist.
		$key   = self::get_sse_counter_key();
		$count = absint( get_transient( $key ) );

		if ( $count <= 1 ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $count - 1, 300 );
		}
	}

	/**
	 * Resolve the active SSE rate limiter (per-install-mode seam).
	 *
	 * @return object|null Limiter instance or null when unavailable.
	 */
	protected static function get_sse_rate_limiter() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_SSE_Rate_Limiter' ) ) {
			return new \WP_MCP_AI_SSE_Rate_Limiter();
		}

		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( '\NvoosContentGraphAi\Chat\SseRateLimiter' ) ) {
			return new \NvoosContentGraphAi\Chat\SseRateLimiter();
		}

		return null;
	}

	/**
	 * Build the SSE counter transient key for the current user.
	 *
	 * Uses user ID when authenticated, falls back to IP for guests.
	 *
	 * @return string
	 */
	private static function get_sse_counter_key() {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return self::SSE_COUNTER_PREFIX . 'u_' . $user_id;
		}

		// Fall back to IP for unauthenticated users.
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return self::SSE_COUNTER_PREFIX . 'ip_' . md5( $ip );
	}

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	/**
	 * Get a settings value from the active settings store (per-mode seam).
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected static function get_setting( $key, $default = null ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			return wp_mcp_ai_get_settings_repository()->get( $key, $default );
		}

		return CoreBridge::instance()->settings->get( $key, $default );
	}

	// ----------------------------------------------------------------
	// Error Verbosity Control
	// ----------------------------------------------------------------

	/**
	 * Filter error responses to strip detail when verbosity is 'safe'.
	 *
	 * Hooked on rest_post_dispatch at priority 20 (after augment_error_actions).
	 *
	 * @param WP_REST_Response|WP_Error $response Response object.
	 * @param WP_REST_Server            $server   REST server.
	 * @param WP_REST_Request           $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function filter_error_verbosity( $response, $server, $request ) {
		// Only filter plugin routes.
		if ( ! self::is_plugin_route( $request ) ) {
			return $response;
		}

		$verbosity = self::get_setting( 'api_error_verbosity', 'normal' );

		// 'verbose' mode: pass everything through.
		if ( 'verbose' === $verbosity ) {
			return $response;
		}

		// 'normal' mode: only filter for non-admin users.
		if ( 'normal' === $verbosity && current_user_can( 'manage_options' ) ) {
			return $response;
		}

		// 'safe' mode (or 'normal' for non-admins): strip internal detail.
		if ( is_wp_error( $response ) ) {
			return self::sanitize_error( $response );
		}

		if ( $response instanceof \WP_REST_Response && $response->is_error() ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$data = self::strip_internal_keys( $data );
				$response->set_data( $data );
			}
		}

		return $response;
	}

	/**
	 * Strip internal/sensitive keys from an error response.
	 *
	 * Only the following keys are safe to expose:
	 * code, message, status, retry_after, actions, user_message, suggestions.
	 *
	 * @param WP_Error $error The error object.
	 * @return WP_Error Sanitized error.
	 */
	private static function sanitize_error( $error ) {
		$data     = $error->get_error_data();
		$filtered = array(
			'status' => 500,
		);

		if ( is_array( $data ) ) {
			// Keep only safe keys.
			$safe_keys = array( 'status', 'retry_after', 'actions', 'user_message', 'suggestions' );
			foreach ( $safe_keys as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$filtered[ $key ] = $data[ $key ];
				}
			}
		}

		return new \WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			$filtered
		);
	}

	/**
	 * Strip internal keys from a response data array.
	 *
	 * @param array $data Response data.
	 * @return array Filtered data.
	 */
	private static function strip_internal_keys( $data ) {
		// If the response has nested data, strip that too.
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$safe_data = array();
			$safe_keys = array( 'status', 'actions', 'user_message', 'suggestions', 'retry_after' );
			foreach ( $safe_keys as $key ) {
				if ( isset( $data['data'][ $key ] ) ) {
					$safe_data[ $key ] = $data['data'][ $key ];
				}
			}
			$data['data'] = $safe_data;
		}

		return $data;
	}

	// ----------------------------------------------------------------
	// Exception Guard
	// ----------------------------------------------------------------

	/**
	 * Wrap REST callback dispatch in try/catch to prevent unhandled
	 * exceptions from leaking stack traces to API consumers.
	 *
	 * Hooked on rest_dispatch_request at priority 10.
	 *
	 * @param mixed           $result  Dispatch result.
	 * @param WP_REST_Request $request Current request (WP >= 6.5).
	 * @param string          $route   Matched route.
	 * @param mixed           $handler Route handler (WP >= 6.5).
	 * @param WP_REST_Server  $server  REST server (WP >= 6.5).
	 * @return mixed|WP_Error
	 */
	public static function wrap_dispatch( $result, $request, $route, $handler = null, $server = null ) {
		// Normalise: WP < 6.5 passed ($result, $server, $request, $route).
		// WP >= 6.5 passes ($result, $request, $route, $handler, $server).
		if ( ! $request instanceof \WP_REST_Request && $route instanceof \WP_REST_Request ) {
			// Old signature detected — swap.
			$tmp     = $request;
			$request = $route;
			$route   = $handler;
		}

		// Only wrap plugin routes.
		if ( ! self::is_plugin_route( $request ) ) {
			return $result;
		}

		// If the result is already an error, pass it through.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	// ----------------------------------------------------------------
	// Asset Version Stripping
	// ----------------------------------------------------------------

	/**
	 * Check if asset version stripping is enabled.
	 *
	 * @return bool
	 */
	private static function is_asset_version_stripping_enabled() {
		return (bool) self::get_setting( 'strip_asset_versions', false );
	}

	/**
	 * Strip the version query parameter from asset URLs.
	 *
	 * Hooked on script_loader_src and style_loader_src.
	 * Only strips versions from plugin assets (URLs containing 'mcp-ai' or 'nvoos-').
	 *
	 * @param string $src    Asset URL.
	 * @param string $handle Asset handle.
	 * @return string Filtered URL.
	 */
	public static function strip_asset_version( $src, $handle ) {
		// Only affect plugin assets.
		if ( false === strpos( $src, 'mcp-ai' ) && false === strpos( $src, 'nvoos-' ) ) {
			return $src;
		}

		// Remove the ver query parameter.
		return remove_query_arg( 'ver', $src );
	}
}
