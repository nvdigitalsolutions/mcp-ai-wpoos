<?php
/**
 * Tool for creating health reminders and notifications.
 *
 * Creates reminders for medication, checkups, prescriptions refills, and other health-related events.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates health reminders and notifications.
 */
class WP_MCP_AI_Tool_Create_Health_Reminder implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_health_reminder';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Health Reminder', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates health reminders and notifications for medications, checkups, prescription refills, and other health events. Supports recurring reminders with customizable frequency. Integrates with WordPress cron system for reliable delivery.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this reminder is for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'reminder_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of health reminder (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'medication', 'checkup', 'prescription_refill', 'lab_test', 'vaccination', 'follow_up', 'custom' ),
				),
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Reminder title (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'     => array(
					'type'        => 'string',
					'description' => __( 'Reminder description or notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'reminder_date'   => array(
					'type'        => 'string',
					'description' => __( 'Date for reminder (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'reminder_time'   => array(
					'type'        => 'string',
					'description' => __( 'Time for reminder (HH:MM format, 24-hour) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
					'default'     => '09:00',
				),
				'is_recurring'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether reminder should recur (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'recurrence_rule' => array(
					'type'        => 'string',
					'description' => __( 'Recurrence pattern if recurring (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'daily', 'weekly', 'bi-weekly', 'monthly', 'quarterly', 'yearly', 'custom' ),
				),
				'notification_methods' => array(
					'type'        => 'array',
					'description' => __( 'Notification delivery methods (optional, default: email)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'email', 'sms', 'push', 'in-app' ),
					),
					'default'     => array( 'email' ),
				),
				'advance_notice_days' => array(
					'type'        => 'integer',
					'description' => __( 'Days before event to send reminder (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 365,
					'default'     => 1,
				),
				'related_record_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of related health record (prescription, checkup, etc.) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'related_record_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of related health record (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'prescription', 'checkup', 'medical_record', 'allergy' ),
				),
				'priority'        => array(
					'type'        => 'string',
					'description' => __( 'Reminder priority level (optional, default: normal)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'normal', 'high', 'urgent' ),
					'default'     => 'normal',
				),
			),
			'required'             => array( 'member_id', 'reminder_type', 'title', 'reminder_date' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'cron-scheduling' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health and Wellness management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create health reminders.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id            = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$reminder_type        = isset( $arguments['reminder_type'] ) ? sanitize_text_field( $arguments['reminder_type'] ) : '';
		$title                = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description          = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$reminder_date        = isset( $arguments['reminder_date'] ) ? sanitize_text_field( $arguments['reminder_date'] ) : '';
		$reminder_time        = isset( $arguments['reminder_time'] ) ? sanitize_text_field( $arguments['reminder_time'] ) : '09:00';
		$is_recurring         = isset( $arguments['is_recurring'] ) ? (bool) $arguments['is_recurring'] : false;
		$recurrence_rule      = isset( $arguments['recurrence_rule'] ) ? sanitize_text_field( $arguments['recurrence_rule'] ) : '';
		$notification_methods = isset( $arguments['notification_methods'] ) ? array_map( 'sanitize_text_field', (array) $arguments['notification_methods'] ) : array( 'email' );
		$advance_notice_days  = isset( $arguments['advance_notice_days'] ) ? absint( $arguments['advance_notice_days'] ) : 1;
		$related_record_id    = isset( $arguments['related_record_id'] ) ? absint( $arguments['related_record_id'] ) : 0;
		$related_record_type  = isset( $arguments['related_record_type'] ) ? sanitize_text_field( $arguments['related_record_type'] ) : '';
		$priority             = isset( $arguments['priority'] ) ? sanitize_text_field( $arguments['priority'] ) : 'normal';

		// Validate required fields.
		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $reminder_type ) {
			return new WP_Error( 'wp_mcp_ai_missing_reminder_type', __( 'Reminder type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Reminder title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $reminder_date ) {
			return new WP_Error( 'wp_mcp_ai_missing_reminder_date', __( 'Reminder date is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate date format.
		$date_obj = \DateTime::createFromFormat( 'Y-m-d', $reminder_date );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $reminder_date ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid reminder date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate time format.
		if ( $reminder_time && ! preg_match( '/^\d{2}:\d{2}$/', $reminder_time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Invalid reminder time format. Use HH:MM (24-hour).', 'mcp-ai-wpoos-pro' ) );
		}

		// Calculate the actual notification timestamp (reminder_date - advance_notice_days).
		$reminder_timestamp    = strtotime( $reminder_date . ' ' . $reminder_time );
		$notification_timestamp = $reminder_timestamp - ( $advance_notice_days * DAY_IN_SECONDS );

		// Store reminder as custom post type or option.
		// Using WordPress option for simplicity. Could be enhanced to use CPT for better management.
		$reminder_data = array(
			'member_id'            => $member_id,
			'member_name'          => $member->post_title,
			'reminder_type'        => $reminder_type,
			'title'                => $title,
			'description'          => $description,
			'reminder_date'        => $reminder_date,
			'reminder_time'        => $reminder_time,
			'reminder_timestamp'   => $reminder_timestamp,
			'notification_timestamp' => $notification_timestamp,
			'is_recurring'         => $is_recurring,
			'recurrence_rule'      => $recurrence_rule,
			'notification_methods' => $notification_methods,
			'advance_notice_days'  => $advance_notice_days,
			'related_record_id'    => $related_record_id,
			'related_record_type'  => $related_record_type,
			'priority'             => $priority,
			'status'               => 'active',
			'created_at'           => current_time( 'mysql' ),
			'created_by'           => $current_user_id,
		);

		// Generate unique reminder ID.
		$reminder_id = 'health_reminder_' . uniqid();

		// Store reminder in options table (transient-like storage).
		$all_reminders = get_option( 'wp_mcp_ai_health_reminders', array() );
		$all_reminders[ $reminder_id ] = $reminder_data;
		update_option( 'wp_mcp_ai_health_reminders', $all_reminders );

		// Schedule WordPress cron job for notification.
		$hook_name = 'wp_mcp_ai_health_reminder_notification';
		wp_schedule_single_event(
			$notification_timestamp,
			$hook_name,
			array(
				'reminder_id'   => $reminder_id,
				'reminder_data' => $reminder_data,
			)
		);

		// If recurring, schedule the next occurrence.
		if ( $is_recurring && $recurrence_rule ) {
			$next_occurrence = $this->calculate_next_occurrence( $reminder_timestamp, $recurrence_rule );
			if ( $next_occurrence ) {
				// Store recurring schedule info.
				$reminder_data['next_occurrence'] = $next_occurrence;
				$all_reminders[ $reminder_id ] = $reminder_data;
				update_option( 'wp_mcp_ai_health_reminders', $all_reminders );
			}
		}

		return array(
			'success'               => true,
			'message'               => __( 'Health reminder created successfully.', 'mcp-ai-wpoos-pro' ),
			'reminder_id'           => $reminder_id,
			'member_id'             => $member_id,
			'member_name'           => $member->post_title,
			'reminder_type'         => $reminder_type,
			'title'                 => $title,
			'reminder_date'         => $reminder_date,
			'reminder_time'         => $reminder_time,
			'notification_scheduled_for' => gmdate( 'Y-m-d H:i:s', $notification_timestamp ),
			'is_recurring'          => $is_recurring,
			'recurrence_rule'       => $recurrence_rule,
			'notification_methods'  => $notification_methods,
			'priority'              => $priority,
			'advance_notice_days'   => $advance_notice_days,
		);
	}

	/**
	 * Calculate next occurrence for recurring reminders.
	 *
	 * @param int    $current_timestamp Current reminder timestamp.
	 * @param string $recurrence_rule   Recurrence pattern.
	 * @return int|null Next occurrence timestamp or null if not calculable.
	 */
	private function calculate_next_occurrence( $current_timestamp, $recurrence_rule ) {
		switch ( $recurrence_rule ) {
			case 'daily':
				return $current_timestamp + DAY_IN_SECONDS;

			case 'weekly':
				return $current_timestamp + ( 7 * DAY_IN_SECONDS );

			case 'bi-weekly':
				return $current_timestamp + ( 14 * DAY_IN_SECONDS );

			case 'monthly':
				return strtotime( '+1 month', $current_timestamp );

			case 'quarterly':
				return strtotime( '+3 months', $current_timestamp );

			case 'yearly':
				return strtotime( '+1 year', $current_timestamp );

			default:
				return null;
		}
	}
}
