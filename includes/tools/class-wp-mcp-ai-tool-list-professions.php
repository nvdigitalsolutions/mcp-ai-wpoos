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
		return __( 'List Professions', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all available professions that can be used when creating AI assistants. Professions include advisory services, creative roles, STEM fields, healthcare, emergency management, and more.', 'wp-mcp-ai' );
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
					'description' => __( 'Optional: Filter by category (advisory, creative, technical, healthcare, legal, financial, other)', 'wp-mcp-ai' ),
					'enum'        => array( 'advisory', 'creative', 'technical', 'healthcare', 'legal', 'financial', 'other' ),
				),
				'detailed' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, returns detailed information including expertise areas and default tools. Default: false.', 'wp-mcp-ai' ),
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
				'message'     => __( 'Profession system not available.', 'wp-mcp-ai' ),
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
