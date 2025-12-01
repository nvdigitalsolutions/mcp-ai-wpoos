<?php
/**
 * Server-Sent Events (SSE) stream handler for real-time job updates.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages SSE connections for streaming job status updates to clients.
 */
class WP_MCP_AI_SSE_Stream {
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
			 * @since 1.0.0
			 *
			 * @param int $max_duration Maximum connection duration in seconds. Default 300 (5 minutes).
			 */
			$max_duration = apply_filters( 'wp_mcp_ai_sse_max_duration', self::MAX_DURATION );
		}

		if ( null === $poll_interval ) {
			/**
			 * Filter the SSE polling interval.
			 *
			 * @since 1.0.0
			 *
			 * @param int $poll_interval Polling interval in seconds. Default 2.
			 */
			$poll_interval = apply_filters( 'wp_mcp_ai_sse_poll_interval', self::POLL_INTERVAL );
		}

		// Validate parameters.
		$max_duration  = max( 10, min( 600, absint( $max_duration ) ) );
		$poll_interval = max( 1, min( 30, absint( $poll_interval ) ) );

		// Prepare SSE headers.
		$headers = array(
			'Content-Type'                 => 'text/event-stream; charset=UTF-8',
			'Cache-Control'                => 'no-cache, no-store, must-revalidate, no-transform',
			'X-Accel-Buffering'            => 'no',
			'Access-Control-Allow-Origin'  => '*',
			'Access-Control-Allow-Methods' => 'GET, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-WP-Nonce',
		);

		// Build SSE response body.
		$body = self::build_sse_stream( $job_id, $max_duration, $poll_interval );

		$response = new WP_REST_Response( $body, 200 );

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
				break;
			}

			$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

			// Send status update if changed.
			if ( $status && $status !== $last_status ) {
				$stream     .= self::format_sse_message( 'status', $status );
				$last_status = $status;

				// Check if job is in terminal state.
				if ( isset( $status['status'] ) && in_array( $status['status'], $terminal_states, true ) ) {
					$stream .= self::format_sse_message(
						'complete',
						array(
							'job_id'       => $job_id,
							'final_status' => $status['status'],
						)
					);
					break;
				}
			}

			// Send heartbeat to keep connection alive.
			/**
			 * Filter the SSE heartbeat interval.
			 *
			 * @since 1.0.0
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
		}

		// Send closing message.
		$stream .= self::format_sse_message( 'close', array( 'job_id' => $job_id ) );

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
}
