<?php
/**
 * Taxonomies Tool - Operations for WordPress taxonomies and terms.
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WordPress taxonomy and term operations.
 *
 * Provides access to taxonomies and terms including:
 * - Listing available taxonomies
 * - Getting terms for a taxonomy
 * - Searching terms
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Core_Tool_Taxonomies implements WP_MCP_AI_Core_Tool_Interface, WP_MCP_AI_Core_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'taxonomies';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Taxonomies', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query WordPress taxonomies and terms. Supports listing taxonomies, getting terms, searching, and managing term assignments.', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'    => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: list_taxonomies, list_terms, get_term, search_terms.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'list_taxonomies', 'list_terms', 'get_term', 'search_terms' ),
					'default'     => 'list_taxonomies',
				),
				'taxonomy'  => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy name (e.g., category, post_tag).', 'wp-mcp-ai-core' ),
				),
				'term_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Term ID for get_term action.', 'wp-mcp-ai-core' ),
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of terms to return. Default: 20. Max: 100.', 'wp-mcp-ai-core' ),
					'default'     => 20,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-core' ),
					'default'     => 1,
				),
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter results.', 'wp-mcp-ai-core' ),
				),
				'parent'    => array(
					'type'        => 'integer',
					'description' => __( 'Filter terms by parent ID.', 'wp-mcp-ai-core' ),
				),
				'hide_empty' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to hide terms with no posts. Default: false.', 'wp-mcp-ai-core' ),
					'default'     => false,
				),
				'orderby'   => array(
					'type'        => 'string',
					'description' => __( 'Field to order by. Default: name.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'name', 'slug', 'term_id', 'count', 'parent' ),
					'default'     => 'name',
				),
				'order'     => array(
					'type'        => 'string',
					'description' => __( 'Order direction. Default: ASC.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'ASC',
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',    // Only read operations.
			'local-only',   // No external API calls.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_taxonomies';

		switch ( $action ) {
			case 'list_taxonomies':
				return $this->list_taxonomies( $arguments );
			case 'list_terms':
				return $this->list_terms( $arguments );
			case 'get_term':
				return $this->get_term( $arguments );
			case 'search_terms':
				return $this->search_terms( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-core' )
				);
		}
	}

	/**
	 * List all public taxonomies.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_taxonomies( $arguments ) {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		$result = array();
		foreach ( $taxonomies as $taxonomy ) {
			$result[] = $this->format_taxonomy( $taxonomy );
		}

		return array(
			'taxonomies' => $result,
			'total'      => count( $result ),
		);
	}

	/**
	 * List terms for a taxonomy.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function list_terms( $arguments ) {
		if ( empty( $arguments['taxonomy'] ) ) {
			return new WP_Error(
				'missing_taxonomy',
				__( 'Taxonomy is required for list_terms action.', 'wp-mcp-ai-core' )
			);
		}

		$taxonomy = sanitize_key( $arguments['taxonomy'] );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'invalid_taxonomy',
				__( 'The specified taxonomy does not exist.', 'wp-mcp-ai-core' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$query_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => isset( $arguments['hide_empty'] ) ? (bool) $arguments['hide_empty'] : false,
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'name',
			'order'      => isset( $arguments['order'] ) ? strtoupper( sanitize_key( $arguments['order'] ) ) : 'ASC',
		);

		if ( isset( $arguments['parent'] ) ) {
			$query_args['parent'] = absint( $arguments['parent'] );
		}

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['search'] = sanitize_text_field( $arguments['search'] );
		}

		$terms = get_terms( $query_args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		// Get total count.
		$count_args           = $query_args;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		$count_args['fields'] = 'count';
		$total                = get_terms( $count_args );

		$result = array();
		foreach ( $terms as $term ) {
			$result[] = $this->format_term( $term );
		}

		return array(
			'terms'       => $result,
			'total'       => is_numeric( $total ) ? (int) $total : count( $result ),
			'total_pages' => is_numeric( $total ) ? ceil( $total / $per_page ) : 1,
			'page'        => $page,
			'taxonomy'    => $taxonomy,
		);
	}

	/**
	 * Get a single term by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_term( $arguments ) {
		if ( empty( $arguments['term_id'] ) ) {
			return new WP_Error(
				'missing_term_id',
				__( 'Term ID is required for get_term action.', 'wp-mcp-ai-core' )
			);
		}

		$taxonomy = isset( $arguments['taxonomy'] ) ? sanitize_key( $arguments['taxonomy'] ) : '';
		$term_id  = absint( $arguments['term_id'] );

		if ( $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'invalid_taxonomy',
				__( 'The specified taxonomy does not exist.', 'wp-mcp-ai-core' )
			);
		}

		$term = $taxonomy ? get_term( $term_id, $taxonomy ) : get_term( $term_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! $term ) {
			return new WP_Error(
				'term_not_found',
				__( 'Term not found.', 'wp-mcp-ai-core' )
			);
		}

		return $this->format_term( $term );
	}

	/**
	 * Search terms across taxonomies.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function search_terms( $arguments ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search_terms action.', 'wp-mcp-ai-core' )
			);
		}

		// If taxonomy is specified, use list_terms.
		if ( ! empty( $arguments['taxonomy'] ) ) {
			return $this->list_terms( $arguments );
		}

		// Search across all public taxonomies.
		$taxonomies = get_taxonomies( array( 'public' => true ) );

		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$query_args = array(
			'taxonomy'   => array_values( $taxonomies ),
			'hide_empty' => isset( $arguments['hide_empty'] ) ? (bool) $arguments['hide_empty'] : false,
			'search'     => sanitize_text_field( $arguments['search'] ),
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$terms = get_terms( $query_args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		// Get total count.
		$count_args           = $query_args;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		$count_args['fields'] = 'count';
		$total                = get_terms( $count_args );

		$result = array();
		foreach ( $terms as $term ) {
			$result[] = $this->format_term( $term );
		}

		return array(
			'terms'       => $result,
			'total'       => is_numeric( $total ) ? (int) $total : count( $result ),
			'total_pages' => is_numeric( $total ) ? ceil( $total / $per_page ) : 1,
			'page'        => $page,
		);
	}

	/**
	 * Format a taxonomy for output.
	 *
	 * @param WP_Taxonomy $taxonomy Taxonomy object.
	 * @return array
	 */
	protected function format_taxonomy( $taxonomy ) {
		return array(
			'name'         => $taxonomy->name,
			'label'        => $taxonomy->label,
			'description'  => $taxonomy->description,
			'hierarchical' => $taxonomy->hierarchical,
			'public'       => $taxonomy->public,
			'post_types'   => $taxonomy->object_type,
			'rest_base'    => $taxonomy->rest_base,
		);
	}

	/**
	 * Format a term for output.
	 *
	 * @param WP_Term $term Term object.
	 * @return array
	 */
	protected function format_term( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
			'link'        => get_term_link( $term ),
		);
	}
}
