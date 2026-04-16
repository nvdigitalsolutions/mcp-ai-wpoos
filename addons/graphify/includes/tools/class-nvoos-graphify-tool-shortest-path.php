<?php
/**
 * Tool: Shortest Path
 *
 * Finds the shortest path between two nodes in the knowledge graph.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compute the shortest path between two graph nodes.
 *
 * Resolves the source and target by label (fuzzy) or exact node ID, then
 * delegates to NV_oOS_Graphify_Analyzer::get_shortest_path() for the
 * actual BFS traversal with a configurable max-hop limit.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Shortest_Path implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_shortest_path';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Shortest Path', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Find the shortest path between two nodes in the knowledge graph. Nodes can be specified by label (fuzzy search) or exact node ID. Returns the full path with node details and relationship types.', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'local-only' );
	}

	/**
	 * Get the JSON Schema for accepted parameters.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source'   => array(
					'type'        => 'string',
					'description' => __( 'Source node label (fuzzy) or exact node ID.', 'nvoos-graphify' ),
				),
				'target'   => array(
					'type'        => 'string',
					'description' => __( 'Target node label (fuzzy) or exact node ID.', 'nvoos-graphify' ),
				),
				'max_hops' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 10,
					'description' => __( 'Maximum number of hops allowed in the path.', 'nvoos-graphify' ),
				),
			),
			'required'   => array( 'source', 'target' ),
		);
	}

	/**
	 * Execute the shortest-path computation.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Path details on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		if ( empty( $arguments['source'] ) || empty( $arguments['target'] ) ) {
			return new WP_Error( 'missing_params', __( 'Both source and target are required.', 'nvoos-graphify' ) );
		}

		$source   = sanitize_text_field( $arguments['source'] );
		$target   = sanitize_text_field( $arguments['target'] );
		$max_hops = isset( $arguments['max_hops'] ) ? absint( $arguments['max_hops'] ) : 10;
		$max_hops = max( 1, min( 20, $max_hops ) );

		$source_node_id = $this->resolve_node_id( $source );
		$target_node_id = $this->resolve_node_id( $target );

		if ( is_wp_error( $source_node_id ) ) {
			return $source_node_id;
		}

		if ( is_wp_error( $target_node_id ) ) {
			return $target_node_id;
		}

		$analyzer = new NV_oOS_Graphify_Analyzer();
		$path     = $analyzer->get_shortest_path( $source_node_id, $target_node_id, $max_hops );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of hops in the path */
				__( 'Path found with %d hop(s).', 'nvoos-graphify' ),
				is_array( $path ) && isset( $path['hops'] ) ? absint( $path['hops'] ) : 0
			),
			'data'    => $path,
		);
	}

	/**
	 * Resolve a label or node_id string to an internal node ID.
	 *
	 * Tries an exact node_id match first, then falls back to a fuzzy label
	 * LIKE search.
	 *
	 * @since  0.1.0
	 * @param  string $identifier Node label or node_id.
	 * @return string|WP_Error Resolved node_id on success, WP_Error on failure.
	 */
	private function resolve_node_id( $identifier ) {
		global $wpdb;

		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_id FROM %i WHERE node_id = %s LIMIT 1",
				$nodes_table,
				$identifier
			)
		);

		if ( $node ) {
			return $node->node_id;
		}

		$like = '%' . $wpdb->esc_like( $identifier ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_id FROM %i WHERE label LIKE %s LIMIT 1",
				$nodes_table,
				$like
			)
		);

		if ( $node ) {
			return $node->node_id;
		}

		return new WP_Error(
			'node_not_found',
			sprintf(
				/* translators: %s: the identifier that could not be resolved */
				__( 'Could not resolve node: %s', 'nvoos-graphify' ),
				$identifier
			)
		);
	}
}
