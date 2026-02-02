<?php
/**
 * Tool for configuring email notification rules.
 *
 * Allows AI assistants to configure automated email notifications
 * for registration events and milestones.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configures email notification rules.
 */
class WP_MCP_AI_Tool_Configure_Email_Notifications implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'configure_email_notifications';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Configure Email Notifications', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Configures automated email notification rules for registration events, expiry alerts, and status changes with recipient management.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'notification_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of notification to configure (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'expiry_alert', 'status_change', 'submission_confirmation', 'approval_notice' ),
				),
				'enabled'           => array(
					'type'        => 'boolean',
					'description' => __( 'Enable or disable notification (required)', 'mcp-ai-wpoos-pro' ),
				),
				'recipients'        => array(
					'type'        => 'array',
					'description' => __( 'Email addresses to notify (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'email',
					),
				),
				'conditions'        => array(
					'type'        => 'object',
					'description' => __( 'Notification trigger conditions (optional)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'days_before_expiry' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'countries'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'status_from'        => array( 'type' => 'string' ),
						'status_to'          => array( 'type' => 'string' ),
					),
				),
			),
			'required'             => array( 'notification_type', 'enabled' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Saves configuration.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to configure email notifications.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['notification_type'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Notification type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! isset( $arguments['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Enabled status is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$notification_type = sanitize_text_field( $arguments['notification_type'] );
		$enabled           = (bool) $arguments['enabled'];
		$recipients        = ! empty( $arguments['recipients'] ) && is_array( $arguments['recipients'] ) ? array_map( 'sanitize_email', $arguments['recipients'] ) : array();
		$conditions        = ! empty( $arguments['conditions'] ) && is_array( $arguments['conditions'] ) ? $arguments['conditions'] : array();

		// Get current notification settings.
		$notification_settings = get_option( 'wp_mcp_ai_notification_settings', array() );

		// Update configuration for this notification type.
		$notification_settings[ $notification_type ] = array(
			'enabled'    => $enabled,
			'recipients' => $recipients,
			'conditions' => $conditions,
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => $current_user_id,
		);

		// Save settings.
		update_option( 'wp_mcp_ai_notification_settings', $notification_settings );

		// Log configuration change.
		$log_entry = array(
			'timestamp'         => current_time( 'mysql' ),
			'user_id'           => $current_user_id,
			'notification_type' => $notification_type,
			'action'            => $enabled ? 'enabled' : 'disabled',
		);

		$notification_log   = get_option( 'wp_mcp_ai_notification_log', array() );
		$notification_log[] = $log_entry;
		update_option( 'wp_mcp_ai_notification_log', array_slice( $notification_log, -100 ) ); // Keep last 100 entries.

		return array(
			'success'           => true,
			'notification_type' => $notification_type,
			'enabled'           => $enabled,
			'recipients_count'  => count( $recipients ),
			'has_conditions'    => ! empty( $conditions ),
			'configured_at'     => current_time( 'mysql' ),
			'message'           => sprintf(
				/* translators: 1: notification type, 2: enabled/disabled */
				__( 'Email notification "%1$s" has been %2$s.', 'mcp-ai-wpoos-pro' ),
				$notification_type,
				$enabled ? __( 'enabled', 'mcp-ai-wpoos-pro' ) : __( 'disabled', 'mcp-ai-wpoos-pro' )
			),
		);
	}
}
