<?php
/**
 * Event Dispatcher Service
 *
 * Bridges WordPress action hooks to chat client notifications.
 * Follows separation of concerns by only handling event routing and formatting.
 *
 * Responsibilities:
 * - Listen to async job lifecycle hooks
 * - Format notifications for chat client consumption
 * - Dispatch notifications to active chat sessions
 * - Maintain notification history for debugging
 *
 * Does NOT:
 * - Execute tools or manage jobs
 * - Handle SSE streaming directly
 * - Modify job state or data
 * - Perform business logic
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Dispatcher Service class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Event_Dispatcher_Service {

	/**
	 * Singleton instance
	 *
	 * @var WP_MCP_AI_Event_Dispatcher_Service|null
	 */
	private static $instance = null;

	/**
	 * Active chat sessions to notify
	 * Format: array( 'session_id' => array( 'assistant_id' => int, 'user_id' => int ) )
	 *
	 * @var array
	 */
	private $active_sessions = array();

	/**
	 * Notification history (last 50 notifications for debugging)
	 *
	 * @var array
	 */
	private $notification_history = array();

	/**
	 * Maximum notifications to keep in history
	 *
	 * @var int
	 */
	const MAX_HISTORY = 50;

	/**
	 * Get singleton instance
	 *
	 * @return WP_MCP_AI_Event_Dispatcher_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - register hook listeners
	 */
	private function __construct() {
		$this->register_hook_listeners();
	}

	/**
	 * Register WordPress hook listeners for async job events
	 *
	 * Follows SOC: Only registers listeners, doesn't contain business logic.
	 */
	private function register_hook_listeners() {
		// Async tool executor hooks.
		add_action( 'wp_mcp_ai_async_job_queued', array( $this, 'handle_async_job_queued' ), 10, 3 );
		add_action( 'wp_mcp_ai_async_job_started', array( $this, 'handle_async_job_started' ), 10, 3 );
		add_action( 'wp_mcp_ai_async_job_completed', array( $this, 'handle_async_job_completed' ), 10, 4 );
		add_action( 'wp_mcp_ai_async_job_failed', array( $this, 'handle_async_job_failed' ), 10, 4 );

		// Video generation hooks.
		add_action( 'wp_mcp_ai_video_job_queued', array( $this, 'handle_video_job_queued' ), 10, 3 );
		add_action( 'wp_mcp_ai_video_job_completed', array( $this, 'handle_video_job_completed' ), 10, 3 );

		// Cron job lifecycle hooks (orchestration layer integration).
		add_action( 'wp_mcp_ai_cron_job_created', array( $this, 'handle_cron_job_created' ), 10, 2 );
		add_action( 'wp_mcp_ai_cron_job_deleted', array( $this, 'handle_cron_job_deleted' ), 10, 2 );
		add_action( 'wp_mcp_ai_cron_job_executed', array( $this, 'handle_cron_job_executed' ), 10, 2 );
		add_action( 'wp_mcp_ai_cron_job_failed', array( $this, 'handle_cron_job_failed' ), 10, 3 );
	}

	/**
	 * Register an active chat session for notifications
	 *
	 * Call this when a chat session starts to enable real-time notifications.
	 *
	 * @param string $session_id   Unique session identifier.
	 * @param int    $assistant_id Assistant ID for this session.
	 * @param int    $user_id      User ID for this session.
	 */
	public function register_session( $session_id, $assistant_id, $user_id ) {
		$this->active_sessions[ $session_id ] = array(
			'assistant_id' => absint( $assistant_id ),
			'user_id'      => absint( $user_id ),
			'registered'   => time(),
		);
	}

	/**
	 * Unregister a chat session
	 *
	 * @param string $session_id Session identifier.
	 */
	public function unregister_session( $session_id ) {
		if ( isset( $this->active_sessions[ $session_id ] ) ) {
			unset( $this->active_sessions[ $session_id ] );
		}
	}

	/**
	 * Handle async job queued event
	 *
	 * Bridges async tool events to generic job notifier system.
	 * Follows SOC: Only routes events, doesn't contain business logic.
	 *
	 * @param string $job_id    Job identifier.
	 * @param array  $metadata  Job metadata.
	 * @param string $tool_slug Tool slug.
	 */
	public function handle_async_job_queued( $job_id, $metadata, $tool_slug ) {
		// Format notification - don't notify for queued state, only completion.
		// Queued notifications would be too noisy.
		$this->log_notification( 'async_job_queued', $job_id, $metadata );

		// Bridge to generic job notifier system for job bar display.
		do_action( 'wp_mcp_ai_job_started', $job_id, $metadata );
	}

	/**
	 * Handle async job started event
	 *
	 * @param string $job_id    Job identifier.
	 * @param array  $metadata  Job metadata.
	 * @param string $tool_slug Tool slug.
	 */
	public function handle_async_job_started( $job_id, $metadata, $tool_slug ) {
		// Log but don't notify - started events are too noisy.
		$this->log_notification( 'async_job_started', $job_id, $metadata );
	}

	/**
	 * Handle async job completed event
	 *
	 * Bridges async tool events to generic job notifier system.
	 * Follows SOC: Only routes events and formats messages, doesn't contain business logic.
	 *
	 * @param string $job_id    Job identifier.
	 * @param array  $metadata  Job metadata including result.
	 * @param mixed  $result    Tool execution result.
	 * @param string $tool_slug Tool slug.
	 */
	public function handle_async_job_completed( $job_id, $metadata, $result, $tool_slug ) {
		$duration = isset( $metadata['duration'] ) ? round( $metadata['duration'], 2 ) : 0;

		// Format user-friendly tool name.
		$tool_name = $this->format_tool_name( $tool_slug );

		// Create notification message.
		$message = sprintf(
			/* translators: 1: Tool name, 2: Duration in seconds */
			__( '%1$s completed in %2$ss', 'wp-mcp-ai' ),
			$tool_name,
			$duration
		);

		// Dispatch notification to relevant sessions.
		$this->dispatch_notification(
			$job_id,
			$metadata,
			'completed',
			$message,
			array(
				'tool_slug' => $tool_slug,
				'result'    => $result,
			)
		);

		// Bridge to generic job notifier system for job bar display.
		do_action( 'wp_mcp_ai_job_completed', $job_id, $result, $metadata );
	}

	/**
	 * Handle async job failed event
	 *
	 * Bridges async tool events to generic job notifier system.
	 * Follows SOC: Only routes events and formats messages, doesn't contain business logic.
	 *
	 * @param string        $job_id        Job identifier.
	 * @param array         $metadata      Job metadata.
	 * @param string        $error_message Error message.
	 * @param WP_Error|null $error         WP_Error object if available.
	 */
	public function handle_async_job_failed( $job_id, $metadata, $error_message, $error = null ) {
		$tool_slug = isset( $metadata['tool_slug'] ) ? $metadata['tool_slug'] : 'unknown';
		$tool_name = $this->format_tool_name( $tool_slug );

		// Create notification message.
		$message = sprintf(
			/* translators: 1: Tool name, 2: Error message */
			__( '%1$s failed: %2$s', 'wp-mcp-ai' ),
			$tool_name,
			sanitize_text_field( $error_message )
		);

		// Dispatch notification to relevant sessions.
		$this->dispatch_notification(
			$job_id,
			$metadata,
			'failed',
			$message,
			array(
				'tool_slug' => $tool_slug,
				'error'     => $error_message,
			)
		);

		// Bridge to generic job notifier system for job bar display.
		// Create WP_Error if not provided.
		if ( ! is_wp_error( $error ) ) {
			$error = new WP_Error( 'async_tool_failed', $error_message );
		}
		do_action( 'wp_mcp_ai_job_failed', $job_id, $error, $metadata );
	}

	/**
	 * Handle video job queued event
	 *
	 * Bridges video-specific events to generic job notifier system.
	 * Follows SOC: Only routes events, doesn't contain business logic.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Job metadata.
	 * @param array  $args     Generation arguments.
	 */
	public function handle_video_job_queued( $job_id, $metadata, $args ) {
		// Log but don't notify for queued state.
		$this->log_notification( 'video_job_queued', $job_id, $metadata );

		// Bridge to generic job notifier system for job bar display.
		// This allows the chat client's job polling to detect video jobs.
		do_action( 'wp_mcp_ai_job_started', $job_id, $metadata );
	}

	/**
	 * Handle video job completed event
	 *
	 * Bridges video-specific events to generic job notifier system.
	 * Follows SOC: Only routes events and formats messages, doesn't contain business logic.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Job metadata.
	 * @param string $status   Job status ('completed' or 'failed').
	 */
	public function handle_video_job_completed( $job_id, $metadata, $status ) {
		if ( 'completed' === $status ) {
			// Success - notify about video creation.
			$message = __( 'Video generation completed', 'wp-mcp-ai' );

			// Add attachment info if available.
			if ( isset( $metadata['result']['attachment_id'] ) ) {
				$message .= sprintf(
					/* translators: %d: Attachment ID */
					__( ' (saved to media library #%d)', 'wp-mcp-ai' ),
					absint( $metadata['result']['attachment_id'] )
				);
			}

			$this->dispatch_notification(
				$job_id,
				$metadata,
				'completed',
				$message,
				array(
					'type'   => 'video_generation',
					'result' => isset( $metadata['result'] ) ? $metadata['result'] : null,
				)
			);

			// Bridge to generic job notifier system for job bar display.
			// Allows chat client's SSE/REST polling to detect video completion.
			$result = isset( $metadata['result'] ) ? $metadata['result'] : array();
			do_action( 'wp_mcp_ai_job_completed', $job_id, $result, $metadata );
		} elseif ( 'failed' === $status ) {
			// Failure - notify about error.
			$error_message = isset( $metadata['error'] ) ? $metadata['error'] : __( 'Unknown error', 'wp-mcp-ai' );

			$message = sprintf(
				/* translators: %s: Error message */
				__( 'Video generation failed: %s', 'wp-mcp-ai' ),
				sanitize_text_field( $error_message )
			);

			$this->dispatch_notification(
				$job_id,
				$metadata,
				'failed',
				$message,
				array(
					'type'  => 'video_generation',
					'error' => $error_message,
				)
			);

			// Bridge to generic job notifier system for job bar display.
			// Create WP_Error for failed state.
			$error = new WP_Error( 'video_generation_failed', $error_message );
			do_action( 'wp_mcp_ai_job_failed', $job_id, $error, $metadata );
		}
	}

	/**
	 * Handle cron job created event
	 *
	 * Bridges cron events to chat client notifications (agentic loop integration).
	 * Follows SOC: Only routes events and formats messages, doesn't contain business logic.
	 *
	 * @param string $job_id  Job identifier.
	 * @param array  $metadata Job metadata.
	 */
	public function handle_cron_job_created( $job_id, $metadata ) {
		$hook         = isset( $metadata['hook'] ) ? $metadata['hook'] : 'unknown';
		$schedule     = isset( $metadata['schedule'] ) ? $metadata['schedule'] : 'single';
		$next_run     = isset( $metadata['next_run'] ) ? $metadata['next_run'] : '';
		$user_id      = isset( $metadata['user_id'] ) ? absint( $metadata['user_id'] ) : 0;
		$assistant_id = isset( $metadata['assistant_id'] ) ? absint( $metadata['assistant_id'] ) : 0;

		// Format schedule type for display.
		$schedule_type = ( 'single' === $schedule ) ? __( 'one-time', 'wp-mcp-ai' ) : $schedule;

		// Create notification message.
		$message = sprintf(
			/* translators: 1: Hook name, 2: Schedule type, 3: Next run time */
			__( 'Scheduled %1$s event (%2$s) - next run: %3$s', 'wp-mcp-ai' ),
			sanitize_text_field( $hook ),
			sanitize_text_field( $schedule_type ),
			sanitize_text_field( $next_run )
		);

		// Dispatch notification to chat client.
		$this->dispatch_notification(
			$job_id,
			array(
				'context' => array(
					'user_id'      => $user_id,
					'assistant_id' => $assistant_id,
				),
			),
			'scheduled',
			$message,
			array(
				'type'     => 'cron_job_created',
				'hook'     => $hook,
				'schedule' => $schedule,
				'next_run' => $next_run,
			)
		);

		// Log for debugging.
		$this->log_notification( 'cron_job_created', $job_id, $metadata );
	}

	/**
	 * Handle cron job deleted event
	 *
	 * @param string $job_id  Job identifier.
	 * @param array  $metadata Job metadata.
	 */
	public function handle_cron_job_deleted( $job_id, $metadata ) {
		$hook         = isset( $metadata['hook'] ) ? $metadata['hook'] : 'unknown';
		$user_id      = isset( $metadata['user_id'] ) ? absint( $metadata['user_id'] ) : 0;
		$assistant_id = isset( $metadata['assistant_id'] ) ? absint( $metadata['assistant_id'] ) : 0;

		$message = sprintf(
			/* translators: %s: Hook name */
			__( 'Cancelled scheduled event: %s', 'wp-mcp-ai' ),
			sanitize_text_field( $hook )
		);

		$this->dispatch_notification(
			$job_id,
			array(
				'context' => array(
					'user_id'      => $user_id,
					'assistant_id' => $assistant_id,
				),
			),
			'cancelled',
			$message,
			array(
				'type' => 'cron_job_deleted',
				'hook' => $hook,
			)
		);

		$this->log_notification( 'cron_job_deleted', $job_id, $metadata );
	}

	/**
	 * Handle cron job executed event
	 *
	 * @param string $job_id  Job identifier.
	 * @param array  $metadata Job metadata.
	 */
	public function handle_cron_job_executed( $job_id, $metadata ) {
		$hook         = isset( $metadata['hook'] ) ? $metadata['hook'] : 'unknown';
		$user_id      = isset( $metadata['user_id'] ) ? absint( $metadata['user_id'] ) : 0;
		$assistant_id = isset( $metadata['assistant_id'] ) ? absint( $metadata['assistant_id'] ) : 0;
		$duration     = isset( $metadata['duration'] ) ? $metadata['duration'] : null;

		$message = sprintf(
			/* translators: %s: Hook name */
			__( 'Executed scheduled event: %s', 'wp-mcp-ai' ),
			sanitize_text_field( $hook )
		);

		if ( null !== $duration ) {
			$message .= sprintf(
				/* translators: %s: Duration in seconds */
				__( ' (completed in %ss)', 'wp-mcp-ai' ),
				number_format( $duration, 2 )
			);
		}

		$this->dispatch_notification(
			$job_id,
			array(
				'context' => array(
					'user_id'      => $user_id,
					'assistant_id' => $assistant_id,
				),
			),
			'completed',
			$message,
			array(
				'type'     => 'cron_job_executed',
				'hook'     => $hook,
				'duration' => $duration,
			)
		);

		$this->log_notification( 'cron_job_executed', $job_id, $metadata );
	}

	/**
	 * Handle cron job failed event
	 *
	 * @param string $job_id       Job identifier.
	 * @param array  $metadata     Job metadata.
	 * @param string $error_message Error message.
	 */
	public function handle_cron_job_failed( $job_id, $metadata, $error_message ) {
		$hook         = isset( $metadata['hook'] ) ? $metadata['hook'] : 'unknown';
		$user_id      = isset( $metadata['user_id'] ) ? absint( $metadata['user_id'] ) : 0;
		$assistant_id = isset( $metadata['assistant_id'] ) ? absint( $metadata['assistant_id'] ) : 0;

		$message = sprintf(
			/* translators: 1: Hook name, 2: Error message */
			__( 'Scheduled event failed: %1$s - %2$s', 'wp-mcp-ai' ),
			sanitize_text_field( $hook ),
			sanitize_text_field( $error_message )
		);

		$this->dispatch_notification(
			$job_id,
			array(
				'context' => array(
					'user_id'      => $user_id,
					'assistant_id' => $assistant_id,
				),
			),
			'failed',
			$message,
			array(
				'type'  => 'cron_job_failed',
				'hook'  => $hook,
				'error' => $error_message,
			)
		);

		$this->log_notification( 'cron_job_failed', $job_id, array_merge( $metadata, array( 'error' => $error_message ) ) );
	}

	/**
	 * Dispatch notification to relevant chat sessions
	 *
	 * Follows SOC: Only formats and routes notifications, doesn't handle delivery.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Job metadata.
	 * @param string $status   Job status ('completed', 'failed').
	 * @param string $message  User-friendly notification message.
	 * @param array  $data     Additional data for notification.
	 */
	private function dispatch_notification( $job_id, $metadata, $status, $message, $data = array() ) {
		// Get user_id and assistant_id from metadata context.
		$user_id      = 0;
		$assistant_id = 0;

		if ( isset( $metadata['context']['user_id'] ) ) {
			$user_id = absint( $metadata['context']['user_id'] );
		} elseif ( isset( $metadata['args']['user_id'] ) ) {
			$user_id = absint( $metadata['args']['user_id'] );
		}

		if ( isset( $metadata['context']['assistant_id'] ) ) {
			$assistant_id = absint( $metadata['context']['assistant_id'] );
		} elseif ( isset( $metadata['args']['assistant_id'] ) ) {
			$assistant_id = absint( $metadata['args']['assistant_id'] );
		}

		// Build notification payload.
		$notification = array(
			'job_id'       => $job_id,
			'status'       => $status,
			'message'      => $message,
			'user_id'      => $user_id,
			'assistant_id' => $assistant_id,
			'timestamp'    => time(),
			'data'         => $data,
		);

		// Store notification in transient for chat client to retrieve.
		// Using user-specific transient key to avoid conflicts.
		$this->store_pending_notification( $notification, $user_id, $assistant_id );

		// Log notification.
		$this->log_notification( 'notification_dispatched', $job_id, $notification );

		/**
		 * Fires when a job notification is ready to be dispatched.
		 *
		 * Other components can hook into this to deliver notifications via:
		 * - Chat messages (append system bubble)
		 * - Email alerts
		 * - Webhooks
		 * - Push notifications
		 * - SSE events
		 *
		 * @since 1.0.0
		 *
		 * @param array $notification Notification payload.
		 * @param string $job_id      Job identifier.
		 */
		do_action( 'wp_mcp_ai_job_notification', $notification, $job_id );
	}

	/**
	 * Store pending notification for chat client retrieval
	 *
	 * Stores notifications in transients grouped by user and assistant.
	 * Chat client can poll for pending notifications and display them.
	 *
	 * @param array $notification Notification payload.
	 * @param int   $user_id      User ID.
	 * @param int   $assistant_id Assistant ID.
	 */
	private function store_pending_notification( $notification, $user_id, $assistant_id ) {
		// Transient key format: wp_mcp_ai_notifications_{user_id}_{assistant_id}.
		$transient_key = sprintf( 'wp_mcp_ai_notifications_%d_%d', $user_id, $assistant_id );

		// Get existing notifications.
		$notifications = get_transient( $transient_key );
		if ( ! is_array( $notifications ) ) {
			$notifications = array();
		}

		// Add new notification.
		$notifications[] = $notification;

		// Keep only last 10 notifications.
		if ( count( $notifications ) > 10 ) {
			array_shift( $notifications );
		}

		// Store with 1 hour expiry.
		set_transient( $transient_key, $notifications, HOUR_IN_SECONDS );
	}

	/**
	 * Get pending notifications for a user/assistant
	 *
	 * @param int  $user_id      User ID.
	 * @param int  $assistant_id Assistant ID.
	 * @param bool $clear        Whether to clear notifications after retrieval.
	 * @return array Array of notification payloads.
	 */
	public function get_pending_notifications( $user_id, $assistant_id, $clear = true ) {
		$transient_key = sprintf( 'wp_mcp_ai_notifications_%d_%d', $user_id, $assistant_id );

		$notifications = get_transient( $transient_key );
		if ( ! is_array( $notifications ) ) {
			return array();
		}

		// Clear notifications if requested.
		if ( $clear ) {
			delete_transient( $transient_key );
		}

		return $notifications;
	}

	/**
	 * Format tool slug into user-friendly name
	 *
	 * @param string $tool_slug Tool slug.
	 * @return string Formatted tool name.
	 */
	private function format_tool_name( $tool_slug ) {
		// Remove underscores and capitalize words.
		$name = str_replace( '_', ' ', $tool_slug );
		$name = ucwords( $name );

		// Apply filter to allow customization.
		return apply_filters( 'wp_mcp_ai_format_tool_name', $name, $tool_slug );
	}

	/**
	 * Log notification to history
	 *
	 * @param string $event    Event type.
	 * @param string $job_id   Job identifier.
	 * @param array  $data     Event data.
	 */
	private function log_notification( $event, $job_id, $data ) {
		$this->notification_history[] = array(
			'event'     => $event,
			'job_id'    => $job_id,
			'data'      => $data,
			'timestamp' => time(),
		);

		// Keep only last MAX_HISTORY notifications.
		if ( count( $this->notification_history ) > self::MAX_HISTORY ) {
			array_shift( $this->notification_history );
		}

		// Also log to WordPress logger if available.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'event_dispatcher_' . $event,
				sprintf( 'Event dispatcher: %s for job %s', $event, $job_id ),
				array(
					'job_id' => $job_id,
					'event'  => $event,
				)
			);
		}
	}

	/**
	 * Get notification history (for debugging)
	 *
	 * @param int $limit Number of recent notifications to return.
	 * @return array Notification history.
	 */
	public function get_notification_history( $limit = 20 ) {
		$limit = min( absint( $limit ), self::MAX_HISTORY );
		return array_slice( $this->notification_history, -$limit );
	}

	/**
	 * Get active sessions count
	 *
	 * @return int Number of active sessions.
	 */
	public function get_active_sessions_count() {
		return count( $this->active_sessions );
	}
}
