<?php
/**
 * Server-Sent Events (SSE) Handler for WP oOS Plugin
 *
 * Handles SSE streaming operations for real-time communication with MCP clients.
 * Extracted from WP_MCP_AI_REST class as part of Milestone 3 refactoring.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSE Handler class for Server-Sent Events streaming.
 *
 * Implements modern SSE best practices (2024-2025):
 * - Proper Content-Type: text/event-stream with UTF-8
 * - Cache-Control: no-cache to prevent proxy/browser caching
 * - Connection: keep-alive for persistent streaming
 * - Retry directive for automatic client reconnection
 * - Event IDs for reconnection state tracking
 * - HTTP/2 compatibility (removes Connection header when appropriate)
 * - CORS headers for cross-origin access
 *
 * @since 1.0.0
 */
class WP_MCP_AI_SSE_Handler {

	/**
	 * Transient prefix for storing SSE session state.
	 */
	const SESSION_STATE_PREFIX = 'wp_mcp_ai_sse_session_';

	/**
	 * Session state expiration time in seconds (1 hour).
	 */
	const SESSION_STATE_EXPIRATION = 3600;

	/**
	 * Send SSE headers for streaming response.
	 *
	 * Sets up HTTP headers required for Server-Sent Events streaming.
	 * Disables output buffering to ensure real-time streaming.
	 *
	 * @since 1.0.0
	 */
	public function send_sse_headers() {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate, no-transform' );
			header( 'Pragma: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );

			// Remove Connection header for HTTP/2.
			if ( isset( $_SERVER['SERVER_PROTOCOL'] ) && 0 === strpos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ), 'HTTP/2' ) ) {
				header_remove( 'Connection' );
			}
		}

		// Disable output buffering.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	/**
	 * Send an SSE event.
	 *
	 * Emits a named event with JSON-encoded data following SSE specification.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 */
	public function send_sse_event( $event, $data ) {
		echo 'event: ' . esc_html( $event ) . "\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		// Aggressive flushing to ensure events are sent immediately.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}
		@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Send SSE done marker.
	 *
	 * Signals the end of the event stream with the standard [DONE] marker.
	 *
	 * @since 1.0.0
	 */
	public function send_sse_done() {
		echo "data: [DONE]\n\n";

		// Aggressive flushing to ensure done marker is sent.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}
		@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Determine whether the current request prefers an event stream response.
	 *
	 * Checks for explicit stream parameter or Accept header indicating text/event-stream.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool True if streaming is requested, false otherwise.
	 */
	public function request_wants_event_stream( WP_REST_Request $request ) {
		$stream_param    = $request->get_param( 'stream' );
		$explicit_stream = null;

		if ( null !== $stream_param ) {
			if ( is_array( $stream_param ) || is_object( $stream_param ) ) {
				$stream_data = (array) $stream_param;

				if ( array_key_exists( 'enabled', $stream_data ) ) {
					$normalized_enabled = rest_sanitize_boolean( $stream_data['enabled'] );

					if ( null !== $normalized_enabled ) {
						$explicit_stream = $normalized_enabled;
					}
				}

				if ( null === $explicit_stream && ! empty( $stream_data ) ) {
					$explicit_stream = true;
				}
			} else {
				$normalized_stream = rest_sanitize_boolean( $stream_param );

				if ( null !== $normalized_stream ) {
					$explicit_stream = $normalized_stream;
				}
			}
		}

		if ( true === $explicit_stream ) {
			return true;
		}

		if ( false === $explicit_stream ) {
			return false;
		}

		$accept_header = $request->get_header( 'accept' );

		if ( is_string( $accept_header ) && '' !== $accept_header ) {
			$normalized_accept = strtolower( $accept_header );

			if ( preg_match( '#(^|,|\s)text/event-stream(?:(?=\s*[;,])|$)#i', $normalized_accept ) ) {
				return true;
			}
		}

		if ( false === $explicit_stream ) {
			return false;
		}

		return false;
	}

	/**
	 * Stream a response payload as Server-Sent Events.
	 *
	 * Implements Server-Sent Events (SSE) with modern best practices (2024-2025):
	 * - Proper Content-Type: text/event-stream with UTF-8
	 * - Cache-Control: no-cache to prevent proxy/browser caching
	 * - Connection: keep-alive for persistent streaming
	 * - Retry directive for automatic client reconnection
	 * - Event IDs for reconnection state tracking
	 * - HTTP/2 compatibility (removes Connection header when appropriate)
	 * - CORS headers for cross-origin access
	 *
	 * @since 1.0.0
	 *
	 * @param array  $payload Response payload to emit.
	 * @param string $event   Event name used for the SSE frame. Default 'message'.
	 * @return WP_REST_Response Response object with SSE streaming configured.
	 */
	public function stream_event_stream_payload( array $payload, $event = 'message' ) {
		$encoded_payload = wp_json_encode( $payload );

		if ( false === $encoded_payload ) {
			return rest_ensure_response( $payload );
		}

		$event_name = (string) $event;
		if ( '' === $event_name ) {
			$event_name = 'message';
		}

		// Add retry directive for automatic reconnection (3 seconds).
		$frames  = "retry: 3000\n\n";
		$frames .= $this->build_event_stream_chunk( $event_name, $encoded_payload, (string) time() );
		$frames .= $this->build_event_stream_chunk( '', '[DONE]' );

		$headers = array(
			'Content-Type'                 => 'text/event-stream; charset=UTF-8',
			'Cache-Control'                => 'no-cache, no-store, must-revalidate, no-transform',
			'Pragma'                       => 'no-cache',
			'Connection'                   => 'keep-alive',
			'Vary'                         => 'Accept, Authorization',
			'Access-Control-Allow-Origin'  => '*',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-WP-Nonce',
			'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
			'X-Accel-Buffering'            => 'no',
			'X-Content-Type-Options'       => 'nosniff',
		);

		if ( isset( $_SERVER['SERVER_PROTOCOL'] ) && 0 === strpos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ), 'HTTP/2' ) ) {
			unset( $headers['Connection'] );
		}

		$callback = null;
		$callback = static function ( $served, $response, $request, $server ) use ( $headers, $frames, &$callback ) {
			if ( $served ) {
				return $served;
			}

			if ( method_exists( $server, 'remove_header' ) ) {
				$server->remove_header( 'Content-Type' );
			} else {
				header_remove( 'Content-Type' );
			}

			foreach ( $headers as $name => $value ) {
				if ( '' === $name || null === $value ) {
					continue;
				}

				$server->send_header( $name, $value );
			}

			echo $frames; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( function_exists( 'ob_get_level' ) && function_exists( 'ob_end_flush' ) ) {
				while ( ob_get_level() > 0 ) {
					if ( false === ob_end_flush() ) {
						break;
					}
				}
			} elseif ( function_exists( 'ob_flush' ) ) {
				ob_flush();
			}

			if ( function_exists( 'flush' ) ) {
				flush();
			}

			remove_filter( 'rest_pre_serve_request', $callback, 999 );

			return true;
		};

		add_filter( 'rest_pre_serve_request', $callback, 999, 4 );

		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( $headers );
		$response->header( 'Content-Type', 'text/event-stream; charset=UTF-8' );

		return $response;
	}

	/**
	 * Build a Server-Sent Events chunk for the provided data.
	 *
	 * Formats data according to SSE specification with optional event ID
	 * for client-side reconnection tracking.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event  Event name.
	 * @param string $data   Event data payload.
	 * @param string $id     Optional event ID for client-side reconnection tracking. Default empty.
	 * @return string Formatted SSE chunk.
	 */
	public function build_event_stream_chunk( $event, $data, $id = '' ) {
		$chunk = '';

		$id = (string) $id;
		if ( '' !== $id ) {
			$chunk .= 'id: ' . $id . "\n";
		}

		$event = (string) $event;
		if ( '' !== $event ) {
			$chunk .= 'event: ' . $event . "\n";
		}

		$data_lines = explode( "\n", (string) $data );

		foreach ( $data_lines as $line ) {
			$chunk .= 'data: ' . $line . "\n";
		}

		$chunk .= "\n";

		return $chunk;
	}

	/**
	 * Get the last event ID from the request (for SSE reconnection).
	 *
	 * Checks the Last-Event-ID header sent by the client when reconnecting
	 * to an SSE stream. This allows resuming from the last received event.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return string Last event ID, or empty string if not present.
	 */
	public function get_last_event_id( WP_REST_Request $request ) {
		$last_event_id = $request->get_header( 'Last-Event-ID' );

		if ( is_string( $last_event_id ) && '' !== $last_event_id ) {
			return sanitize_text_field( $last_event_id );
		}

		return '';
	}

	/**
	 * Store SSE session state for reconnection tracking.
	 *
	 * Stores the current event ID and prevents duplicate tool execution
	 * when clients reconnect after network interruption.
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id  Unique session identifier.
	 * @param string $event_id    Current event ID.
	 * @param array  $state_data  Additional state data to preserve.
	 * @return bool True on success, false on failure.
	 */
	public function store_session_state( $session_id, $event_id, $state_data = array() ) {
		$transient_key = self::SESSION_STATE_PREFIX . md5( $session_id );

		$state = array(
			'last_event_id' => sanitize_text_field( $event_id ),
			'timestamp'     => time(),
			'data'          => $state_data,
		);

		return set_transient( $transient_key, $state, self::SESSION_STATE_EXPIRATION );
	}

	/**
	 * Retrieve SSE session state for reconnection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id Unique session identifier.
	 * @return array|null Session state array, or null if not found.
	 */
	public function get_session_state( $session_id ) {
		$transient_key = self::SESSION_STATE_PREFIX . md5( $session_id );
		$state         = get_transient( $transient_key );

		if ( false === $state || ! is_array( $state ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Check if an event has already been sent (for duplicate prevention).
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id Unique session identifier.
	 * @param string $event_id   Event ID to check.
	 * @return bool True if event was already sent, false otherwise.
	 */
	public function is_duplicate_event( $session_id, $event_id ) {
		$state = $this->get_session_state( $session_id );

		if ( null === $state ) {
			return false;
		}

		return isset( $state['last_event_id'] ) && $state['last_event_id'] === $event_id;
	}

	/**
	 * Clear SSE session state.
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id Unique session identifier.
	 * @return bool True on success, false on failure.
	 */
	public function clear_session_state( $session_id ) {
		$transient_key = self::SESSION_STATE_PREFIX . md5( $session_id );
		return delete_transient( $transient_key );
	}
}
