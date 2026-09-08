<?php
/**
 * Calendar appointment/slot tool (ecosystem port — Wave F2, calendar extras batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-set-availability-rules.php` for the standalone
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
 * WP_MCP_AI_Tool_Set_Availability_Rules tool.
 */
class WP_MCP_AI_Tool_Set_Availability_Rules implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'set_availability_rules';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Set Availability Rules', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Define availability rules and business hours for appointment scheduling.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'day_of_week'   => array(
					'type'        => 'string',
					'description' => __( 'Day of week', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ),
				),
				'enabled'       => array(
					'type'        => 'boolean',
					'description' => __( 'Enable appointments on this day', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'start_time'    => array(
					'type'        => 'string',
					'description' => __( 'Start time (HH:MM format)', 'nvoos-content-graph-pro' ),
				),
				'end_time'      => array(
					'type'        => 'string',
					'description' => __( 'End time (HH:MM format)', 'nvoos-content-graph-pro' ),
				),
				'slot_duration' => array(
					'type'        => 'integer',
					'description' => __( 'Appointment slot duration in minutes', 'nvoos-content-graph-pro' ),
					'minimum'     => 15,
					'default'     => 60,
				),
				'buffer_time'   => array(
					'type'        => 'integer',
					'description' => __( 'Buffer time between appointments in minutes', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
			'required'   => array( 'day_of_week' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'phase-2.6' );
	}

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
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		$day_of_week = ! empty( $arguments['day_of_week'] ) ? sanitize_text_field( $arguments['day_of_week'] ) : '';

		if ( empty( $day_of_week ) ) {
			return new WP_Error( 'missing_day', __( 'Day of week is required.', 'nvoos-content-graph-pro' ) );
		}

		$business_hours = get_option( 'wp_mcp_ai_business_hours', array() );

		$business_hours[ $day_of_week ] = array(
			'enabled'       => isset( $arguments['enabled'] ) ? (bool) $arguments['enabled'] : true,
			'start_time'    => ! empty( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '09:00',
			'end_time'      => ! empty( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '17:00',
			'slot_duration' => ! empty( $arguments['slot_duration'] ) ? absint( $arguments['slot_duration'] ) : 60,
			'buffer_time'   => ! empty( $arguments['buffer_time'] ) ? absint( $arguments['buffer_time'] ) : 0,
		);

		update_option( 'wp_mcp_ai_business_hours', $business_hours );

		return array(
			'success'     => true,
			'day_of_week' => $day_of_week,
			'rules'       => $business_hours[ $day_of_week ],
			'message'     => __( 'Availability rules updated successfully.', 'nvoos-content-graph-pro' ),
		);
	}
}
