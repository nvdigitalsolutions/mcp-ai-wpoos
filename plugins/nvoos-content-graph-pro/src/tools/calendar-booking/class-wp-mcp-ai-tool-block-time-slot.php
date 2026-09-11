<?php
/**
 * Calendar appointment/slot tool (ecosystem port — Wave F2, calendar extras batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-block-time-slot.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Tree-only: the base tree ships this file but the monolith's inline
 * tool map never registers it — the standalone filter/ecosystem
 * registrations below are the standalone registrations (CRM CC-extras
 * precedent).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
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
 * WP_MCP_AI_Tool_Block_Time_Slot tool.
 */
class WP_MCP_AI_Tool_Block_Time_Slot implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return __( 'Calendar Booking toolkit is not enabled.', 'nvoos-content-graph-pro' ); }
		/**
		 * Get the tool slug.
		 *
		 * @return string
		 */
	public function get_slug() {
		return 'block_time_slot'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Block Time Slot', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Block specific time slots to prevent appointments.', 'nvoos-content-graph-pro' ); }
		/**
		 * Get the parameters schema.
		 *
		 * @return array
		 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'start_time' => array(
					'type'        => 'string',
					'description' => __( 'Start time (Y-m-d H:i:s)', 'nvoos-content-graph-pro' ),
				),
				'end_time'   => array(
					'type'        => 'string',
					'description' => __( 'End time (Y-m-d H:i:s)', 'nvoos-content-graph-pro' ),
				),
				'reason'     => array(
					'type'        => 'string',
					'description' => __( 'Reason for blocking', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'start_time', 'end_time' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() ); }
		$start_time = ! empty( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '';
		$end_time   = ! empty( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '';
		if ( empty( $start_time ) || empty( $end_time ) ) {
			return new WP_Error( 'missing_time', __( 'Start and end times are required.', 'nvoos-content-graph-pro' ) );
		}
		$blocked_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_blocked_time',
				/* translators: %1$s: start time, %2$s: end time */
				'post_title'  => sprintf( __( 'Blocked: %1$s to %2$s', 'nvoos-content-graph-pro' ), $start_time, $end_time ),
				'post_status' => 'publish',
				'meta_input'  => array(
					'_start_time' => $start_time,
					'_end_time'   => $end_time,
					'_reason'     => ! empty( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '',
					'_blocked_by' => $current_user_id,
				),
			),
			true
		);
		if ( is_wp_error( $blocked_id ) ) {
			return $blocked_id; }
		return array(
			'success'    => true,
			'blocked_id' => $blocked_id,
			'start_time' => $start_time,
			'end_time'   => $end_time,
			'message'    => __( 'Time slot blocked successfully.', 'nvoos-content-graph-pro' ),
		);
	}
}
