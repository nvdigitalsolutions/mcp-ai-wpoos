<?php
/**
 * Tool for getting profession details.
 *
 * Retrieves detailed information about a specific profession.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information about a profession.
 */
class WP_MCP_AI_Tool_Get_Profession implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_profession';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Profession Details', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific profession including expertise areas, role description, warnings, knowledge base content, and default tools.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'profession_slug' => array(
					'type'        => 'string',
					'description' => __( 'The slug of the profession to retrieve (e.g., "graphic_designer", "data_scientist", "marine_biologist")', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
			),
			'required'             => array( 'profession_slug' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$profession_slug = isset( $arguments['profession_slug'] ) ? sanitize_key( $arguments['profession_slug'] ) : '';

		if ( empty( $profession_slug ) ) {
			return new WP_Error( 'missing_profession', __( 'Profession slug is required.', 'wp-mcp-ai' ) );
		}

		// Get profession service.
		if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			return new WP_Error( 'system_unavailable', __( 'Profession system not available.', 'wp-mcp-ai' ) );
		}

		$profession_service = wp_mcp_ai_get_profession_service();
		$profession         = $profession_service->get_profession( $profession_slug );

		if ( ! $profession ) {
			return new WP_Error(
				'profession_not_found',
				sprintf(
					/* translators: %s: profession slug */
					__( 'Profession "%s" not found.', 'wp-mcp-ai' ),
					$profession_slug
				)
			);
		}

		return array(
			'success'    => true,
			'profession' => $profession,
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
