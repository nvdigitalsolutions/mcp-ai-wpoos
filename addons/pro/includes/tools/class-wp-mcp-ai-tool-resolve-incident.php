<?php
/**
 * Tool: Resolve Incident
 *
 * Allows AI assistants to resolve an operational incident.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Resolve_Incident' ) ) {
	/**
	 * Resolve Incident tool.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Tool_Resolve_Incident implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * {@inheritdoc}
		 */
		public function get_slug(): string {
			return 'resolve_incident';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_definition(): array {
			return array(
				'name'                => __( 'Resolve Incident', 'mcp-ai-wpoos' ),
				'description'         => __( 'Mark an operational incident as resolved.', 'mcp-ai-wpoos' ),
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
				'incident_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the incident to resolve.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Resolution message.', 'mcp-ai-wpoos' ),
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
			$incident_id = absint( $arguments['incident_id'] );
			$message     = isset( $arguments['message'] ) ? sanitize_text_field( $arguments['message'] ) : '';

			if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
				return new WP_Error(
					'wp_mcp_ai_unavailable',
					__( 'The incident management system is not available.', 'mcp-ai-wpoos' )
				);
			}

			$post = get_post( $incident_id );
			if ( ! $post || WP_MCP_AI_Incident_CPT::POST_TYPE !== $post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_not_found',
					__( 'Incident not found.', 'mcp-ai-wpoos' )
				);
			}

			if ( '' === $message ) {
				$message = __( 'This incident has been resolved.', 'mcp-ai-wpoos' );
			}

			$success = WP_MCP_AI_Incident_CPT::transition_phase(
				$incident_id,
				WP_MCP_AI_Incident_CPT::PHASE_RESOLVED,
				$message
			);

			if ( ! $success ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_transition',
					__( 'Cannot resolve this incident in its current state.', 'mcp-ai-wpoos' )
				);
			}

			return array(
				'incident_id' => $incident_id,
				'title'       => esc_html( $post->post_title ),
				'phase'       => esc_html( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED ),
				'message'     => esc_html__( 'Incident resolved successfully.', 'mcp-ai-wpoos' ),
			);
		}
	}
}
