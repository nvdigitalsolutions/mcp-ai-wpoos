<?php
/**
 * Request Guard — Runtime enforcement of SSE connection limits,
 * JSON depth limits, and request body size limits.
 *
 * Hooks into WordPress REST API filters to validate requests before
 * they reach endpoint callbacks.
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

if ( ! class_exists( 'WP_MCP_AI_Request_Guard' ) ) {
	/**
	 * Validates incoming REST requests against admin-configured limits.
	 */
	class WP_MCP_AI_Request_Guard {

		/**
		 * Transient prefix for SSE connection counters.
		 */
		const SSE_COUNTER_PREFIX = 'wp_mcp_ai_sse_connections_';

		/**
		 * Register hooks.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function register() {
			// Validate request body size and JSON depth before any callback runs.
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'validate_request' ), 10, 3 );

			// Track SSE connections. Fires when stream starts and ends.
			add_action( 'wp_mcp_ai_sse_stream_started', array( __CLASS__, 'acquire_sse_slot' ), 10, 2 );
			add_action( 'wp_mcp_ai_sse_stream_chunk_sent', array( __CLASS__, 'refresh_sse_slot' ), 10, 2 );
			add_action( 'wp_mcp_ai_sse_stream_ended', array( __CLASS__, 'release_sse_slot' ), 10, 2 );
		}

		// ----------------------------------------------------------------
		// Request Body Size & JSON Depth
		// ----------------------------------------------------------------

		/**
		 * Validate the request before dispatching to the route callback.
		 *
		 * Hooked on `rest_pre_dispatch` at priority 10.
		 *
		 * @since 1.2.0
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
		 * @since 1.2.0
		 *
		 * @param WP_REST_Request $request Current request.
		 * @return bool
		 */
		private static function is_plugin_route( $request ) {
			$route = $request->get_route();

			// Match mcp-ai/* and nvoos-* namespaces.
			return 0 === strpos( $route, '/mcp-ai/' )
				|| 0 === strpos( $route, '/nvoos-' );
		}

		/**
		 * Validate request body size against the configured limit.
		 *
		 * @since 1.2.0
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
					return new WP_Error(
						'request_body_too_large',
						sprintf(
							/* translators: 1=actual size in KB, 2=max size in KB */
							__( 'Request body too large: %1$d KB exceeds the maximum of %2$d KB.', 'mcp-ai-wpoos' ),
							ceil( $content_length / 1024 ),
							$max_kb
						),
						array( 'status' => 413 )
					);
				}
			}

			// Fallback: check actual body length (slower but accurate).
			$body = $request->get_body();
			if ( strlen( $body ) > $max_bytes ) {
				return new WP_Error(
					'request_body_too_large',
					sprintf(
						/* translators: 1=actual size in KB, 2=max size in KB */
						__( 'Request body too large: %1$d KB exceeds the maximum of %2$d KB.', 'mcp-ai-wpoos' ),
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
		 * @since 1.2.0
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
				return new WP_Error(
					'json_too_deep',
					sprintf(
						/* translators: 1=actual depth, 2=max depth */
						__( 'JSON nesting depth %1$d exceeds the maximum of %2$d.', 'mcp-ai-wpoos' ),
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
		 * @since 1.2.0
		 *
		 * @param string $json Raw JSON string.
		 * @return int Maximum nesting depth found.
		 */
		private static function measure_json_depth( $json ) {
			$depth    = 0;
			$max      = 0;
			$in_string = false;
			$escape   = false;
			$len      = strlen( $json );

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
		 * @since 1.2.0
		 *
		 * @param string $job_id Job identifier.
		 * @param array  $params Stream parameters.
		 * @return void
		 */
		public static function acquire_sse_slot( $job_id, $params ) {
			$max   = self::get_setting( 'max_sse_connections_per_user', 5 );
			$key   = self::get_sse_counter_key();
			$count = absint( get_transient( $key ) );

			if ( $max > 0 && $count >= $max ) {
				wp_die(
					new WP_Error(
						'sse_connection_limit',
						sprintf(
							/* translators: %d: max connections */
							__( 'Maximum %d concurrent SSE connections reached. Please wait for an existing connection to close.', 'mcp-ai-wpoos' ),
							$max
						),
						array( 'status' => 429 )
					),
					429
				);
			}

			// Use 5-min TTL (matches SSE MAX_DURATION). Self-cleaning if
			// the 'ended' hook never fires (crash / connection drop).
			set_transient( $key, $count + 1, 300 );
		}

		/**
		 * Refresh the SSE slot TTL on each chunk sent (keeps transient alive).
		 *
		 * @since 1.2.0
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
		 * @since 1.2.0
		 *
		 * @param string $job_id  Job identifier.
		 * @param mixed  ...$args Additional arguments (outcome, summary).
		 * @return void
		 */
		public static function release_sse_slot( $job_id, ...$args ) {
			$key   = self::get_sse_counter_key();
			$count = absint( get_transient( $key ) );

			if ( $count <= 1 ) {
				delete_transient( $key );
			} else {
				set_transient( $key, $count - 1, 300 );
			}
		}

		/**
		 * Build the SSE counter transient key for the current user.
		 *
		 * Uses user ID when authenticated, falls back to IP for guests.
		 *
		 * @since 1.2.0
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
		 * Get a settings value from the repository.
		 *
		 * @since 1.2.0
		 *
		 * @param string $key     Setting key.
		 * @param mixed  $default Default value.
		 * @return mixed
		 */
		private static function get_setting( $key, $default = null ) {
			if ( function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
				return wp_mcp_ai_get_settings_repository()->get( $key, $default );
			}

			return $default;
		}
	}
}
