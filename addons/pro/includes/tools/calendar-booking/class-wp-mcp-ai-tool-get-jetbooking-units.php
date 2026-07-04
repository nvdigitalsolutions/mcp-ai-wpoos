<?php
/**
 * Get JetBooking Units Tool
 *
 * Lists JetBooking units for a booking instance with optional
 * availability data for a date range.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since     1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries JetBooking units via the adapter.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Get_JetBooking_Units implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_jetbooking_units';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get JetBooking Units', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List JetBooking units for a booking instance. Includes optional availability for a date range.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'instance_id' => array(
					'type'        => 'integer',
					'description' => __( 'Booking instance ID (required).', 'mcp-ai-wpoos-pro' ),
				),
				'check_in'    => array(
					'type'        => 'string',
					'description' => __( 'Check-in date for availability check (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'check_out'   => array(
					'type'        => 'string',
					'description' => __( 'Check-out date for availability check (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'instance_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'phase-1.5' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		if ( ! class_exists( 'WP_MCP_AI_Booking_Adapter_Factory' ) || ! WP_MCP_AI_Booking_Adapter_Factory::has_jetbooking() ) {
			return new WP_Error(
				'jetbooking_unavailable',
				__( 'JetBooking adapter is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$adapter     = WP_MCP_AI_Booking_Adapter_Factory::get_jetbooking();
		$instance_id = absint( $arguments['instance_id'] );

		$result = $adapter->get_units( $instance_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If date range provided, enrich with availability.
		if ( ! empty( $arguments['check_in'] ) ) {
			$check_in  = sanitize_text_field( $arguments['check_in'] );
			$check_out = ! empty( $arguments['check_out'] ) ? sanitize_text_field( $arguments['check_out'] ) : $check_in;

			$availability = $adapter->get_unit_availability( $instance_id, $check_in, $check_out );

			if ( ! is_wp_error( $availability ) ) {
				$available_ids = wp_list_pluck( $availability['available_units'], 'unit_id' );
				foreach ( $result['units'] as &$unit ) {
					$unit['is_available'] = in_array( $unit['unit_id'], $available_ids, true );
				}
				$result['availability_summary'] = array(
					'available_count' => $availability['available_count'],
					'total_units'     => $availability['total_units'],
				);
			}
		}

		return $result;
	}
}
