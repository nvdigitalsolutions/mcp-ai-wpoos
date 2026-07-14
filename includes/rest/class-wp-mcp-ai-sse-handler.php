<?php
/**
 * Server-Sent Events (SSE) Handler for NV oOS Plugin
 *
 * Handles SSE streaming operations for real-time communication with MCP clients.
 * Extracted from WP_MCP_AI_REST class as part of Milestone 3 refactoring.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		// Release session lock so concurrent SSE requests don't block
		// each other waiting for the same session file.
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		// Disable PHP-level output compression before anything is sent.
		// zlib.output_compression (and ob_gzhandler) buffer all output until the
		// script ends, which prevents SSE events from reaching the browser in
		// real-time and causes ERR_HTTP2_PROTOCOL_ERROR on HTTP/2 connections.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: ini_set() may be disabled on restricted hosts; failure is non-critical since X-Accel-Buffering: no is the primary mitigation.
		@ini_set( 'zlib.output_compression', 'Off' );
		@ini_set( 'output_buffering', 'Off' );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		// Clean (do NOT flush) pre-existing output buffer content so it
		// does not appear before the SSE Content-Type header and corrupt
		// the HTTP/2 response.  Keep the outermost buffer alive — if it
		// is destroyed, WordPress shutdown hooks attempt to write to a
		// closed buffer and produce HTTP/2 protocol errors.
		//
		// ob_end_flush() is avoided here: it sends buffered content to
		// the client BEFORE the SSE headers, which causes the browser to
		// see a mixed-content response and fail with
		// ERR_HTTP2_PROTOCOL_ERROR.
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: clean/end may fail on restricted hosts; non-critical.
		$level = ob_get_level();
		if ( $level > 0 ) {
			// End (and discard) all buffers except the outermost.
			for ( $i = 1; $i < $level; $i++ ) {
				@ob_end_clean();
			}
			// Keep the outermost buffer alive for WP shutdown hooks
			// but discard its content so nothing leaks into the SSE stream.
			@ob_clean();
		}
			// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate, no-transform' );
			header( 'Pragma: no-cache' );
			header( 'X-Accel-Buffering: no' );

			// Hop-by-hop headers are forbidden on HTTP/2 (RFC 7540 §8.1.2.2)
			// and cause ERR_HTTP2_PROTOCOL_ERROR in browsers.  Remove them
			// even though they may be re-added by the web server — this is
			// a best-effort mitigation.
			header_remove( 'Connection' );
			header_remove( 'Transfer-Encoding' );
			header_remove( 'Keep-Alive' );

			/**
			 * Filter the Access-Control-Allow-Origin value for SSE streaming responses.
			 *
			 * Use this filter to restrict SSE connections to specific origins in production.
			 * Defaults to '*' (all origins) for maximum compatibility.
			 *
			 * Example — restrict to a single origin:
			 *   add_filter( 'wp_mcp_ai_cors_allow_origin', fn() => 'https://app.example.com' );
			 *
			 * @since 1.2.0
			 *
			 * @param string $origin Allowed origin. Default '*'.
			 */
			$allow_origin = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );
			// Sanitize to prevent HTTP header injection: strip tags, newlines and
			// carriage returns that could split the response into multiple headers.
			$allow_origin = sanitize_text_field( str_replace( array( "\r", "\n" ), '', $allow_origin ) );
			header( 'Access-Control-Allow-Origin: ' . $allow_origin );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		}

		// Extend PHP execution time for long-running SSE connections.
		// The default max_execution_time (often 30 s) is too short for
		// SSE polling loops and LLM streaming responses.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: set_time_limit() may be restricted; non-critical.
			@set_time_limit( 300 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		// Send retry directive to help clients reconnect if connection drops.
		echo 'retry: ' . esc_html( absint( self::RETRY_INTERVAL_MS ) ) . "\n\n";

		// Force initial flush to establish connection before any
		// expensive processing begins.  This is critical for avoiding
		// Cloudflare 524 timeouts on the first SSE frame.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * Finish an SSE streaming response cleanly.
	 *
	 * Replaces bare `exit` at the end of SSE handlers. When running under
	 * PHP-FPM, `fastcgi_finish_request()` properly closes the FastCGI
	 * transaction and sends a well-formed HTTP/2 DATA+END_STREAM frame,
	 * preventing the `ERR_HTTP2_PROTOCOL_ERROR` that a raw `exit()` causes
	 * (PHP-FPM terminates the connection abruptly, nginx emits RST_STREAM).
	 *
	 * After `fastcgi_finish_request()` the PHP process continues briefly for
	 * any registered shutdown functions; no further output will reach the
	 * client, so this is safe to use as the final statement in an SSE handler.
	 *
	 * Falls back to `exit()` on non-FPM environments (CLI, mod_php, etc.).
	 *
	 * @since 1.2.0
	 */
	public function finish() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		exit;
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
		$this->emit_sse_frame( $event, $data, '' );
	}

	/**
	 * Send an SSE event with an explicit event ID.
	 *
	 * Emits an `id:` line before the `event:` / `data:` pair so the browser
	 * EventSource exposes the value as `event.lastEventId` and reissues it
	 * via the `Last-Event-ID` header on reconnect. Used by the chat-tasks
	 * drawer's `/cron-status` polling loop to support resumable streams
	 * (Phase 2 of the cron-status / tasks-drawer plan).
	 *
	 * @since 1.1.16
	 *
	 * @param string     $event    Event name.
	 * @param array      $data     Event data.
	 * @param string|int $event_id Monotonic event identifier. Empty string skips the `id:` line.
	 */
	public function send_sse_event_with_id( $event, $data, $event_id ) {
		$this->emit_sse_frame( $event, $data, $event_id );
	}

	/**
	 * Emit an SSE frame.
	 *
	 * Internal helper shared by send_sse_event() and send_sse_event_with_id().
	 *
	 * @since 1.1.16
	 *
	 * @param string     $event    Event name.
	 * @param array      $data     Event data.
	 * @param string|int $event_id Optional monotonic event identifier; empty string skips it.
	 */
	protected function emit_sse_frame( $event, $data, $event_id = '' ) {
		// Emit the optional `id:` line first per the SSE spec — EventSource
		// stores this value and replays it as the `Last-Event-ID` header on
		// reconnect. Empty/whitespace identifiers are skipped so the field
		// stays optional (default frames remain identical to pre-1.1.16).
		if ( '' !== $event_id && null !== $event_id ) {
			$id_string = is_scalar( $event_id ) ? (string) $event_id : '';
			$id_string = trim( $id_string );
			if ( '' !== $id_string ) {
				echo 'id: ' . esc_html( $id_string ) . "\n";
			}
		}

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
				'message' => __( 'Failed to encode response data. The data may contain invalid characters or unsupported types.', 'mcp-ai-wpoos' ),
				'event'   => $event,
			);

			$json_data = wp_json_encode( $error_data );

			// If even the error response can't be encoded, use a hardcoded JSON string.
			if ( false === $json_data ) {
				$json_data = '{"error":true,"message":"JSON encoding failed"}';
			}
		}

		// Echo SSE formatted data. JSON data from wp_json_encode is safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE protocol format: data already JSON-encoded via wp_json_encode().
		echo 'data: ' . $json_data . "\n\n";

		// Force flush to send data immediately.
		// ob_flush() must be called before flush() to ensure PHP output buffers.
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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE protocol marker is static string.
		echo "data: [DONE]\n\n";

		// Force flush to send data immediately.
		// ob_flush() must be called before flush() to ensure PHP output buffers.
		// are flushed to the system buffer, which flush() then sends to the client.
		if ( ob_get_level() > 0 && function_exists( 'ob_flush' ) ) {
			ob_flush();
		}
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * Send an SSE comment (keepalive / no-op frame).
	 *
	 * SSE comments (lines starting with `:`) are ignored by EventSource
	 * clients but flush the output buffer through proxies like Cloudflare
	 * and nginx. Use this to establish the connection before expensive
	 * data gathering begins, preventing 524 timeout errors.
	 *
	 * @since 1.9.5
	 *
	 * @param string $text Optional comment text (not sent to clients).
	 */
	public function send_sse_comment( $text = '' ) {
		// SSE comment line — starts with ':', ignored by EventSource clients.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE comment; no HTML/JS context.
		echo ': ' . ( '' !== $text ? esc_html( $text ) : 'keepalive' ) . "\n\n";

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
		// Do NOT check Accept header - LM Studio and other MCP clients.
		// send "Accept: text/event-stream" but expect JSON responses.
		return false;
	}

	/**
	 * Stream a complete event-stream payload constructed from an array.
	 *
	 * This is a convenience method for non-polling SSE responses (e.g.
	 * assistant directory listing). It sends headers, emits one named
	 * event, sends [DONE], and finishes the connection — all in one
	 * blocking call. Do NOT use this for long-polling loops; use
	 * send_sse_headers() + send_sse_event() / send_sse_event_with_id()
	 * + send_sse_done() + finish() manually for those.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $payload     Data to send as the event payload.
	 * @param string $event_name  SSE event name (default 'message').
	 */
	public function stream_event_stream_payload( $payload, $event_name = 'message' ) {
		$this->send_sse_headers();
		$this->send_sse_event( $event_name, $payload );
		$this->send_sse_done();
		$this->finish();
	}
}
