<?php
/**
 * wellness/reminders-research/class-wp-mcp-ai-tool-create-health-reminder.php (ecosystem port — Wave F4, healthcare wellness breadth batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-create-health-reminder.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the capture-tool-base require resolves from the
 * addon's already-ported `src/tools/capture/` copy; the media-worker-client trait resolves via
 * the entry's `src/traits/` probe).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
		return __( 'Create Health Reminder', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates health reminders and notifications for medications, checkups, prescription refills, and other health events. Supports recurring reminders with customizable frequency. Integrates with WordPress cron system for reliable delivery.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this reminder is for (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'reminder_type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of health reminder (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'medication', 'checkup', 'prescription_refill', 'lab_test', 'vaccination', 'follow_up', 'custom' ),
				),
				'title'                => array(
					'type'        => 'string',
					'description' => __( 'Reminder title (required)', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'          => array(
					'type'        => 'string',
					'description' => __( 'Reminder description or notes (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 2000,
				),
				'reminder_date'        => array(
					'type'        => 'string',
					'description' => __( 'Date for reminder (YYYY-MM-DD) (required)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'reminder_time'        => array(
					'type'        => 'string',
					'description' => __( 'Time for reminder (HH:MM format, 24-hour) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
					'default'     => '09:00',
				),
				'is_recurring'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether reminder should recur (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'recurrence_rule'      => array(
					'type'        => 'string',
					'description' => __( 'Recurrence pattern if recurring (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'daily', 'weekly', 'bi-weekly', 'monthly', 'quarterly', 'yearly', 'custom' ),
				),
				'notification_methods' => array(
					'type'        => 'array',
					'description' => __( 'Notification delivery methods (optional, default: email)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'email', 'sms', 'push', 'in-app' ),
					),
					'default'     => array( 'email' ),
				),
				'advance_notice_days'  => array(
					'type'        => 'integer',
					'description' => __( 'Days before event to send reminder (optional, default: 1)', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'maximum'     => 365,
					'default'     => 1,
				),
				'related_record_id'    => array(
					'type'        => 'integer',
					'description' => __( 'ID of related health record (prescription, checkup, etc.) (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'related_record_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of related health record (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'prescription', 'checkup', 'medical_record', 'allergy' ),
				),
				'priority'             => array(
					'type'        => 'string',
					'description' => __( 'Reminder priority level (optional, default: normal)', 'nvoos-content-graph-pro' ),
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
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_member',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver', 'patient' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create health reminders.', 'nvoos-content-graph-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! $reminder_type ) {
			return new WP_Error( 'wp_mcp_ai_missing_reminder_type', __( 'Reminder type is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Reminder title is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! $reminder_date ) {
			return new WP_Error( 'wp_mcp_ai_missing_reminder_date', __( 'Reminder date is required.', 'nvoos-content-graph-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'nvoos-content-graph-pro' ) );
		}

		// Validate date format.
		$date_obj = \DateTime::createFromFormat( 'Y-m-d', $reminder_date );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $reminder_date ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid reminder date format. Use YYYY-MM-DD.', 'nvoos-content-graph-pro' ) );
		}

		// Validate time format.
		if ( $reminder_time && ! preg_match( '/^\d{2}:\d{2}$/', $reminder_time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time', __( 'Invalid reminder time format. Use HH:MM (24-hour).', 'nvoos-content-graph-pro' ) );
		}

		// Calculate the actual notification timestamp (reminder_date - advance_notice_days).
		$reminder_timestamp     = strtotime( $reminder_date . ' ' . $reminder_time );
		$notification_timestamp = $reminder_timestamp - ( $advance_notice_days * DAY_IN_SECONDS );

		// Store reminder as custom post type or option.
		// Using WordPress option for simplicity. Could be enhanced to use CPT for better management.
		$reminder_data = array(
			'member_id'              => $member_id,
			'member_name'            => $member->post_title,
			'reminder_type'          => $reminder_type,
			'title'                  => $title,
			'description'            => $description,
			'reminder_date'          => $reminder_date,
			'reminder_time'          => $reminder_time,
			'reminder_timestamp'     => $reminder_timestamp,
			'notification_timestamp' => $notification_timestamp,
			'is_recurring'           => $is_recurring,
			'recurrence_rule'        => $recurrence_rule,
			'notification_methods'   => $notification_methods,
			'advance_notice_days'    => $advance_notice_days,
			'related_record_id'      => $related_record_id,
			'related_record_type'    => $related_record_type,
			'priority'               => $priority,
			'status'                 => 'active',
			'created_at'             => current_time( 'mysql' ),
			'created_by'             => $current_user_id,
		);

		// Generate unique reminder ID.
		$reminder_id = 'health_reminder_' . uniqid();

		// Store reminder in options table (transient-like storage).
		$all_reminders                 = get_option( 'wp_mcp_ai_health_reminders', array() );
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
				$all_reminders[ $reminder_id ]    = $reminder_data;
				update_option( 'wp_mcp_ai_health_reminders', $all_reminders );
			}
		}

		return array(
			'success'                    => true,
			'message'                    => __( 'Health reminder created successfully.', 'nvoos-content-graph-pro' ),
			'reminder_id'                => $reminder_id,
			'member_id'                  => $member_id,
			'member_name'                => $member->post_title,
			'reminder_type'              => $reminder_type,
			'title'                      => $title,
			'reminder_date'              => $reminder_date,
			'reminder_time'              => $reminder_time,
			'notification_scheduled_for' => gmdate( 'Y-m-d H:i:s', $notification_timestamp ),
			'is_recurring'               => $is_recurring,
			'recurrence_rule'            => $recurrence_rule,
			'notification_methods'       => $notification_methods,
			'priority'                   => $priority,
			'advance_notice_days'        => $advance_notice_days,
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
