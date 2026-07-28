<?php
/**
 * Tool: Schedule Maintenance
 *
 * Allows AI assistants to schedule a maintenance window.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Schedule_Maintenance' ) ) {
	/**
	 * Schedule Maintenance tool.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Tool_Schedule_Maintenance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * {@inheritdoc}
		 */
		public function get_slug(): string {
			return 'schedule_maintenance';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_definition(): array {
			return array(
				'name'                => __( 'Schedule Maintenance', 'mcp-ai-wpoos' ),
				'description'         => __( 'Schedule a new maintenance window.', 'mcp-ai-wpoos' ),
				'required_capability' => 'manage_options',
				'parameters'          => $this->get_parameters_schema(),
			);
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_capability_flags(): array {
			return array(
				'state_changing' => true,
				'risk_level'     => 'low',
			);
		}

		/**
		 * Get parameters schema.
		 *
		 * @return array
		 */
		public function get_parameters_schema(): array {
			return array(
				'title'    => array(
					'type'        => 'string',
					'description' => __( 'Title of the maintenance window.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'message'  => array(
					'type'        => 'string',
					'description' => __( 'Description of the maintenance activities.', 'mcp-ai-wpoos' ),
				),
				'start'    => array(
					'type'        => 'string',
					'description' => __( 'Start time in ISO 8601 format (e.g. 2026-08-01T02:00:00Z).', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'end'      => array(
					'type'        => 'string',
					'description' => __( 'End time in ISO 8601 format.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'services' => array(
					'type'        => 'array',
					'description' => __( 'Affected service component slugs.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
			);
		}

		/**
		 * Execute the tool.
		 *
		 * @since 1.4.0
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments, array $context = array() ) {
			$title    = sanitize_text_field( $arguments['title'] );
			$start    = sanitize_text_field( $arguments['start'] );
			$end      = sanitize_text_field( $arguments['end'] );
			$message  = isset( $arguments['message'] ) ? sanitize_text_field( $arguments['message'] ) : '';
			$services = isset( $arguments['services'] ) && is_array( $arguments['services'] )
				? array_map( 'sanitize_text_field', $arguments['services'] )
				: array();

			if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
				return new WP_Error(
					'wp_mcp_ai_unavailable',
					__( 'The maintenance system is not available.', 'mcp-ai-wpoos' )
				);
			}

			$start_ts = strtotime( $start );
			$end_ts   = strtotime( $end );

			if ( false === $start_ts || false === $end_ts ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_date',
					__( 'Start and end must be valid ISO 8601 dates.', 'mcp-ai-wpoos' )
				);
			}

			if ( $end_ts <= $start_ts ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_range',
					__( 'End time must be after start time.', 'mcp-ai-wpoos' )
				);
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => WP_MCP_AI_Maintenance_CPT::POST_TYPE,
					'post_title'   => $title,
					'post_content' => $message,
					'post_status'  => 'publish',
					'meta_input'   => array(
						'_mcp_ai_maintenance_status'   => WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED,
						'_mcp_ai_maintenance_start'    => $start,
						'_mcp_ai_maintenance_end'      => $end,
						'_mcp_ai_maintenance_services' => $services,
						'_mcp_ai_maintenance_banner_enabled' => true,
						'_mcp_ai_maintenance_reminder_sent' => false,
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			/** Fires when a maintenance window is scheduled. @since 1.3.0 */
			do_action( 'wp_mcp_ai_maintenance_scheduled', $post_id, $arguments );

			return array(
				'window_id' => $post_id,
				'title'     => esc_html( $title ),
				'start'     => esc_html( $start ),
				'end'       => esc_html( $end ),
				'message'   => esc_html__( 'Maintenance window scheduled successfully.', 'mcp-ai-wpoos' ),
			);
		}
	}
}
