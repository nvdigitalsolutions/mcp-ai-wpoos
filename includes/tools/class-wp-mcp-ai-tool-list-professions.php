<?php
/**
 * Tool for listing professions.
 *
 * Allows AI assistants to discover available professions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists available professions with their details.
 */
class WP_MCP_AI_Tool_List_Professions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
			return array(
				'success'     => false,
				'message'     => __( 'Profession system not available.', 'mcp-ai-wpoos' ),
				'professions' => array(),
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
				'category'    => $category ?: 'all',
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
			'category'    => $category ?: 'all',
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
