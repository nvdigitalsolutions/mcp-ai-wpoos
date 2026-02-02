<?php
/**
 * Tool for sending status change notification emails.
 *
 * Allows AI assistants to send notifications when registration
 * status changes occur.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends status change notifications.
 */
class WP_MCP_AI_Tool_Send_Status_Change_Notification implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_status_change_notification';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Status Change Notification', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends email notifications when registration status transitions occur with detailed change information and next steps.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'old_status'      => array(
					'type'        => 'string',
					'description' => __( 'Previous status (required)', 'mcp-ai-wpoos-pro' ),
				),
				'new_status'      => array(
					'type'        => 'string',
					'description' => __( 'New status (required)', 'mcp-ai-wpoos-pro' ),
				),
				'recipients'      => array(
					'type'        => 'array',
					'description' => __( 'Email addresses to notify (optional, uses configured recipients if not provided)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'email',
					),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional notes to include in notification (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'registration_id', 'old_status', 'new_status' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'database-write',       // Logs sent emails.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send status change notifications.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['old_status'] ) || empty( $arguments['new_status'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Both old status and new status are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );
		$old_status      = sanitize_text_field( $arguments['old_status'] );
		$new_status      = sanitize_text_field( $arguments['new_status'] );
		$recipients      = ! empty( $arguments['recipients'] ) && is_array( $arguments['recipients'] ) ? array_map( 'sanitize_email', $arguments['recipients'] ) : array();
		$notes           = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Get default recipients if not provided.
		if ( empty( $recipients ) ) {
			$notification_settings = get_option( 'wp_mcp_ai_notification_settings', array() );
			if ( ! empty( $notification_settings['status_change']['recipients'] ) ) {
				$recipients = $notification_settings['status_change']['recipients'];
			} else {
				$recipients = array( get_option( 'admin_email' ) );
			}
		}

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get registration details.
		$country    = get_post_meta( $registration_id, 'country', true );
		$authority  = get_post_meta( $registration_id, 'authority', true );
		$cos_number = get_post_meta( $registration_id, 'cos_number', true );

		// Compose email.
		$subject = sprintf(
			/* translators: %s: registration title */
			__( 'Status Change: %s', 'mcp-ai-wpoos-pro' ),
			$registration->post_title
		);

		$message = sprintf(
			/* translators: %s: registration title */
			__( 'The status of registration "%s" has changed:', 'mcp-ai-wpoos-pro' ),
			$registration->post_title
		) . "\n\n";
		$message .= sprintf( __( 'Previous Status: %s', 'mcp-ai-wpoos-pro' ), $old_status ) . "\n";
		$message .= sprintf( __( 'New Status: %s', 'mcp-ai-wpoos-pro' ), $new_status ) . "\n\n";
		$message .= __( 'Registration Details:', 'mcp-ai-wpoos-pro' ) . "\n";
		$message .= sprintf( __( 'Country: %s', 'mcp-ai-wpoos-pro' ), $country ) . "\n";
		$message .= sprintf( __( 'Authority: %s', 'mcp-ai-wpoos-pro' ), $authority ) . "\n";
		if ( $cos_number ) {
			$message .= sprintf( __( 'COS Number: %s', 'mcp-ai-wpoos-pro' ), $cos_number ) . "\n";
		}

		if ( $notes ) {
			$message .= "\n" . __( 'Additional Notes:', 'mcp-ai-wpoos-pro' ) . "\n";
			$message .= $notes . "\n";
		}

		$message .= "\n" . sprintf(
			/* translators: %s: registration URL */
			__( 'View Registration: %s', 'mcp-ai-wpoos-pro' ),
			get_edit_post_link( $registration_id, 'raw' )
		);

		// Send emails.
		$emails_sent = 0;
		foreach ( $recipients as $recipient ) {
			if ( wp_mail( $recipient, $subject, $message ) ) {
				++$emails_sent;
			}
		}

		// Log notification.
		$notification_log = array(
			'timestamp'       => current_time( 'mysql' ),
			'user_id'         => $current_user_id,
			'registration_id' => $registration_id,
			'type'            => 'status_change',
			'old_status'      => $old_status,
			'new_status'      => $new_status,
			'recipients'      => $recipients,
		);

		$notification_history   = get_option( 'wp_mcp_ai_notification_history', array() );
		$notification_history[] = $notification_log;
		update_option( 'wp_mcp_ai_notification_history', array_slice( $notification_history, -100 ) );

		return array(
			'success'         => true,
			'registration_id' => $registration_id,
			'old_status'      => $old_status,
			'new_status'      => $new_status,
			'recipients'      => $recipients,
			'emails_sent'     => $emails_sent,
			'sent_at'         => current_time( 'mysql' ),
		);
	}
}
