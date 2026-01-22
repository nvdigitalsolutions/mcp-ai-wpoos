<?php
/**
 * Reschedule Appointment Tool
 *
 * Reschedules appointments to new time slots automatically.
 * Handles conflict detection and notifications.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 * @phase Phase 2.6 - Calendar Booking Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for rescheduling appointments.
 *
 * Features:
 * - Automatic time slot validation
 * - Conflict detection
 * - Change history tracking
 * - Client notifications
 * - Calendar sync updates
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Reschedule_Appointment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 2.6.0
	 *
	 * @return bool True if calendar booking toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 2.6.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			return __( 'Calendar Booking toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Reschedule appointment tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'reschedule_appointment';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Reschedule Appointment', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Reschedule appointments to new time slots with automatic conflict detection and client notifications.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID to reschedule (required)', 'mcp-ai-wpoos-pro' ),
				),
				'new_start_time'    => array(
					'type'        => 'string',
					'description' => __( 'New start time (Y-m-d H:i:s format, required)', 'mcp-ai-wpoos-pro' ),
				),
				'new_end_time'      => array(
					'type'        => 'string',
					'description' => __( 'New end time (Y-m-d H:i:s format)', 'mcp-ai-wpoos-pro' ),
				),
				'reason'            => array(
					'type'        => 'string',
					'description' => __( 'Reason for rescheduling', 'mcp-ai-wpoos-pro' ),
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'description' => __( 'Send reschedule notification to client', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_conflicts'   => array(
					'type'        => 'boolean',
					'description' => __( 'Check for scheduling conflicts', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'appointment_id', 'new_start_time' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'phase-2.6',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to reschedule appointments.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate appointment ID.
		if ( empty( $arguments['appointment_id'] ) ) {
			return new WP_Error(
				'missing_appointment_id',
				__( 'Appointment ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['new_start_time'] ) ) {
			return new WP_Error(
				'missing_new_start_time',
				__( 'New start time is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$appointment_id = absint( $arguments['appointment_id'] );
		$appointment    = get_post( $appointment_id );

		if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
			return new WP_Error(
				'invalid_appointment',
				__( 'Invalid appointment ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get original times.
		$old_start_time = get_post_meta( $appointment_id, '_start_time', true );
		$old_end_time   = get_post_meta( $appointment_id, '_end_time', true );

		// Sanitize new times.
		$new_start_time = sanitize_text_field( $arguments['new_start_time'] );
		
		// Calculate new end time.
		if ( ! empty( $arguments['new_end_time'] ) ) {
			$new_end_time = sanitize_text_field( $arguments['new_end_time'] );
		} else {
			// Maintain same duration.
			$old_duration = strtotime( $old_end_time ) - strtotime( $old_start_time );
			$new_end_time = date( 'Y-m-d H:i:s', strtotime( $new_start_time ) + $old_duration );
		}

		// Validate time format.
		if ( ! strtotime( $new_start_time ) || ! strtotime( $new_end_time ) ) {
			return new WP_Error(
				'invalid_time_format',
				__( 'Invalid time format. Use Y-m-d H:i:s format.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if new time is in the future.
		if ( strtotime( $new_start_time ) < time() ) {
			return new WP_Error(
				'past_time',
				__( 'Cannot reschedule to a time in the past.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check for conflicts if requested.
		if ( ! empty( $arguments['check_conflicts'] ) ) {
			$conflicts = $this->check_time_slot_conflicts( $new_start_time, $new_end_time, $appointment_id );
			if ( ! empty( $conflicts ) ) {
				return new WP_Error(
					'scheduling_conflict',
					sprintf(
						/* translators: %d: Number of conflicting appointments */
						__( 'Scheduling conflict detected. %d existing appointment(s) overlap with the new time slot.', 'mcp-ai-wpoos-pro' ),
						count( $conflicts )
					),
					array( 'conflicts' => $conflicts )
				);
			}
		}

		// Update appointment times.
		update_post_meta( $appointment_id, '_start_time', $new_start_time );
		update_post_meta( $appointment_id, '_end_time', $new_end_time );
		update_post_meta( $appointment_id, '_rescheduled_at', current_time( 'mysql' ) );
		update_post_meta( $appointment_id, '_rescheduled_by', $current_user_id );

		// Store reschedule reason.
		$reason = ! empty( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '';
		if ( $reason ) {
			update_post_meta( $appointment_id, '_reschedule_reason', $reason );
		}

		// Log reschedule.
		$this->log_reschedule(
			$appointment_id,
			$current_user_id,
			$old_start_time,
			$old_end_time,
			$new_start_time,
			$new_end_time,
			$reason
		);

		// Send notification if requested.
		$notification_sent = false;
		if ( ! empty( $arguments['send_notification'] ) ) {
			$client_email = get_post_meta( $appointment_id, '_client_email', true );
			$client_name  = get_post_meta( $appointment_id, '_client_name', true );
			
			if ( $client_email ) {
				$notification_sent = $this->send_reschedule_email(
					$appointment_id,
					$client_email,
					$client_name,
					$old_start_time,
					$new_start_time,
					$new_end_time,
					$reason
				);
			}
		}

		return array(
			'success'           => true,
			'appointment_id'    => $appointment_id,
			'old_start_time'    => $old_start_time,
			'old_end_time'      => $old_end_time,
			'new_start_time'    => $new_start_time,
			'new_end_time'      => $new_end_time,
			'rescheduled_at'    => current_time( 'mysql' ),
			'notification_sent' => $notification_sent,
			'message'           => __( 'Appointment rescheduled successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check for time slot conflicts.
	 *
	 * @param string $start_time     Start time.
	 * @param string $end_time       End time.
	 * @param int    $appointment_id Current appointment ID to exclude.
	 * @return array Array of conflicting appointment IDs.
	 */
	private function check_time_slot_conflicts( $start_time, $end_time, $appointment_id ) {
		$conflicts = array();

		$args = array(
			'post_type'      => 'mcp_appointment',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'post__not_in'   => array( $appointment_id ),
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_status',
					'value'   => array( 'confirmed', 'pending' ),
					'compare' => 'IN',
				),
				array(
					'relation' => 'OR',
					array(
						'relation' => 'AND',
						array(
							'key'     => '_start_time',
							'value'   => $start_time,
							'compare' => '<=',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => '_end_time',
							'value'   => $start_time,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => '_start_time',
							'value'   => $end_time,
							'compare' => '<',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => '_end_time',
							'value'   => $end_time,
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$conflicts[] = get_the_ID();
			}
			wp_reset_postdata();
		}

		return $conflicts;
	}

	/**
	 * Log reschedule details.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param int    $user_id        User who rescheduled.
	 * @param string $old_start      Old start time.
	 * @param string $old_end        Old end time.
	 * @param string $new_start      New start time.
	 * @param string $new_end        New end time.
	 * @param string $reason         Reschedule reason.
	 */
	private function log_reschedule( $appointment_id, $user_id, $old_start, $old_end, $new_start, $new_end, $reason ) {
		$log_entry = array(
			'action'        => 'rescheduled',
			'timestamp'     => current_time( 'mysql' ),
			'user_id'       => $user_id,
			'old_start'     => $old_start,
			'old_end'       => $old_end,
			'new_start'     => $new_start,
			'new_end'       => $new_end,
			'reason'        => $reason,
		);

		$activity_log = get_post_meta( $appointment_id, '_activity_log', true );
		if ( ! is_array( $activity_log ) ) {
			$activity_log = array();
		}

		$activity_log[] = $log_entry;
		update_post_meta( $appointment_id, '_activity_log', $activity_log );
	}

	/**
	 * Send reschedule email.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $email          Client email.
	 * @param string $name           Client name.
	 * @param string $old_start      Old start time.
	 * @param string $new_start      New start time.
	 * @param string $new_end        New end time.
	 * @param string $reason         Reschedule reason.
	 * @return bool Whether email was sent successfully.
	 */
	private function send_reschedule_email( $appointment_id, $email, $name, $old_start, $new_start, $new_end, $reason ) {
		$subject = sprintf(
			/* translators: %d: Appointment ID */
			__( 'Appointment Rescheduled #%d', 'mcp-ai-wpoos-pro' ),
			$appointment_id
		);

		$message = sprintf(
			/* translators: 1: Client name, 2: Old time, 3: New start time, 4: New end time */
			__( "Hello %1\$s,\n\nYour appointment has been rescheduled.\n\nOriginal time: %2\$s\nNew time: %3\$s to %4\$s\n\n", 'mcp-ai-wpoos-pro' ),
			$name,
			$old_start,
			$new_start,
			$new_end
		);

		if ( $reason ) {
			$message .= sprintf(
				/* translators: %s: Reschedule reason */
				__( "Reason: %s\n\n", 'mcp-ai-wpoos-pro' ),
				$reason
			);
		}

		$message .= __( "Thank you for your understanding!", 'mcp-ai-wpoos-pro' );

		return wp_mail( $email, $subject, $message );
	}
}
