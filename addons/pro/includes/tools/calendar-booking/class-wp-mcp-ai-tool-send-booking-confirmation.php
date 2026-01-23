<?php
/**
 * Send Booking Confirmation Tool - Phase 2.6
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WP_MCP_AI_Tool_Send_Booking_Confirmation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'send_booking_confirmation'; }
	public function get_name() {
		return __( 'Send Booking Confirmation', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Send confirmation emails to clients for their appointments.', 'mcp-ai-wpoos-pro' ); }
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
	public function get_capability_flags() {
		return array( 'pro', 'email', 'phase-2.6' ); }
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
		$subject      = sprintf( __( 'Appointment Confirmation #%d', 'mcp-ai-wpoos-pro' ), $appointment_id );
		$message      = sprintf( __( "Hello %1\$s,\n\nYour appointment is confirmed.\nTime: %2\$s to %3\$s\n\n", 'mcp-ai-wpoos-pro' ), $client_name, $start_time, $end_time );
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
