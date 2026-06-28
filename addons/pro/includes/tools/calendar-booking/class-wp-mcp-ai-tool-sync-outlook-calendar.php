<?php
/**
 * Sync Outlook Calendar Tool - Phase 2.6
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
 * WP_MCP_AI_Tool_Sync_Outlook_Calendar tool.
 */
class WP_MCP_AI_Tool_Sync_Outlook_Calendar implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'sync_outlook_calendar'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Sync Outlook Calendar', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Sync appointments with Outlook Calendar.', 'mcp-ai-wpoos-pro' ); }
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
					'description' => __( 'Appointment ID to sync', 'mcp-ai-wpoos-pro' ),
				),
				'sync_direction' => array(
					'type'    => 'string',
					'enum'    => array( 'to_outlook', 'from_outlook', 'bidirectional' ),
					'default' => 'to_outlook',
				),
				'calendar_id'    => array(
					'type'        => 'string',
					'description' => __( 'Outlook Calendar ID', 'mcp-ai-wpoos-pro' ),
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
		return array( 'pro', 'external-api', 'phase-2.6' ); }
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
		$sync_result = apply_filters( 'wp_mcp_ai_outlook_calendar_sync', false, $appointment_id, $arguments );
		if ( ! $sync_result ) {
			update_post_meta( $appointment_id, '_outlook_calendar_synced', 'pending' );
			$sync_result = array(
				'status'   => 'queued',
				'event_id' => 'pending-' . time(),
			);
		}
		return array(
			'success'          => true,
			'appointment_id'   => $appointment_id,
			'sync_status'      => $sync_result['status'],
			'outlook_event_id' => $sync_result['event_id'],
			'message'          => __( 'Outlook Calendar sync initiated.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
