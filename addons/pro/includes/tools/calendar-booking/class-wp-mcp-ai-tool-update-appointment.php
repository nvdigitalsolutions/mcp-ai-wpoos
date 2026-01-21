<?php
/**
 * Update Appointment Tool
 *
 * Updates existing appointments with new client details, time slots,
 * or other booking information. Supports partial updates.
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
 * Tool for updating existing appointments.
 *
 * Features:
 * - Partial field updates
 * - Time slot conflict detection
 * - Status change tracking
 * - Notification on changes
 * - Change history logging
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Update_Appointment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Update appointment tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'update_appointment';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Update Appointment', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Update existing appointments with new client details, time slots, or booking information. Supports partial updates and change tracking.', 'mcp-ai-wpoos-pro' );
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
				'appointment_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID to update (required)', 'mcp-ai-wpoos-pro' ),
				),
				'client_name'        => array(
					'type'        => 'string',
					'description' => __( 'Updated client name', 'mcp-ai-wpoos-pro' ),
				),
				'client_email'       => array(
					'type'        => 'string',
					'description' => __( 'Updated client email', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'client_phone'       => array(
					'type'        => 'string',
					'description' => __( 'Updated client phone', 'mcp-ai-wpoos-pro' ),
				),
				'appointment_type'   => array(
					'type'        => 'string',
					'description' => __( 'Updated appointment type', 'mcp-ai-wpoos-pro' ),
				),
				'start_time'         => array(
					'type'        => 'string',
					'description' => __( 'Updated start time (Y-m-d H:i:s format)', 'mcp-ai-wpoos-pro' ),
				),
				'end_time'           => array(
					'type'        => 'string',
					'description' => __( 'Updated end time (Y-m-d H:i:s format)', 'mcp-ai-wpoos-pro' ),
				),
				'location'           => array(
					'type'        => 'string',
					'description' => __( 'Updated location', 'mcp-ai-wpoos-pro' ),
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Updated notes', 'mcp-ai-wpoos-pro' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Updated status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pending', 'confirmed', 'completed', 'cancelled', 'no-show' ),
				),
				'send_notification'  => array(
					'type'        => 'boolean',
					'description' => __( 'Send update notification to client', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_conflicts'    => array(
					'type'        => 'boolean',
					'description' => __( 'Check for scheduling conflicts when updating time', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'appointment_id' ),
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
				__( 'You do not have permission to update appointments.', 'mcp-ai-wpoos-pro' )
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

		$appointment_id = absint( $arguments['appointment_id'] );
		$appointment    = get_post( $appointment_id );

		if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
			return new WP_Error(
				'invalid_appointment',
				__( 'Invalid appointment ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		$changes = array();
		$updated = false;

		// Update client information.
		if ( isset( $arguments['client_name'] ) ) {
			$client_name = sanitize_text_field( $arguments['client_name'] );
			update_post_meta( $appointment_id, '_client_name', $client_name );
			$changes['client_name'] = $client_name;
			$updated                = true;
		}

		if ( isset( $arguments['client_email'] ) ) {
			$client_email = sanitize_email( $arguments['client_email'] );
			if ( ! is_email( $client_email ) ) {
				return new WP_Error(
					'invalid_email',
					__( 'Invalid email address.', 'mcp-ai-wpoos-pro' )
				);
			}
			update_post_meta( $appointment_id, '_client_email', $client_email );
			$changes['client_email'] = $client_email;
			$updated                 = true;
		}

		if ( isset( $arguments['client_phone'] ) ) {
			$client_phone = sanitize_text_field( $arguments['client_phone'] );
			update_post_meta( $appointment_id, '_client_phone', $client_phone );
			$changes['client_phone'] = $client_phone;
			$updated                 = true;
		}

		// Update appointment details.
		if ( isset( $arguments['appointment_type'] ) ) {
			$appointment_type = sanitize_text_field( $arguments['appointment_type'] );
			update_post_meta( $appointment_id, '_appointment_type', $appointment_type );
			$changes['appointment_type'] = $appointment_type;
			$updated                     = true;
		}

		// Update time slots.
		if ( isset( $arguments['start_time'] ) || isset( $arguments['end_time'] ) ) {
			$start_time = isset( $arguments['start_time'] )
				? sanitize_text_field( $arguments['start_time'] )
				: get_post_meta( $appointment_id, '_start_time', true );

			$end_time = isset( $arguments['end_time'] )
				? sanitize_text_field( $arguments['end_time'] )
				: get_post_meta( $appointment_id, '_end_time', true );

			// Validate time format.
			if ( ! strtotime( $start_time ) || ! strtotime( $end_time ) ) {
				return new WP_Error(
					'invalid_time_format',
					__( 'Invalid time format. Use Y-m-d H:i:s format.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check for conflicts if requested.
			if ( ! empty( $arguments['check_conflicts'] ) ) {
				$conflicts = $this->check_time_slot_conflicts( $start_time, $end_time, $appointment_id );
				if ( ! empty( $conflicts ) ) {
					return new WP_Error(
						'scheduling_conflict',
						sprintf(
							/* translators: %d: Number of conflicting appointments */
							__( 'Scheduling conflict detected. %d existing appointment(s) overlap with this time slot.', 'mcp-ai-wpoos-pro' ),
							count( $conflicts )
						),
						array( 'conflicts' => $conflicts )
					);
				}
			}

			update_post_meta( $appointment_id, '_start_time', $start_time );
			update_post_meta( $appointment_id, '_end_time', $end_time );
			$changes['start_time'] = $start_time;
			$changes['end_time']   = $end_time;
			$updated               = true;
		}

		// Update location.
		if ( isset( $arguments['location'] ) ) {
			$location = sanitize_text_field( $arguments['location'] );
			update_post_meta( $appointment_id, '_location', $location );
			$changes['location'] = $location;
			$updated             = true;
		}

		// Update notes.
		if ( isset( $arguments['notes'] ) ) {
			wp_update_post(
				array(
					'ID'           => $appointment_id,
					'post_content' => sanitize_textarea_field( $arguments['notes'] ),
				)
			);
			$changes['notes'] = $arguments['notes'];
			$updated          = true;
		}

		// Update status.
		if ( isset( $arguments['status'] ) ) {
			$status = sanitize_text_field( $arguments['status'] );
			$valid_statuses = array( 'pending', 'confirmed', 'completed', 'cancelled', 'no-show' );
			if ( ! in_array( $status, $valid_statuses, true ) ) {
				return new WP_Error(
					'invalid_status',
					__( 'Invalid appointment status.', 'mcp-ai-wpoos-pro' )
				);
			}
			update_post_meta( $appointment_id, '_status', $status );
			$changes['status'] = $status;
			$updated           = true;
		}

		if ( ! $updated ) {
			return new WP_Error(
				'no_changes',
				__( 'No changes were made to the appointment.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Log changes.
		$this->log_appointment_changes( $appointment_id, $changes, $current_user_id );

		// Send notification if requested.
		$notification_sent = false;
		if ( ! empty( $arguments['send_notification'] ) ) {
			$client_email = get_post_meta( $appointment_id, '_client_email', true );
			if ( $client_email ) {
				$notification_sent = $this->send_update_notification( $appointment_id, $client_email, $changes );
			}
		}

		return array(
			'success'           => true,
			'appointment_id'    => $appointment_id,
			'changes'           => $changes,
			'notification_sent' => $notification_sent,
			'message'           => __( 'Appointment updated successfully.', 'mcp-ai-wpoos-pro' ),
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
	 * Log appointment changes.
	 *
	 * @param int   $appointment_id Appointment ID.
	 * @param array $changes        Array of changes.
	 * @param int   $user_id        User who made changes.
	 */
	private function log_appointment_changes( $appointment_id, $changes, $user_id ) {
		$history = get_post_meta( $appointment_id, '_change_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'timestamp' => current_time( 'mysql' ),
			'user_id'   => $user_id,
			'changes'   => $changes,
		);

		update_post_meta( $appointment_id, '_change_history', $history );
	}

	/**
	 * Send update notification.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $email          Client email.
	 * @param array  $changes        Array of changes.
	 * @return bool Whether email was sent successfully.
	 */
	private function send_update_notification( $appointment_id, $email, $changes ) {
		$subject = sprintf(
			/* translators: %d: Appointment ID */
			__( 'Appointment Update #%d', 'mcp-ai-wpoos-pro' ),
			$appointment_id
		);

		$message = __( "Your appointment has been updated.\n\nChanges:\n", 'mcp-ai-wpoos-pro' );
		foreach ( $changes as $field => $value ) {
			$message .= sprintf( "- %s: %s\n", ucwords( str_replace( '_', ' ', $field ) ), $value );
		}

		return wp_mail( $email, $subject, $message );
	}
}
