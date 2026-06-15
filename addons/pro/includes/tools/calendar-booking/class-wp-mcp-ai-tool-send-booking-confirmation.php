<?php
/**
 * Send Booking Confirmation Tool - Phase 2.6
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
 * WP_MCP_AI_Tool_Send_Booking_Confirmation tool.
 */
class WP_MCP_AI_Tool_Send_Booking_Confirmation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'send_booking_confirmation'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Send Booking Confirmation', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Send confirmation emails to clients for their appointments.', 'mcp-ai-wpoos-pro' ); }
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
				'include_ical'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include iCal attachment', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'custom_message' => array(
					'type'        => 'string',
					'description' => __( 'Additional custom message', 'mcp-ai-wpoos-pro' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
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
		$end_time     = get_post_meta( $appointment_id, '_end_time', true );
		/* translators: %d: appointment ID */
		$subject   = sprintf( __( 'Appointment Confirmation #%d', 'mcp-ai-wpoos-pro' ), $appointment_id );
		/* translators: %1$s: client name, %2$s: start time, %3$s: end time */
		$message   = sprintf( __( "Hello %1\$s,\n\nYour appointment is confirmed.\nTime: %2\$s to %3\$s\n\n", 'mcp-ai-wpoos-pro' ), $client_name, $start_time, $end_time );
		if ( ! empty( $arguments['custom_message'] ) ) {
			$message .= sanitize_textarea_field( $arguments['custom_message'] ) . "\n\n";
		}
		$message .= __( 'Thank you!', 'mcp-ai-wpoos-pro' );
		$sent     = wp_mail( $client_email, $subject, $message );
		if ( $sent ) {
			update_post_meta( $appointment_id, '_confirmation_sent_at', current_time( 'mysql' ) );
		}
		return array(
			'success'        => true,
			'appointment_id' => $appointment_id,
			'email_sent'     => $sent,
			'recipient'      => $client_email,
			'message'        => $sent ? __( 'Confirmation sent successfully.', 'mcp-ai-wpoos-pro' ) : __( 'Failed to send confirmation.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
