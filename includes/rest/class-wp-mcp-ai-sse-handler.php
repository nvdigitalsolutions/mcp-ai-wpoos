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
	 * Retry interval in milliseconds for SSE reconnection.
	 *
	 * If the SSE connection drops, clients will wait this many milliseconds
	 * before attempting to reconnect.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const RETRY_INTERVAL_MS = 3000;

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

		// Disable output buffering completely.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		// Send retry directive to help clients reconnect if connection drops.
		echo 'retry: ' . self::RETRY_INTERVAL_MS . "\n\n";

		// Force initial flush to establish connection.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * Send an SSE event.
	 *
	 * Emits a named event with JSON-encoded data following SSE specification.
	 * Ensures data is flushed immediately for real-time streaming.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 */
	public function send_sse_event( $event, $data ) {
		echo 'event: ' . esc_html( $event ) . "\n";
		
		// Attempt to JSON encode the data.
		// If encoding fails (e.g., invalid UTF-8, circular references, or non-serializable objects),
		// send an error event instead of corrupting the SSE stream with invalid JSON.
		$json_data = wp_json_encode( $data );
		
		if ( false === $json_data ) {
			// JSON encoding failed - log the error and send a simplified error event.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'sse_json_encode_failed',
					'Failed to JSON encode SSE event data',
					array(
						'event'      => $event,
						'data_type'  => gettype( $data ),
						'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown error',
					)
				);
			}
			
			// Send a simplified error response that can be JSON encoded.
			$error_data = array(
				'error'   => true,
				'message' => __( 'Failed to encode response data. The data may contain invalid characters or unsupported types.', 'wp-mcp-ai' ),
				'event'   => $event,
			);
			
			$json_data = wp_json_encode( $error_data );
			
			// If even the error response can't be encoded, use a hardcoded JSON string.
			if ( false === $json_data ) {
				$json_data = '{"error":true,"message":"JSON encoding failed"}';
			}
		}
		
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE data output is JSON encoded.
		echo 'data: ' . $json_data . "\n\n";

		// Force flush to send data immediately.
		// ob_flush() must be called before flush() to ensure PHP output buffers
		// are flushed to the system buffer, which flush() then sends to the client.
		if ( ob_get_level() > 0 && function_exists( 'ob_flush' ) ) {
			ob_flush();
		}
		if ( function_exists( 'flush' ) ) {
			flush();
		}
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

		// Force flush to send data immediately.
		// ob_flush() must be called before flush() to ensure PHP output buffers
		// are flushed to the system buffer, which flush() then sends to the client.
		if ( ob_get_level() > 0 && function_exists( 'ob_flush' ) ) {
			ob_flush();
		}
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * Determine whether the current request prefers an event stream response.
	 *
	 * Checks ONLY for explicit stream parameter.
	 * Accept header is NOT used because MCP clients like LM Studio send
	 * "Accept: text/event-stream" by default but expect JSON responses
	 * (Streamable HTTP transport, MCP 2024-11-05 spec).
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

		// Always return false if not explicitly requested.
		// Do NOT check Accept header - LM Studio and other MCP clients
		// send "Accept: text/event-stream" but expect JSON responses.
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
}
