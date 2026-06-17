<?php
/**
 * Generate Booking Link Tool - Phase 2.6
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
 * WP_MCP_AI_Tool_Generate_Booking_Link tool.
 */
class WP_MCP_AI_Tool_Generate_Booking_Link implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'generate_booking_link'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Generate Booking Link', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate public booking links for appointment scheduling.', 'mcp-ai-wpoos-pro' ); }
		/**
		 * Get the parameters schema.
		 *
		 * @return array
		 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of appointment', 'mcp-ai-wpoos-pro' ),
				),
				'duration_minutes' => array(
					'type'        => 'integer',
					'description' => __( 'Default duration', 'mcp-ai-wpoos-pro' ),
					'default'     => 60,
				),
				'expiry_days'      => array(
					'type'        => 'integer',
					'description' => __( 'Link expiry in days (0 for no expiry)', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'max_bookings'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum bookings allowed', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'phase-2.6' ); }
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
		$appointment_type = ! empty( $arguments['appointment_type'] ) ? sanitize_text_field( $arguments['appointment_type'] ) : 'general';
		$duration         = ! empty( $arguments['duration_minutes'] ) ? absint( $arguments['duration_minutes'] ) : 60;
		$expiry_days      = ! empty( $arguments['expiry_days'] ) ? absint( $arguments['expiry_days'] ) : 0;
		$max_bookings     = ! empty( $arguments['max_bookings'] ) ? absint( $arguments['max_bookings'] ) : 0;
		$link_token       = wp_generate_password( 32, false );
		$link_id          = wp_insert_post(
			array(
				'post_type'   => 'mcp_booking_link',
				/* translators: %s: appointment type */
				'post_title'  => sprintf( __( 'Booking Link: %s', 'mcp-ai-wpoos-pro' ), $appointment_type ),
				'post_status' => 'publish',
				'meta_input'  => array(
					'_token'            => $link_token,
					'_appointment_type' => $appointment_type,
					'_duration_minutes' => $duration,
					'_expiry_days'      => $expiry_days,
					'_max_bookings'     => $max_bookings,
					'_bookings_count'   => 0,
					'_created_by'       => $current_user_id,
					'_created_at'       => current_time( 'mysql' ),
				),
			),
			true
		);
		if ( is_wp_error( $link_id ) ) {
			return $link_id; }
		$booking_url = home_url( '/book/' . $link_token );
		return array(
			'success'          => true,
			'link_id'          => $link_id,
			'booking_url'      => $booking_url,
			'token'            => $link_token,
			'appointment_type' => $appointment_type,
			'duration_minutes' => $duration,
			'expiry_days'      => $expiry_days,
			'max_bookings'     => $max_bookings,
			'message'          => __( 'Booking link generated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
