<?php
/**
 * Tool for creating/updating professions.
 *
 * Allows AI assistants to create or update profession definitions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or updates a profession.
 */
class WP_MCP_AI_Tool_Save_Profession implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'save_profession';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Save Profession', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new profession or updates an existing one. Professions define roles that can be used when creating AI assistants, including their expertise areas, default tools, and knowledge base.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Display name of the profession (e.g., "Data Scientist", "Marine Biologist")', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'slug'             => array(
					'type'        => 'string',
					'description' => __( 'Unique identifier (e.g., "data_scientist"). If updating, provide existing slug.', 'wp-mcp-ai' ),
					'pattern'     => '^[a-z0-9_-]+$',
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Brief description of the profession', 'wp-mcp-ai' ),
					'maxLength'   => 500,
				),
				'category'         => array(
					'type'        => 'string',
					'description' => __( 'Category: advisory, creative, technical, healthcare, legal, financial, or other', 'wp-mcp-ai' ),
					'enum'        => array( 'advisory', 'creative', 'technical', 'healthcare', 'legal', 'financial', 'other' ),
				),
				'role_description' => array(
					'type'        => 'string',
					'description' => __( 'Role description for AI instructions (what the assistant helps with)', 'wp-mcp-ai' ),
					'maxLength'   => 1000,
				),
				'expertise'        => array(
					'type'        => 'array',
					'description' => __( 'Array of expertise areas (e.g., ["Machine learning", "Data visualization"])', 'wp-mcp-ai' ),
					'items'       => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'maxItems'    => 20,
				),
				'warnings'         => array(
					'type'        => 'array',
					'description' => __( 'Array of disclaimers/warnings the AI should communicate', 'wp-mcp-ai' ),
					'items'       => array(
						'type'      => 'string',
						'maxLength' => 300,
					),
					'maxItems'    => 10,
				),
				'knowledge_base'   => array(
					'type'        => 'string',
					'description' => __( 'Knowledge base content (supports markdown)', 'wp-mcp-ai' ),
					'maxLength'   => 10000,
				),
				'default_tools'    => array(
					'type'        => 'array',
					'description' => __( 'Array of default tool slugs to enable for this profession', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'maxItems'    => 50,
				),
			),
			'required'             => array( 'title', 'slug' ),
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
		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to manage professions.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}
		// Validate required fields.
		if ( empty( $arguments['title'] ) || empty( $arguments['slug'] ) ) {
			return new WP_Error( 'missing_required', __( 'Title and slug are required.', 'wp-mcp-ai' ) );
		}

		// Get profession repository.
		$repository = new WP_MCP_AI_Profession_Repository();

		// Check if updating existing profession.
		$existing = $repository->find_one( $arguments['slug'] );

		// Prepare data.
		$data = array(
			'title'       => sanitize_text_field( $arguments['title'] ),
			'slug'        => sanitize_title( $arguments['slug'] ),
			'description' => isset( $arguments['description'] ) ? sanitize_text_field( $arguments['description'] ) : '',
			'status'      => 'publish',
		);

		if ( $existing ) {
			$data['id'] = $existing->ID;
		}

		// Add optional fields.
		if ( isset( $arguments['category'] ) ) {
			$data['category'] = sanitize_key( $arguments['category'] );
		}

		if ( isset( $arguments['role_description'] ) ) {
			$data['role_description'] = wp_kses_post( $arguments['role_description'] );
		}

		if ( isset( $arguments['expertise'] ) && is_array( $arguments['expertise'] ) ) {
			$data['expertise'] = array_map( 'sanitize_text_field', $arguments['expertise'] );
		}

		if ( isset( $arguments['warnings'] ) && is_array( $arguments['warnings'] ) ) {
			$data['warnings'] = array_map( 'sanitize_text_field', $arguments['warnings'] );
		}

		if ( isset( $arguments['knowledge_base'] ) ) {
			$data['knowledge_base'] = wp_kses_post( $arguments['knowledge_base'] );
		}

		if ( isset( $arguments['default_tools'] ) && is_array( $arguments['default_tools'] ) ) {
			$data['default_tools'] = array_map( 'sanitize_key', $arguments['default_tools'] );
		}

		// Save profession.
		$result = $repository->save( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Get updated profession data.
		$profession_service = wp_mcp_ai_get_profession_service();
		$profession         = $profession_service->get_profession( $data['slug'] );

		return array(
			'success'    => true,
			'action'     => $existing ? 'updated' : 'created',
			'profession' => $profession,
			'message'    => $existing
				? sprintf(
					/* translators: %s: profession name */
					__( 'Profession "%s" updated successfully.', 'wp-mcp-ai' ),
					$data['title']
				)
				: sprintf(
					/* translators: %s: profession name */
					__( 'Profession "%s" created successfully.', 'wp-mcp-ai' ),
					$data['title']
				),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates/updates posts.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires manage_options.
			'state-changing',       // Modifies database.
		);
	}
}
