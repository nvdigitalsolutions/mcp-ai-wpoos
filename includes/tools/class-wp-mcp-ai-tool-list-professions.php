<?php
/**
 * Tool for listing professions.
 *
 * Allows AI assistants to discover available professions.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists available professions with their details.
 */
class WP_MCP_AI_Tool_List_Professions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_professions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Professions', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all available professions that can be used when creating AI assistants. Professions include advisory services, creative roles, STEM fields, healthcare, emergency management, and more.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Enumerating professions available when creating or configuring an assistant.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Reading one profession\'s full definition; use get_profession.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_profession', 'create_assistant' ),
			'notes'           => __( 'detailed=true returns expertise areas and default tools per profession.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'category' => array(
					'type'        => 'string',
					'description' => __( 'Optional: Filter by category (advisory, creative, technical, healthcare, legal, financial, other)', 'mcp-ai-wpoos' ),
					'enum'        => array( 'advisory', 'creative', 'technical', 'healthcare', 'legal', 'financial', 'other' ),
				),
				'detailed' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, returns detailed information including expertise areas and default tools. Default: false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
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
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$category = isset( $arguments['category'] ) ? sanitize_key( $arguments['category'] ) : '';
		$detailed = isset( $arguments['detailed'] ) && $arguments['detailed'];

		// Get profession service.
		if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Profession system not available.', 'mcp-ai-wpoos' )
			);
		}

		$profession_service = wp_mcp_ai_get_profession_service();

		// Get professions.
		if ( $category ) {
			$professions_data = $profession_service->get_professions_by_category( $category );
		} else {
			$professions_data = $profession_service->get_all_professions();
		}

		// If not detailed, just return names.
		if ( ! $detailed ) {
			return array(
				'success'     => true,
				'count'       => count( $professions_data ),
				'category'    => $category ? $category : 'all',
				'professions' => $professions_data,
			);
		}

		// Get detailed information.
		$detailed_professions = array();
		foreach ( array_keys( $professions_data ) as $profession_slug ) {
			$profession_details = $profession_service->get_profession( $profession_slug );
			if ( $profession_details ) {
				$detailed_professions[ $profession_slug ] = $profession_details;
			}
		}

		return array(
			'success'     => true,
			'count'       => count( $detailed_professions ),
			'category'    => $category ? $category : 'all',
			'professions' => $detailed_professions,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'systems_administrator', 'ai_researcher' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read',         // Reads profession data.
			'local-only',   // No external API calls.
			'safe',         // Read-only operation.
		);
	}
}
