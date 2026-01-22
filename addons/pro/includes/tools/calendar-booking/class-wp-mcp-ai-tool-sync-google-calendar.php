<?php
/**
 * Sync Google Calendar Tool - Phase 2.6
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WP_MCP_AI_Tool_Sync_Google_Calendar implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'sync_google_calendar'; }
	public function get_name() {
		return __( 'Sync Google Calendar', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Sync appointments with Google Calendar.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_id' => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID to sync', 'mcp-ai-wpoos-pro' ),
				),
				'sync_direction' => array(
					'type'    => 'string',
					'enum'    => array( 'to_google', 'from_google', 'bidirectional' ),
					'default' => 'to_google',
				),
				'calendar_id'    => array(
					'type'        => 'string',
					'description' => __( 'Google Calendar ID', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'appointment_id' ),
		);
	}
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'phase-2.6' ); }
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
		$sync_result = apply_filters( 'wp_mcp_ai_google_calendar_sync', false, $appointment_id, $arguments );
		if ( ! $sync_result ) {
			update_post_meta( $appointment_id, '_google_calendar_synced', 'pending' );
			$sync_result = array(
				'status'   => 'queued',
				'event_id' => 'pending-' . time(),
			);
		}
		return array(
			'success'         => true,
			'appointment_id'  => $appointment_id,
			'sync_status'     => $sync_result['status'],
			'google_event_id' => $sync_result['event_id'],
			'message'         => __( 'Google Calendar sync initiated.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
