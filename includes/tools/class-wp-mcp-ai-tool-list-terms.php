<?php
/**
 * Tool for listing taxonomy terms.
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
 * Lists taxonomy terms (categories, tags, or custom taxonomies).
 *
 * Read-only companion to `create_term` / `update_term` so agents can
 * discover the site's existing taxonomy structure before mapping new content
 * against it.
 */
class WP_MCP_AI_Tool_List_Terms implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_terms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Taxonomy Terms', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists terms in a taxonomy (categories, tags, or custom taxonomies) with IDs, names, parents, and post counts. Use this to discover the site\'s existing category/tag structure before creating or assigning terms.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'       => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (e.g., "category", "post_tag", or custom taxonomy).', 'mcp-ai-wpoos' ),
				),
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Optional search string to filter terms by name or slug.', 'mcp-ai-wpoos' ),
				),
				'hide_empty'     => array(
					'type'        => 'boolean',
					'description' => __( 'Hide terms that have no assigned posts.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'parent'         => array(
					'type'        => 'integer',
					'description' => __( 'Optional parent term ID to list only children of that parent (hierarchical taxonomies only).', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of terms to return.', 'mcp-ai-wpoos' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'offset'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of terms to skip (pagination offset).', 'mcp-ai-wpoos' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'orderby'        => array(
					'type'        => 'string',
					'enum'        => array( 'name', 'count', 'slug', 'term_id' ),
					'description' => __( 'Field to order results by.', 'mcp-ai-wpoos' ),
					'default'     => 'name',
				),
				'order'          => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => __( 'Sort direction.', 'mcp-ai-wpoos' ),
					'default'     => 'ASC',
				),
				'include_counts' => array(
					'type'        => 'boolean',
					'description' => __( 'Include post counts per term.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list terms.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Gate 1: sanitise all inputs at entry.
		$taxonomy       = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : '';
		$search         = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$hide_empty     = isset( $arguments['hide_empty'] ) ? (bool) $arguments['hide_empty'] : false;
		$parent         = isset( $arguments['parent'] ) ? absint( $arguments['parent'] ) : 0;
		$limit          = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$offset         = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;
		$orderby        = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'name';
		$order          = isset( $arguments['order'] ) ? strtoupper( sanitize_key( $arguments['order'] ) ) : 'ASC';
		$include_counts = isset( $arguments['include_counts'] ) ? (bool) $arguments['include_counts'] : true;

		if ( '' === $taxonomy ) {
			return new WP_Error( 'wp_mcp_ai_missing_taxonomy', __( 'Taxonomy name is required.', 'mcp-ai-wpoos' ) );
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$valid_taxonomies = get_taxonomies( array(), 'names' );

			return new WP_Error(
				'wp_mcp_ai_invalid_taxonomy',
				__( 'The specified taxonomy does not exist.', 'mcp-ai-wpoos' ),
				array(
					'status'           => 400,
					'valid_taxonomies' => array_values( $valid_taxonomies ),
					'actions'          => array(
						'list_taxonomies' => __( 'Use the list_taxonomies tool to discover available taxonomies.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		// Clamp bounds.
		$limit = max( 1, min( 500, $limit ) );

		// Validate orderby / order enums.
		if ( ! in_array( $orderby, array( 'name', 'count', 'slug', 'term_id' ), true ) ) {
			$orderby = 'name';
		}
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		// Build get_terms() arguments.
		$term_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hide_empty,
			'orderby'    => $orderby,
			'order'      => $order,
			'number'     => $limit,
			'offset'     => $offset,
		);

		if ( '' !== $search ) {
			$term_args['search'] = $search;
		}

		if ( $parent > 0 && is_taxonomy_hierarchical( $taxonomy ) ) {
			$term_args['parent'] = $parent;
		}

		$terms = get_terms( $term_args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		if ( empty( $terms ) ) {
			$terms = array();
		}

		$results = array();
		foreach ( $terms as $term ) {
			$item = array(
				'term_id'  => absint( $term->term_id ),
				'name'     => sanitize_text_field( $term->name ),
				'slug'     => sanitize_title( $term->slug ),
				'taxonomy' => sanitize_key( $term->taxonomy ),
			);

			if ( '' !== $term->description ) {
				$item['description'] = wp_kses_post( $term->description );
			}

			$item['parent'] = absint( $term->parent );

			if ( $include_counts ) {
				$item['count'] = absint( $term->count );
			}

			// Gate 2: escape at exit — term links are URLs.
			$term_link = get_term_link( $term );
			if ( ! is_wp_error( $term_link ) ) {
				$item['link'] = esc_url_raw( $term_link );
			}

			$results[] = $item;
		}

		$summary_text = sprintf(
			/* translators: 1: number of terms, 2: taxonomy name */
			__( 'Found %1$d term(s) in "%2$s".', 'mcp-ai-wpoos' ),
			count( $results ),
			$taxonomy
		);

		return array(
			'message'     => $summary_text, // Chat client display.
			'summary'     => $summary_text, // Backward compatibility.
			'taxonomy'    => $taxonomy,
			'total_found' => count( $results ),
			'limit'       => $limit,
			'offset'      => $offset,
			'terms'       => $results,
		);
	}


	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.2.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'content_strategist', 'seo_specialist' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'read' capability.
		);
	}
}
