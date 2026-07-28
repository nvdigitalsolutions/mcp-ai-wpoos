<?php
/**
 * Tool: Update Incident
 *
 * Allows AI assistants to update incident phase and add timeline entries.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Update_Incident' ) ) {
	/**
	 * Update Incident tool.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Tool_Update_Incident implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
		 */
		public function get_slug(): string {
			return 'update_incident';
		}

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
		 */
		public function get_definition(): array {
			return array(
				'name'                => __( 'Update Incident', 'mcp-ai-wpoos' ),
				'description'         => __( 'Update the phase of an existing incident and add a timeline entry.', 'mcp-ai-wpoos' ),
				'required_capability' => 'manage_options',
				'parameters'          => $this->get_parameters_schema(),
			);
		}

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
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
		 * @since 1.4.0
		 *
		 * @return array
		 */
		public function get_parameters_schema(): array {
			return array(
				'incident_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the incident to update.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'phase'       => array(
					'type'        => 'string',
					'description' => __( 'The new phase for the incident.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'investigating', 'identified', 'monitoring', 'resolved' ),
				),
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Update message describing the progress.', 'mcp-ai-wpoos' ),
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
			$phase       = isset( $arguments['phase'] ) ? sanitize_text_field( $arguments['phase'] ) : '';
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

			if ( '' !== $phase ) {
				$success = WP_MCP_AI_Incident_CPT::transition_phase( $incident_id, $phase, $message );

				if ( ! $success ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_transition',
						__( 'Invalid phase transition for this incident.', 'mcp-ai-wpoos' )
					);
				}
			} elseif ( '' !== $message ) {
				$current = get_post_meta( $incident_id, '_mcp_ai_incident_phase', true );
				WP_MCP_AI_Incident_CPT::append_timeline_entry( $incident_id, $current, $message );
			}

			$post = get_post( $incident_id );

			return array(
				'incident_id' => $incident_id,
				'title'       => esc_html( $post->post_title ),
				'phase'       => esc_html( get_post_meta( $incident_id, '_mcp_ai_incident_phase', true ) ),
				'message'     => esc_html__( 'Incident updated successfully.', 'mcp-ai-wpoos' ),
			);
		}
	}
}
