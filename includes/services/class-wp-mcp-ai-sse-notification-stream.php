<?php
/**
 * SSE Notification Stream Service
 *
 * Provides Server-Sent Events streaming for real-time job notifications
 * and status updates. Eliminates the need for polling in the chat client.
 *
 * Responsibilities:
 * - Stream job notifications in real-time via SSE
 * - Stream job count updates
 * - Handle connection management and heartbeats
 *
 * Does NOT:
 * - Execute jobs or modify job state
 * - Handle business logic
 * - Store notification data (delegates to Event Dispatcher)
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSE Notification Stream Service class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_SSE_Notification_Stream {

	/**
	 * Stream notifications and job counts for an assistant via SSE
	 *
	 * @param int $assistant_id Assistant ID to stream for.
	 * @param int $user_id      User ID requesting the stream.
	 * @param int $max_duration Maximum stream duration in seconds (10-600).
	 * @param int $poll_interval How often to check for updates in seconds (1-30).
	 * @return void Exits after streaming.
	 */
	public static function stream_notifications( $assistant_id, $user_id, $max_duration = 300, $poll_interval = 2 ) {
		// Validate parameters.
		$assistant_id  = absint( $assistant_id );
		$user_id       = absint( $user_id );
		$max_duration  = max( 10, min( 600, absint( $max_duration ) ) );
		$poll_interval = max( 1, min( 30, absint( $poll_interval ) ) );

		// Set up SSE headers.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Disable Nginx buffering.

		// Disable output buffering for real-time streaming.
		if ( function_exists( 'apache_setenv' ) ) {
			$result = apache_setenv( 'no-gzip', '1' );
			if ( false === $result ) {
				// Log warning but continue - not critical for functionality.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WP_MCP_AI: Failed to disable Apache gzip for SSE stream' );
				}
			}
		}
		ini_set( 'output_buffering', 'off' );
		ini_set( 'zlib.output_compression', 'off' );

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Send initial connected event.
		self::send_event(
			'connected',
			array(
				'assistant_id'  => $assistant_id,
				'poll_interval' => $poll_interval,
				'max_duration'  => $max_duration,
			)
		);

		$start_time       = time();
		$last_heartbeat   = time();
		$heartbeat_interval = 15; // Send heartbeat every 15 seconds.
		$max_iterations = 1000; // Safety limit to prevent infinite loops.
		$iteration_count = 0;

		// Main streaming loop.
		while ( $iteration_count < $max_iterations ) {
			++$iteration_count;
			$current_time = time();

			// Check if max duration exceeded.
			if ( ( $current_time - $start_time ) >= $max_duration ) {
				self::send_event(
					'timeout',
					array(
						'message' => 'Maximum stream duration reached',
						'duration' => $max_duration,
					)
				);
				break;
			}

			// Send heartbeat to keep connection alive.
			if ( ( $current_time - $last_heartbeat ) >= $heartbeat_interval ) {
				self::send_event(
					'heartbeat',
					array( 'timestamp' => $current_time )
				);
				$last_heartbeat = $current_time;
			}

			// Get pending notifications (don't clear them yet).
			$notifications = self::get_notifications( $user_id, $assistant_id, false );

			// Get current job counts.
			$job_counts = self::get_job_counts( $user_id, $assistant_id );

			// Send job counts update.
			self::send_event(
				'job_counts',
				$job_counts
			);

			// Send any new notifications.
			if ( ! empty( $notifications ) ) {
				foreach ( $notifications as $notification ) {
					self::send_event(
						'notification',
						$notification
					);
				}

				// Clear notifications after sending.
				self::get_notifications( $user_id, $assistant_id, true );
			}

			// Sleep before next check.
			sleep( $poll_interval );

			// Flush output to ensure data is sent.
			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();

			// Check if connection is still alive.
			if ( connection_aborted() ) {
				break;
			}
		}

		// Send close event with reason.
		$close_message = 'Stream closed';
		if ( $iteration_count >= $max_iterations ) {
			$close_message = 'Maximum iterations reached (safety limit)';
		}
		
		self::send_event(
			'close',
			array( 'message' => $close_message )
		);

		exit;
	}

	/**
	 * Send an SSE event
	 *
	 * @param string $event Event name.
	 * @param mixed  $data  Event data (will be JSON encoded).
	 * @return void
	 */
	private static function send_event( $event, $data ) {
		echo 'event: ' . esc_html( $event ) . "\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Get pending notifications from Event Dispatcher
	 *
	 * @param int  $user_id      User ID.
	 * @param int  $assistant_id Assistant ID.
	 * @param bool $clear        Whether to clear after retrieving.
	 * @return array Notifications array.
	 */
	private static function get_notifications( $user_id, $assistant_id, $clear = false ) {
		if ( ! class_exists( 'WP_MCP_AI_Event_Dispatcher_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-event-dispatcher-service.php';
		}

		$dispatcher = WP_MCP_AI_Event_Dispatcher_Service::get_instance();
		return $dispatcher->get_pending_notifications( $user_id, $assistant_id, $clear );
	}

	/**
	 * Get job counts from Cron Status Service
	 *
	 * @param int $user_id      User ID.
	 * @param int $assistant_id Assistant ID.
	 * @return array Job counts.
	 */
	private static function get_job_counts( $user_id, $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		}

		$container = WP_MCP_AI_Container::get_instance();
		$service   = $container->get( 'service.cron_status' );

		if ( $service && method_exists( $service, 'get_status_counts' ) ) {
			return $service->get_status_counts( $user_id, $assistant_id, 'chat' );
		}

		return array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'total'     => 0,
		);
	}
}
