<?php
/**
 * Status REST Controller for NV oOS.
 *
 * Provides public-facing status endpoints for the service status page.
 * Public endpoints return allowlisted fields only; detailed diagnostics
 * require authentication with manage_options capability.
 *
 * Routes:
 * - GET /mcp-ai/v1/status             — Full public status overview
 * - GET /mcp-ai/v1/status/components  — Service components only
 * - GET /mcp-ai/v1/status/history     — Uptime history (30/90 day)
 *
 * @package   WP_MCP_AI
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status REST controller.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Status_REST_Controller {

	/**
	 * Register the status REST routes.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		// Full public status overview.
		register_rest_route(
			'mcp-ai/v1',
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_full_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'include_private' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		// Service components only.
		register_rest_route(
			'mcp-ai/v1',
			'/status/components',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_components' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'include_private' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		// Uptime history.
		register_rest_route(
			'mcp-ai/v1',
			'/status/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_history' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'default'           => 30,
						'minimum'           => 1,
						'maximum'           => 90,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Get full public status overview.
	 *
	 * Returns components with public status, an overall computed status,
	 * and the last health check timestamp.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_full_status( $request ) {
		$registry   = WP_MCP_AI_Service_Status_Registry::get_instance();
		$is_private = $request->get_param( 'include_private' ) && current_user_can( 'manage_options' );

		$components = $is_private
			? $registry->get_status()
			: $registry->get_public_status();

		$last_check = (int) get_option( WP_MCP_AI_Service_Status_Registry::LAST_CHECK_KEY, 0 );

		$response = array(
			'overall_status' => $registry->compute_overall_status( $components ),
			'components'     => $components,
			'incidents'      => self::get_incidents_data(),
			'maintenance'    => self::get_maintenance_data(),
			'last_checked'   => $last_check > 0 ? gmdate( 'c', $last_check ) : null,
		);

		// Add HTTP cache headers for public responses (1-minute cache).
		if ( ! $is_private ) {
			header( 'Cache-Control: public, max-age=60, s-maxage=60' );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get service components status.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_components( $request ) {
		$registry   = WP_MCP_AI_Service_Status_Registry::get_instance();
		$is_private = $request->get_param( 'include_private' ) && current_user_can( 'manage_options' );

		$components = $is_private
			? $registry->get_status()
			: $registry->get_public_status();

		$response = array(
			'components' => $components,
		);

		if ( ! $is_private ) {
			header( 'Cache-Control: public, max-age=60, s-maxage=60' );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get uptime history.
	 *
	 * Returns daily uptime percentages for the requested number of days.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_history( $request ) {
		$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
		$days     = $request->get_param( 'days' );

		$history = $registry->get_uptime_history( $days );
		$overall = 100.0;

		if ( ! empty( $history ) ) {
			$sum     = array_sum( $history );
			$overall = round( $sum / count( $history ), 2 );
		}

		$response = array(
			'days'           => $days,
			'overall_uptime' => $overall,
			'history'        => $history,
		);

		header( 'Cache-Control: public, max-age=300, s-maxage=300' );

		return rest_ensure_response( $response );
	}

	/**
	 * Get maintenance data for the status response.
	 *
	 * Returns upcoming and active maintenance windows if the Pro addon
	 * maintenance CPT is available. Returns an empty array otherwise.
	 *
	 * @since 1.3.0
	 *
	 * @return array
	 */
	private static function get_maintenance_data() {
		if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
			return array();
		}

		$upcoming = WP_MCP_AI_Maintenance_CPT::get_upcoming_windows( 3 );
		$active   = WP_MCP_AI_Maintenance_CPT::get_active_window();

		$windows = array();

		if ( $active ) {
			$services  = get_post_meta( $active->ID, '_mcp_ai_maintenance_services', true );
			$windows[] = array(
				'id'       => $active->ID,
				'title'    => $active->post_title,
				'content'  => wp_kses_post( $active->post_content ),
				'status'   => get_post_meta( $active->ID, '_mcp_ai_maintenance_status', true ),
				'start'    => get_post_meta( $active->ID, '_mcp_ai_maintenance_start', true ),
				'end'      => get_post_meta( $active->ID, '_mcp_ai_maintenance_end', true ),
				'services' => is_array( $services ) ? $services : array(),
			);
		}

		foreach ( $upcoming as $window ) {
			$services_val = get_post_meta( $window->ID, '_mcp_ai_maintenance_services', true );
			$windows[]    = array(
				'id'       => $window->ID,
				'title'    => $window->post_title,
				'content'  => wp_kses_post( $window->post_content ),
				'status'   => get_post_meta( $window->ID, '_mcp_ai_maintenance_status', true ),
				'start'    => get_post_meta( $window->ID, '_mcp_ai_maintenance_start', true ),
				'end'      => get_post_meta( $window->ID, '_mcp_ai_maintenance_end', true ),
				'services' => is_array( $services_val ) ? $services_val : array(),
			);
		}

		return $windows;
	}

		/**
		 * Get incidents data for the status response.
		 *
		 * Returns active (unresolved) incidents if the Pro addon incident
		 * CPT is available. Returns an empty array otherwise.
		 *
		 * @since 1.4.0
		 *
		 * @return array
		 */
	private static function get_incidents_data() {
		if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
			return array();
		}

		$incidents = WP_MCP_AI_Incident_CPT::get_active_incidents( 5 );
		$data      = array();

		foreach ( $incidents as $incident ) {
			$services_raw = get_post_meta( $incident->ID, '_mcp_ai_incident_services', true );

			$tl = get_post_meta( $incident->ID, '_mcp_ai_incident_timeline', true );

			$data[] = array(
				'id'       => $incident->ID,
				'title'    => $incident->post_title,
				'phase'    => get_post_meta( $incident->ID, '_mcp_ai_incident_phase', true ),
				'severity' => get_post_meta( $incident->ID, '_mcp_ai_incident_severity', true ),
				'services' => is_array( $services_raw ) ? $services_raw : array(),
				'timeline' => is_array( $tl ) ? $tl : array(),
				'created'  => $incident->post_date_gmt,
			);
		}

		return $data;
	}
}
