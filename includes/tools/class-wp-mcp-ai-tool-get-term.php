<?php
/**
 * Tool: get_term — Returns details for a single taxonomy term.
 *
 * Port of mcp-wordpress wp_get_category tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns details for a single taxonomy term (category, tag, or custom taxonomy) by ID.
 */
class WP_MCP_AI_Tool_Get_Term implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_term';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Taxonomy Term', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns details for a single taxonomy term (category, tag, or custom taxonomy) by ID, including slug, description, parent, and post count.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Fetching a single taxonomy term by ID with its slug, description, parent, and post count.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Browsing or searching many terms at once; use list_terms instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_terms', 'create_term', 'update_term', 'delete_term' ),
			'notes'           => __( 'term_id comes from list_terms / create_term responses. taxonomy must be a registered taxonomy.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the term to fetch.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy the term belongs to (e.g., "category", "post_tag", or a custom taxonomy).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'term_id', 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'term_id' ),
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
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view taxonomy terms.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$term_id  = isset( $arguments['term_id'] ) ? absint( $arguments['term_id'] ) : 0;
		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : '';

		if ( ! $term_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'term_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === $taxonomy ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'taxonomy is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_taxonomy', __( 'The specified taxonomy does not exist.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view taxonomy terms.', 'mcp-ai-wpoos' ) );
		}

		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'wp_mcp_ai_term_not_found', __( 'The requested term could not be found in the specified taxonomy.', 'mcp-ai-wpoos' ) );
		}

		$summary_text = sprintf(
			/* translators: 1: term name, 2: term ID, 3: taxonomy */
			__( 'Term %1$s (ID: %2$d) in %3$s.', 'mcp-ai-wpoos' ),
			$term->name,
			$term->term_id,
			$taxonomy
		);

		$response = array(
			'message'     => $summary_text,
			'term_id'     => absint( $term->term_id ),
			'name'        => esc_html( $term->name ),
			'slug'        => esc_html( $term->slug ),
			'taxonomy'    => esc_html( $term->taxonomy ),
			'description' => wp_strip_all_tags( $term->description ),
			'parent'      => absint( $term->parent ),
			'count'       => absint( $term->count ),
		);

		// Add edit link if available.
		$edit_link = get_edit_term_link( $term->term_id, $taxonomy );
		if ( $edit_link ) {
			$response['edit_link'] = esc_url_raw( $edit_link );
		}

		return $response;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'content_strategist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires edit_posts capability.
		);
	}
}
