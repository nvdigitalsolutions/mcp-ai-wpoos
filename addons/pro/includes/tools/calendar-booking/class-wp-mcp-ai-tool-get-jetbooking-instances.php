<?php
/**
 * Get JetBooking Instances Tool
 *
 * Lists JetBooking booking instances (apartments, rooms, vehicles).
 * Requires JetBooking adapter to be available.
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
 * Queries JetBooking instances via the adapter.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Get_JetBooking_Instances implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'get_jetbooking_instances';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get JetBooking Instances', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List JetBooking booking instances (apartments, rooms, vehicles) with unit counts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search' => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter instances.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum instances (default: 50).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
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

		$adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetbooking();
		$filters = array();

		if ( ! empty( $arguments['search'] ) ) {
			$filters['search'] = sanitize_text_field( $arguments['search'] );
		}

		return $adapter->get_booking_instances( $filters );
	}
}
