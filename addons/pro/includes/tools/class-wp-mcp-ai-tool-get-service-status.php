<?php
/**
 * Tool: Get Service Status
 *
 * Allows AI assistants to query the current status of one or all
 * service components via the status registry.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Get_Service_Status' ) ) {
	/**
	 * Get Service Status tool.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Tool_Get_Service_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
		 */
		public function get_slug(): string {
			return 'get_service_status';
		}

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
		 */
		public function get_definition(): array {
			return array(
				'name'        => __( 'Get Service Status', 'mcp-ai-wpoos' ),
				'description' => __( 'Query the current status of one or all monitored service components.', 'mcp-ai-wpoos' ),
				'parameters'  => $this->get_parameters_schema(),
			);
		}

		/**
		 * Get the parameters schema.
		 *
		 * @since 1.4.0
		 *
		 * @return array
		 */
		public function get_parameters_schema(): array {
			return array(
				'component_slug' => array(
					'type'        => 'string',
					'description' => __( 'Optional. The slug of a specific component to query. Omit to get all components.', 'mcp-ai-wpoos' ),
					'required'    => false,
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
			$slug = isset( $arguments['component_slug'] ) ? sanitize_text_field( $arguments['component_slug'] ) : '';

			if ( ! class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
				return new WP_Error(
					'wp_mcp_ai_status_unavailable',
					__( 'The service status system is not available.', 'mcp-ai-wpoos' )
				);
			}

			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();

			if ( '' !== $slug ) {
				$sources = $registry->get_sources();
				if ( ! isset( $sources[ $slug ] ) ) {
					return new WP_Error(
						'wp_mcp_ai_unknown_component',
						sprintf(
							/* translators: %s: component slug */
							__( 'Unknown component: %s', 'mcp-ai-wpoos' ),
							$slug
						)
					);
				}

				$status = $registry->get_status();
				$data   = $status[ $slug ] ?? array(
					'status'  => 'unknown',
					'message' => __( 'Status not available.', 'mcp-ai-wpoos' ),
				);

				return array(
					'slug'    => esc_html( $slug ),
					'name'    => esc_html( $sources[ $slug ]->get_name() ),
					'status'  => esc_html( $data['status'] ?? 'unknown' ),
					'message' => esc_html( $data['message'] ?? '' ),
				);
			}

			// Return all public components.
			$components = $registry->get_public_status();
			$overall    = $registry->compute_overall_status( $components );

			$result = array(
				'overall_status' => esc_html( $overall ),
				'components'     => array(),
			);

			foreach ( $components as $slug => $data ) {
				$result['components'][] = array(
					'slug'    => esc_html( $slug ),
					'name'    => esc_html( $data['name'] ?? $slug ),
					'status'  => esc_html( $data['status'] ?? 'unknown' ),
					'message' => esc_html( $data['message'] ?? '' ),
				);
			}

			return $result;
		}
	}
}
