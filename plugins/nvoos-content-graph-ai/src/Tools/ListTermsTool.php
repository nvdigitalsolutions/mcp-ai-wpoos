<?php
/**
 * List Terms tool (D8 Cluster 2b port of the base plugin's
 * WP_MCP_AI_Tool_List_Terms — byte-identical slug, schema, error codes,
 * and envelope).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Lists terms in a taxonomy with IDs, names, parents, and post counts.
 */
class ListTermsTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'list_terms';
	}

	public function getName(): string {
		return __( 'List Taxonomy Terms', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Lists terms in a taxonomy (categories, tags, or custom taxonomies) with IDs, names, parents, and post counts. Use this to discover the site\'s existing category/tag structure before creating or assigning terms.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'       => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (e.g., "category", "post_tag", or custom taxonomy).', 'nvoos-content-graph-ai' ),
				),
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Optional search string to filter terms by name or slug.', 'nvoos-content-graph-ai' ),
				),
				'hide_empty'     => array(
					'type'        => 'boolean',
					'description' => __( 'Hide terms that have no assigned posts.', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
				'parent'         => array(
					'type'        => 'integer',
					'description' => __( 'Optional parent term ID to list only children of that parent (hierarchical taxonomies only).', 'nvoos-content-graph-ai' ),
					'minimum'     => 0,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of terms to return.', 'nvoos-content-graph-ai' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'offset'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of terms to skip (pagination offset).', 'nvoos-content-graph-ai' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'orderby'        => array(
					'type'        => 'string',
					'enum'        => array( 'name', 'count', 'slug', 'term_id' ),
					'description' => __( 'Field to order results by.', 'nvoos-content-graph-ai' ),
					'default'     => 'name',
				),
				'order'          => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => __( 'Sort direction.', 'nvoos-content-graph-ai' ),
					'default'     => 'ASC',
				),
				'include_counts' => array(
					'type'        => 'boolean',
					'description' => __( 'Include post counts per term.', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list terms.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
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
			return new \WP_Error( 'wp_mcp_ai_missing_taxonomy', __( 'Taxonomy name is required.', 'nvoos-content-graph-ai' ) );
		}

		// Validate taxonomy exists.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$valid_taxonomies = get_taxonomies( array(), 'names' );

			return new \WP_Error(
				'wp_mcp_ai_invalid_taxonomy',
				__( 'The specified taxonomy does not exist.', 'nvoos-content-graph-ai' ),
				array(
					'status'           => 400,
					'valid_taxonomies' => array_values( $valid_taxonomies ),
					'actions'          => array(
						'list_taxonomies' => __( 'Use the list_taxonomies tool to discover available taxonomies.', 'nvoos-content-graph-ai' ),
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
			__( 'Found %1$d term(s) in "%2$s".', 'nvoos-content-graph-ai' ),
			count( $results ),
			$taxonomy
		);

		return array(
			'message'     => $summary_text,
			'summary'     => $summary_text,
			'taxonomy'    => $taxonomy,
			'total_found' => count( $results ),
			'limit'       => $limit,
			'offset'      => $offset,
			'terms'       => $results,
		);
	}
}
