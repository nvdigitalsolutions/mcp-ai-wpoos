<?php
/**
 * Create Appointment Tool
 *
 * Creates new appointments with client details, time slots, and booking information.
 * Supports multiple appointment types, duration settings, and custom metadata.
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
 * Tool for creating new appointments.
 *
 * Features:
 * - Client information management
 * - Time slot validation
 * - Appointment type selection
 * - Custom metadata support
 * - Automatic conflict detection
 * - Email notifications
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Create_Appointment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 2.6.0
	 *
	 * @return bool True if calendar booking toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if calendar booking toolkit is enabled.
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

		return __( 'Create appointment tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_appointment';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Create Appointment', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a new appointment or update an existing appointment. If appointment_id is provided, updates the existing appointment instead of creating a new one. Supports client details, time slots, booking information, conflict detection and automatic notifications. Use this tool for both creating new appointments and updating existing ones.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Optional appointment ID. If provided, updates the existing appointment instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'client_name'       => array(
					'type'        => 'string',
					'description' => __( 'Client full name (required)', 'mcp-ai-wpoos-pro' ),
				),
				'client_email'      => array(
					'type'        => 'string',
					'description' => __( 'Client email address (required)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'client_phone'      => array(
					'type'        => 'string',
					'description' => __( 'Client phone number', 'mcp-ai-wpoos-pro' ),
				),
				'appointment_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of appointment (consultation, meeting, service, etc.)', 'mcp-ai-wpoos-pro' ),
				),
				'start_time'        => array(
					'type'        => 'string',
					'description' => __( 'Appointment start time (Y-m-d H:i:s format)', 'mcp-ai-wpoos-pro' ),
				),
				'end_time'          => array(
					'type'        => 'string',
					'description' => __( 'Appointment end time (Y-m-d H:i:s format)', 'mcp-ai-wpoos-pro' ),
				),
				'duration_minutes'  => array(
					'type'        => 'integer',
					'description' => __( 'Duration in minutes (alternative to end_time)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 15,
					'maximum'     => 480,
				),
				'location'          => array(
					'type'        => 'string',
					'description' => __( 'Appointment location or virtual meeting link', 'mcp-ai-wpoos-pro' ),
				),
				'notes'             => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or special requests', 'mcp-ai-wpoos-pro' ),
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'description' => __( 'Send confirmation email to client', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_conflicts'   => array(
					'type'        => 'boolean',
					'description' => __( 'Check for scheduling conflicts', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'metadata'          => array(
					'type'        => 'object',
					'description' => __( 'Additional custom metadata', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'client_name', 'client_email', 'start_time' ),
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
				__( 'You do not have permission to create appointments.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_available',
				self::get_unavailable_reason()
			);
		}

		// Check if this is an update operation.
		$appointment_id       = isset( $arguments['appointment_id'] ) ? absint( $arguments['appointment_id'] ) : 0;
		$is_update            = false;
		$existing_appointment = null;

		if ( $appointment_id ) {
			// Verify appointment exists and user has permission to update it.
			$existing_appointment = get_post( $appointment_id );

			if ( ! $existing_appointment || 'mcp_appointment' !== $existing_appointment->post_type ) {
				return new WP_Error( 'wp_mcp_ai_appointment_not_found', __( 'Appointment not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have manage_options capability.
			$is_author  = absint( $existing_appointment->post_author ) === $current_user_id;
			$can_manage = user_can( $current_user_id, 'manage_options' );

			if ( ! $is_author && ! $can_manage ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this appointment.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate required fields.
		if ( empty( $arguments['client_name'] ) ) {
			return new WP_Error(
				'missing_client_name',
				__( 'Client name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['client_email'] ) || ! is_email( $arguments['client_email'] ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Valid client email address is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['start_time'] ) ) {
			return new WP_Error(
				'missing_start_time',
				__( 'Appointment start time is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$client_name  = sanitize_text_field( $arguments['client_name'] );
		$client_email = sanitize_email( $arguments['client_email'] );
		$client_phone = ! empty( $arguments['client_phone'] ) ? sanitize_text_field( $arguments['client_phone'] ) : '';
		$start_time   = sanitize_text_field( $arguments['start_time'] );

		// Calculate end time.
		$end_time = '';
		if ( ! empty( $arguments['end_time'] ) ) {
			$end_time = sanitize_text_field( $arguments['end_time'] );
		} elseif ( ! empty( $arguments['duration_minutes'] ) ) {
			$duration = absint( $arguments['duration_minutes'] );
			$start_dt = new DateTime( $start_time );
			$start_dt->modify( "+{$duration} minutes" );
			$end_time = $start_dt->format( 'Y-m-d H:i:s' );
		} else {
			// Default to 60 minutes.
			$start_dt = new DateTime( $start_time );
			$start_dt->modify( '+60 minutes' );
			$end_time = $start_dt->format( 'Y-m-d H:i:s' );
		}

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

		if ( $is_update ) {
			// Update existing appointment.
			$appointment_data = array(
				'ID'           => $appointment_id,
				'post_title'   => sprintf(
					/* translators: 1: Client name, 2: Start time */
					__( 'Appointment: %1$s - %2$s', 'mcp-ai-wpoos-pro' ),
					$client_name,
					$start_time
				),
				'post_content' => ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '',
			);

			$result = wp_update_post( $appointment_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update appointment metadata.
			update_post_meta( $appointment_id, '_client_name', $client_name );
			update_post_meta( $appointment_id, '_client_email', $client_email );
			update_post_meta( $appointment_id, '_client_phone', $client_phone );
			update_post_meta( $appointment_id, '_appointment_type', ! empty( $arguments['appointment_type'] ) ? sanitize_text_field( $arguments['appointment_type'] ) : 'general' );
			update_post_meta( $appointment_id, '_start_time', $start_time );
			update_post_meta( $appointment_id, '_end_time', $end_time );
			update_post_meta( $appointment_id, '_location', ! empty( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '' );

			// Store custom metadata if provided.
			if ( ! empty( $arguments['metadata'] ) && is_array( $arguments['metadata'] ) ) {
				foreach ( $arguments['metadata'] as $key => $value ) {
					$meta_key = '_custom_' . sanitize_key( $key );
					update_post_meta( $appointment_id, $meta_key, sanitize_text_field( $value ) );
				}
			}

			// Send notification if requested.
			$notification_sent = false;
			if ( ! empty( $arguments['send_notification'] ) ) {
				$notification_sent = $this->send_confirmation_email(
					$appointment_id,
					$client_email,
					$client_name,
					$start_time,
					$end_time
				);
			}

			return array(
				'success'           => true,
				'appointment_id'    => $appointment_id,
				'appointment_title' => get_the_title( $appointment_id ),
				'client_name'       => $client_name,
				'start_time'        => $start_time,
				'end_time'          => $end_time,
				'status'            => 'confirmed',
				'notification_sent' => $notification_sent,
				'updated'           => true,
				'message'           => __( 'Appointment updated successfully.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Create appointment post.
		$appointment_data = array(
			'post_type'    => 'mcp_appointment',
			'post_title'   => sprintf(
				/* translators: 1: Client name, 2: Start time */
				__( 'Appointment: %1$s - %2$s', 'mcp-ai-wpoos-pro' ),
				$client_name,
				$start_time
			),
			'post_content' => ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '',
			'post_status'  => 'publish',
			'meta_input'   => array(
				'_client_name'      => $client_name,
				'_client_email'     => $client_email,
				'_client_phone'     => $client_phone,
				'_appointment_type' => ! empty( $arguments['appointment_type'] ) ? sanitize_text_field( $arguments['appointment_type'] ) : 'general',
				'_start_time'       => $start_time,
				'_end_time'         => $end_time,
				'_location'         => ! empty( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '',
				'_status'           => 'confirmed',
				'_created_by'       => $current_user_id,
				'_created_at'       => current_time( 'mysql' ),
			),
		);

		$appointment_id = wp_insert_post( $appointment_data, true );

		if ( is_wp_error( $appointment_id ) ) {
			return $appointment_id;
		}

		// Store custom metadata if provided.
		if ( ! empty( $arguments['metadata'] ) && is_array( $arguments['metadata'] ) ) {
			foreach ( $arguments['metadata'] as $key => $value ) {
				$meta_key = '_custom_' . sanitize_key( $key );
				update_post_meta( $appointment_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		// Send notification if requested.
		$notification_sent = false;
		if ( ! empty( $arguments['send_notification'] ) ) {
			$notification_sent = $this->send_confirmation_email(
				$appointment_id,
				$client_email,
				$client_name,
				$start_time,
				$end_time
			);
		}

		return array(
			'success'           => true,
			'appointment_id'    => $appointment_id,
			'appointment_title' => get_the_title( $appointment_id ),
			'client_name'       => $client_name,
			'start_time'        => $start_time,
			'end_time'          => $end_time,
			'status'            => 'confirmed',
			'notification_sent' => $notification_sent,
			'updated'           => false,
			'message'           => __( 'Appointment created successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check for time slot conflicts.
	 *
	 * @param string $start_time     Start time.
	 * @param string $end_time       End time.
	 * @param int    $exclude_post_id Optional post ID to exclude from conflict check (for updates).
	 * @return array Array of conflicting appointment IDs.
	 */
	private function check_time_slot_conflicts( $start_time, $end_time, $exclude_post_id = 0 ) {
		$conflicts = array();

		$args = array(
			'post_type'      => 'mcp_appointment',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
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
	 * Send confirmation email.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $email          Client email.
	 * @param string $name           Client name.
	 * @param string $start_time     Start time.
	 * @param string $end_time       End time.
	 * @return bool Whether email was sent successfully.
	 */
	private function send_confirmation_email( $appointment_id, $email, $name, $start_time, $end_time ) {
		$subject = sprintf(
			/* translators: %d: Appointment ID */
			__( 'Appointment Confirmation #%d', 'mcp-ai-wpoos-pro' ),
			$appointment_id
		);

		$message = sprintf(
			/* translators: 1: Client name, 2: Start time, 3: End time */
			__( "Hello %1\$s,\n\nYour appointment has been confirmed.\n\nDate/Time: %2\$s to %3\$s\n\nThank you!", 'mcp-ai-wpoos-pro' ),
			$name,
			$start_time,
			$end_time
		);

		return wp_mail( $email, $subject, $message );
	}
}
