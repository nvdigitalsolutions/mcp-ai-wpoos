<?php
/**
 * Tool: Create Incident
 *
 * Allows AI assistants to create operational incidents when they detect
 * service degradation or outages.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Incident' ) ) {
	/**
	 * Create Incident tool.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Tool_Create_Incident implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
		 */
		public function get_slug(): string {
			return 'create_incident';
		}

		/**
		 * {@inheritdoc}
		 *
		 * @since 1.4.0
		 */
		public function get_definition(): array {
			return array(
				'name'                => __( 'Create Incident', 'mcp-ai-wpoos' ),
				'description'         => __( 'Create a new operational incident for tracking service disruptions.', 'mcp-ai-wpoos' ),
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
				'title'    => array(
					'type'        => 'string',
					'description' => __( 'A brief title describing the incident.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'severity' => array(
					'type'        => 'string',
					'description' => __( 'Severity level: minor, major, or critical.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'minor', 'major', 'critical' ),
					'default'     => 'minor',
				),
				'message'  => array(
					'type'        => 'string',
					'description' => __( 'Initial message describing what was detected.', 'mcp-ai-wpoos' ),
				),
				'services' => array(
					'type'        => 'array',
					'description' => __( 'List of affected service component slugs.', 'mcp-ai-wpoos' ),
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
			$severity = isset( $arguments['severity'] ) ? sanitize_text_field( $arguments['severity'] ) : 'minor';
			$message  = isset( $arguments['message'] ) ? sanitize_text_field( $arguments['message'] ) : '';
			$services = isset( $arguments['services'] ) && is_array( $arguments['services'] )
				? array_map( 'sanitize_text_field', $arguments['services'] )
				: array();

			if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
				return new WP_Error(
					'wp_mcp_ai_unavailable',
					__( 'The incident management system is not available.', 'mcp-ai-wpoos' )
				);
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'publish',
					'meta_input'  => array(
						'_mcp_ai_incident_phase'    => WP_MCP_AI_Incident_CPT::PHASE_DETECTED,
						'_mcp_ai_incident_severity' => $severity,
						'_mcp_ai_incident_services' => $services,
						'_mcp_ai_incident_timeline' => array(
							array(
								'timestamp'   => time(),
								'phase'       => WP_MCP_AI_Incident_CPT::PHASE_DETECTED,
								'message'     => '' !== $message ? $message : __( 'Incident detected.', 'mcp-ai-wpoos' ),
								'operator_id' => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
							),
						),
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			/** Fires when a new incident is created. @since 1.4.0 */
			do_action( 'wp_mcp_ai_incident_created', $post_id, $arguments );

			return array(
				'incident_id' => $post_id,
				'title'       => esc_html( $title ),
				'severity'    => esc_html( $severity ),
				'message'     => esc_html__( 'Incident created successfully.', 'mcp-ai-wpoos' ),
			);
		}
	}
}
