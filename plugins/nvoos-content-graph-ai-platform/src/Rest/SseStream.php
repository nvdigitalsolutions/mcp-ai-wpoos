<?php
/**
 * SSE stream handler for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_SSE_Stream` (Wave E2):
 * byte-identical duration/poll/heartbeat constants and filters
 * (`wp_mcp_ai_sse_max_duration`, `wp_mcp_ai_sse_poll_interval`,
 * `wp_mcp_ai_sse_heartbeat_interval`, `wp_mcp_ai_cors_allow_origin`),
 * the buffered `stream_job_status()` polling loop with its connected/status/
 * complete/timeout/close event framing, heartbeat comments, the
 * `wp_mcp_ai_sse_stream_started|chunk_sent|ended` notification hooks, and
 * the `format_sse_message()` / `format_sse_comment()` / backpressure /
 * typed-event helpers.
 *
 * Decoupling (documented, additive):
 * - CORS origin resolves per install mode: the base settings registry
 *   monolith / a direct `wp_mcp_ai_settings` option read standalone
 *   (identical effective behavior — same option key).
 * - Security headers resolve per install mode: the base
 *   `WP_MCP_AI_Security_Manager` monolith / none standalone (documented
 *   degradation until the security-manager port lands).
 * - Job status reads resolve per install mode: base
 *   `WP_MCP_AI_Job_Notifier` monolith / this package's
 *   `Queues\JobNotifier` standalone.
 *
 * @package NvoosContentGraphAiPlatform\Rest
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages SSE connections for streaming job status updates to clients.
 */
class SseStream {
	const MAX_DURATION       = 300; // 5 minutes max connection time.
	const POLL_INTERVAL      = 2;   // Poll every 2 seconds.
	const HEARTBEAT_INTERVAL = 15;  // Send heartbeat every 15 seconds.

	/**
	 * Stream job status updates via SSE.
	 *
	 * @param string $job_id         Job identifier to monitor.
	 * @param int    $max_duration   Maximum connection duration in seconds.
	 * @param int    $poll_interval  Polling interval in seconds.
	 * @return WP_REST_Response
	 */
	public static function stream_job_status( $job_id, $max_duration = null, $poll_interval = null ) {
		if ( null === $max_duration ) {
			/**
			 * Filter the maximum SSE connection duration.
			 *
			 * @param int $max_duration Maximum connection duration in seconds. Default 300 (5 minutes).
			 */
			$max_duration = apply_filters( 'wp_mcp_ai_sse_max_duration', self::MAX_DURATION );
		}

		if ( null === $poll_interval ) {
			/**
			 * Filter the SSE polling interval.
			 *
			 * @param int $poll_interval Polling interval in seconds. Default 2.
			 */
			$poll_interval = apply_filters( 'wp_mcp_ai_sse_poll_interval', self::POLL_INTERVAL );
		}

		// Validate parameters.
		$max_duration  = max( 10, min( 600, absint( $max_duration ) ) );
		$poll_interval = max( 1, min( 30, absint( $poll_interval ) ) );

		// Prepare SSE headers.
		$cors_setting   = self::cors_allow_origin_setting();
		$default_origin = ( 'star' === $cors_setting ) ? '*' : get_site_url();
		$allow_origin   = apply_filters( 'wp_mcp_ai_cors_allow_origin', $default_origin );
		$headers        = array(
			'Content-Type'                 => 'text/event-stream; charset=UTF-8',
			'Cache-Control'                => 'no-cache, no-store, must-revalidate, no-transform',
			'X-Accel-Buffering'            => 'no',
			'Access-Control-Allow-Origin'  => $allow_origin,
			'Access-Control-Allow-Methods' => 'GET, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-WP-Nonce',
		);

		// Apply the centrally-managed security headers (gated behind Settings → Security → Network → "Enable Security Headers").
		$headers = array_merge( $headers, self::security_headers() );

		// Build SSE response body.
		$body = self::build_sse_stream( $job_id, $max_duration, $poll_interval );

		$response = new \WP_REST_Response( $body, 200 );

		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}

