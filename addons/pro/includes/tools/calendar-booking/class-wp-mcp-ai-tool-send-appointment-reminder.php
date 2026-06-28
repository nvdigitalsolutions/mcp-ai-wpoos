<?php
/**
 * Send Appointment Reminder Tool - Phase 2.6
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WP_MCP_AI_Tool_Send_Appointment_Reminder tool.
 */
class WP_MCP_AI_Tool_Send_Appointment_Reminder implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}
	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); }
		/**
		 * Get the tool slug.
		 *
		 * @return string
		 */
	public function get_slug() {
		return 'send_appointment_reminder'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Send Appointment Reminder', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Send automated reminders for upcoming appointments.', 'mcp-ai-wpoos-pro' ); }
		/**
		 * Get the parameters schema.
		 *
		 * @return array
		 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_id' => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID', 'mcp-ai-wpoos-pro' ),
				),
				'reminder_type'  => array(
					'type'    => 'string',
					'enum'    => array( 'email', 'sms', 'both' ),
					'default' => 'email',
				),
				'hours_before'   => array(
					'type'        => 'integer',
					'description' => __( 'Hours before appointment', 'mcp-ai-wpoos-pro' ),
					'default'     => 24,
				),
			),
			'required'   => array( 'appointment_id' ),
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'email', 'phase-2.6' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() ); }
		$appointment_id = ! empty( $arguments['appointment_id'] ) ? absint( $arguments['appointment_id'] ) : 0;
		if ( ! $appointment_id ) {
			return new WP_Error( 'missing_id', __( 'Appointment ID is required.', 'mcp-ai-wpoos-pro' ) ); }
		$appointment = get_post( $appointment_id );
		if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
			return new WP_Error( 'invalid_appointment', __( 'Invalid appointment.', 'mcp-ai-wpoos-pro' ) );
		}
		$client_email = get_post_meta( $appointment_id, '_client_email', true );
		$client_name  = get_post_meta( $appointment_id, '_client_name', true );
		$start_time   = get_post_meta( $appointment_id, '_start_time', true );
		$hours_before = ! empty( $arguments['hours_before'] ) ? absint( $arguments['hours_before'] ) : 24;
		/* translators: %d: appointment ID */
		$subject = sprintf( __( 'Appointment Reminder #%d', 'mcp-ai-wpoos-pro' ), $appointment_id );
		/* translators: %1$s: client name, %2$s: appointment time */
		$message = sprintf( __( "Hello %1\$s,\n\nThis is a reminder about your upcoming appointment.\nTime: %2\$s\n\nSee you soon!", 'mcp-ai-wpoos-pro' ), $client_name, $start_time );
		$sent    = wp_mail( $client_email, $subject, $message );
		if ( $sent ) {
			$reminders   = get_post_meta( $appointment_id, '_reminders_sent', true ) ? get_post_meta( $appointment_id, '_reminders_sent', true ) : array();
			$reminders[] = array(
				'sent_at'      => current_time( 'mysql' ),
				'hours_before' => $hours_before,
				'type'         => 'email',
			);
			update_post_meta( $appointment_id, '_reminders_sent', $reminders );
		}
		return array(
			'success'        => true,
			'appointment_id' => $appointment_id,
			'reminder_sent'  => $sent,
			'recipient'      => $client_email,
			'message'        => $sent ? __( 'Reminder sent successfully.', 'mcp-ai-wpoos-pro' ) : __( 'Failed to send reminder.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