		return $response;
	}

	/**
	 * Build the SSE stream body by polling job status.
	 *
	 * Safety mechanisms:
	 * - Maximum iteration limit prevents infinite loops
	 * - Connection abortion check exits early if client disconnects
	 * - Maximum duration timeout ensures bounded execution
	 *
	 * @param string $job_id        Job identifier.
	 * @param int    $max_duration  Maximum duration in seconds.
	 * @param int    $poll_interval Polling interval in seconds.
	 * @return string SSE formatted stream.
	 */
	protected static function build_sse_stream( $job_id, $max_duration, $poll_interval ) {
		$start_time      = time();
		$last_heartbeat  = $start_time;
		$last_status     = null;
		$stream          = '';
		$terminal_states = array( 'completed', 'failed', 'cancelled' );
		$iteration_count = 0;
		$max_iterations  = ceil( $max_duration / max( 1, $poll_interval ) ) + 10; // Safety margin.

		// Send initial connection message.
		$stream .= self::format_sse_message(
			'connected',
			array(
				'job_id'        => $job_id,
				'connected_at'  => current_time( 'c', true ),
				'poll_interval' => $poll_interval,
				'max_duration'  => $max_duration,
			)
		);

		/**
		 * Fires when an SSE stream begins, immediately after the initial
		 * `connected` event is buffered and before the poll loop starts.
		 *
		 * Pure notification hook; observers MUST NOT alter the stream state
		 * (no echoing, no calls that modify `$stream`).
		 *
		 * @param string $job_id        Job identifier.
		 * @param array  $params        Stream parameters (`max_duration`, `poll_interval`, `started_at`).
		 */
		do_action(
			'wp_mcp_ai_sse_stream_started',
			$job_id,
			array(
				'max_duration'  => $max_duration,
				'poll_interval' => $poll_interval,
				'started_at'    => $start_time,
			)
		);

		/**
		 * Outcome is refined as the loop exits. Values:
		 * - `complete`              — terminal state `completed`
		 * - `failed`                — terminal state `failed`
		 * - `cancelled_by_job`      — terminal state `cancelled`
		 * - `cancelled_by_client`   — `connection_aborted()` returned true
		 * - `timeout`               — max duration reached
		 * - `iteration_exhausted`   — safety cap tripped (rare, edge case)
		 */
		$outcome = 'iteration_exhausted';

		// Poll until max duration, terminal state, or client disconnect.
		while ( ( time() - $start_time ) < $max_duration && $iteration_count < $max_iterations ) {
			++$iteration_count;

			// Check if client disconnected (prevent wasted processing).
			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				$stream .= self::format_sse_message(
					'disconnected',
					array(
						'job_id'  => $job_id,
						'message' => 'Client connection aborted',
					)
				);
				$outcome = 'cancelled_by_client';
				break;
			}

			$status = self::notifier_class()::get_job_status( $job_id );

			// Send status update if changed.
			if ( $status && $status !== $last_status ) {
				$stream     .= self::format_sse_message( 'status', $status );
				$last_status = $status;

				/**
				 * Fires when a non-heartbeat SSE chunk is buffered for delivery.
				 *
				 * Fires for `connected`, `status`, `disconnected`, `complete`,
				 * `timeout`, and `close` events, but NOT for heartbeat comments —
				 * so `stream.chunk_interval_ms` measures real status progression
				 * rather than the heartbeat cadence.
				 *
				 * Pure notification hook; observers MUST NOT alter stream state.
				 *
				 * @param string $job_id      Job identifier.
				 * @param string $event_type  SSE event name (e.g. `status`, `complete`).
				 * @param int    $iteration   Iteration count at time of emission.
				 */
				do_action( 'wp_mcp_ai_sse_stream_chunk_sent', $job_id, 'status', $iteration_count );

				// Check if job is in terminal state.
				if ( isset( $status['status'] ) && in_array( $status['status'], $terminal_states, true ) ) {
					$stream .= self::format_sse_message(
						'complete',
						array(
							'job_id'       => $job_id,
							'final_status' => $status['status'],
						)
					);
					if ( 'completed' === $status['status'] ) {
						$outcome = 'complete';
					} elseif ( 'failed' === $status['status'] ) {
						$outcome = 'failed';
					} else {
						$outcome = 'cancelled_by_job';
					}
					break;
				}
			}

			// Send heartbeat to keep connection alive.
			/**
			 * Filter the SSE heartbeat interval.
			 *
			 * @param int $heartbeat_interval Heartbeat interval in seconds. Default 15.
			 */
			$heartbeat_interval = apply_filters( 'wp_mcp_ai_sse_heartbeat_interval', self::HEARTBEAT_INTERVAL );
			if ( ( time() - $last_heartbeat ) >= $heartbeat_interval ) {
				$stream        .= self::format_sse_comment( 'heartbeat' );
				$last_heartbeat = time();
			}

			// Wait before next poll (simulate streaming).
			// In real implementation, this would be non-blocking.
			sleep( $poll_interval );
		}

		// Send timeout if max duration reached.
		if ( ( time() - $start_time ) >= $max_duration ) {
			$stream .= self::format_sse_message(
				'timeout',
				array(
					'job_id'  => $job_id,
					'message' => 'Maximum connection duration reached',
				)
			);
			$outcome = 'timeout';
		}

		// Send closing message.
		$stream .= self::format_sse_message( 'close', array( 'job_id' => $job_id ) );

		/**
		 * Fires when an SSE stream ends, just before the handler returns
		 * the buffered stream body. The `$outcome` parameter distinguishes
		 * real errors from client cancellations and server-side timeouts —
		 * consumers (measurement, logging) must treat `cancelled_by_client`
		 * as a first-class non-error outcome.
		 *
		 * Pure notification hook; observers MUST NOT alter stream state.
		 *
		 * @param string $job_id     Job identifier.
		 * @param string $outcome    One of `complete`, `failed`, `cancelled_by_job`,
		 *                           `cancelled_by_client`, `timeout`, `iteration_exhausted`.
		 * @param array  $summary    Stream summary (`duration_ms`, `iterations`, `started_at`).
		 */
		do_action(
			'wp_mcp_ai_sse_stream_ended',
			$job_id,
			$outcome,
			array(
				'duration_ms' => (int) ( ( time() - $start_time ) * 1000 ),
				'iterations'  => $iteration_count,
				'started_at'  => $start_time,
			)
		);

		return $stream;
	}

	/**
	 * Format an SSE message.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 * @param string $id    Optional event ID.
	 * @return string Formatted SSE message.
	 */
	protected static function format_sse_message( $event, $data, $id = '' ) {
		$message = '';

		if ( '' !== $id ) {
			$message .= 'id: ' . sanitize_text_field( $id ) . "\n";
		}

		$message .= 'event: ' . sanitize_key( $event ) . "\n";

		$json = wp_json_encode( $data );
		if ( false !== $json ) {
			// SSE requires data to be prefixed with "data: ".
			$lines = explode( "\n", $json );
			foreach ( $lines as $line ) {
				$message .= 'data: ' . $line . "\n";
			}
		}

		$message .= "\n";

		return $message;
	}

	/**
	 * Format an SSE comment (for heartbeats).
	 *
	 * @param string $text Comment text.
	 * @return string Formatted SSE comment.
	 */
	protected static function format_sse_comment( $text ) {
		return ': ' . sanitize_text_field( $text ) . "\n\n";
	}

	/**
	 * Stream with backpressure control.
	 *
	 * Enhanced streaming that monitors buffer size and client connection status
	 * to prevent buffer overflow and wasted processing.
	 *
	 * @param Generator $generator    Generator yielding chunks of data.
	 * @param int       $buffer_size  Maximum buffer size before flush (default 8192 bytes).
	 * @return void
	 */
	public static function stream_with_backpressure( $generator, $buffer_size = 8192 ) {
		$buffer = '';

		foreach ( $generator as $chunk ) {
			$buffer .= $chunk;

			// Send when buffer is full or on flush signal.
			if ( strlen( $buffer ) >= $buffer_size ) {
				echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE stream output.
				if ( function_exists( 'wp_ob_end_flush_all' ) ) {
					wp_ob_end_flush_all();
				} else {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress flush warnings.
					@flush();
				}
				$buffer = '';

				// Check client connection.
				if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
					break;
				}
			}
		}

		// Flush remaining buffer.
		if ( ! empty( $buffer ) ) {
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE stream output.
			if ( function_exists( 'wp_ob_end_flush_all' ) ) {
				wp_ob_end_flush_all();
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress flush warnings.
				@flush();
			}
		}
	}

	/**
	 * Send typed event (tool_call, content, error, metadata).
	 *
	 * Provides structured event types for different kinds of AI responses,
	 * enabling better client-side handling and UI updates.
	 *
	 * @param string $type      Event type (tool_call, content, error, metadata).
	 * @param mixed  $data      Event data.
	 * @param string $event_id  Optional event ID for tracking.
	 * @return string Formatted typed SSE message.
	 */
	public static function send_typed_event( $type, $data, $event_id = '' ) {
		$event = array(
			'type'      => sanitize_key( $type ),
			'data'      => $data,
			'timestamp' => microtime( true ),
		);

		return self::format_sse_message( $type, $event, $event_id );
	}

	/**
	 * Stream generator with automatic chunking.
	 *
	 * Wrapper around stream_with_backpressure that accepts an array of messages
	 * and yields them as a generator for streaming.
	 *
	 * @param array $messages    Array of messages to stream.
	 * @param int   $buffer_size Buffer size for backpressure control.
	 * @return void
	 */
	public static function stream_messages( $messages, $buffer_size = 8192 ) {
		$generator = function () use ( $messages ) {
			foreach ( $messages as $message ) {
				if ( is_array( $message ) && isset( $message['type'], $message['data'] ) ) {
					yield self::send_typed_event( $message['type'], $message['data'] );
				} else {
					yield self::format_sse_message( 'message', $message );
				}
			}
		};

		self::stream_with_backpressure( $generator(), $buffer_size );
	}

	// ─── Seams ──────────────────────────────────────────────────────

	/**
	 * Resolve the job notifier class for status polling.
	 *
	 * The base WP_MCP_AI_Job_Notifier owns the status cache in monolith
	 * installs; standalone resolves this package's Queues\JobNotifier.
	 *
	 * @return string Fully-qualified class name.
	 */
	protected static function notifier_class(): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			return 'WP_MCP_AI_Job_Notifier';
		}

		return \NvoosContentGraphAiPlatform\Queues\JobNotifier::class;
	}

	/**
	 * Read the CORS allow-origin setting.
	 *
	 * Monolith: the base settings registry. Standalone: a direct
	 * `wp_mcp_ai_settings` option read (identical effective behavior —
	 * same option key and default).
	 *
	 * @return string CORS setting ('site', 'star', …).
	 */
	protected static function cors_allow_origin_setting(): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['cors_allow_origin'] ) ? (string) $settings['cors_allow_origin'] : 'site';
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return isset( $settings['cors_allow_origin'] ) ? (string) $settings['cors_allow_origin'] : 'site';
	}

	/**
	 * Resolve the centrally-managed security headers.
	 *
	 * Monolith: the base WP_MCP_AI_Security_Manager. Standalone: none yet —
	 * documented degradation until the security-manager port lands (the
	 * stream still ships its own SSE + CORS headers).
	 *
	 * @return array Header key => value pairs.
	 */
	protected static function security_headers(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Security_Manager' ) ) {
			$security         = new \WP_MCP_AI_Security_Manager();
			$security_headers = $security->get_security_headers();
			return is_array( $security_headers ) ? $security_headers : array();
		}

		return array();
	}
}
