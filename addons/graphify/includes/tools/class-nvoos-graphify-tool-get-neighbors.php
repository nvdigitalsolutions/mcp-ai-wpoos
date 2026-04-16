<?php
/**
 * Tool: Get Neighbors
 *
 * Returns the immediate neighbors of a knowledge-graph node.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve the direct neighbors of a given graph node.
 *
 * Looks up the node by label (fuzzy) or exact node ID, then queries all
 * edges where the node is either the source or target. An optional
 * relation filter narrows results to a specific edge type.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Get_Neighbors implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_get_neighbors';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Node Neighbors', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Get all direct neighbors of a knowledge-graph node. Look up by label (fuzzy) or node ID. Optionally filter by relationship type.', 'nvoos-graphify' );
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
				'label'           => array(
					'type'        => 'string',
					'description' => __( 'Node label (fuzzy LIKE search) or exact node ID.', 'nvoos-graphify' ),
				),
				'relation_filter' => array(
					'type'        => 'string',
					'description' => __( 'Optional: filter neighbors to a specific edge relation type.', 'nvoos-graphify' ),
				),
			),
			'required'   => array( 'label' ),
		);
	}

	/**
	 * Execute the neighbor lookup.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Neighbor list on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		if ( empty( $arguments['label'] ) ) {
			return new WP_Error( 'missing_label', __( 'A node label or node_id is required.', 'nvoos-graphify' ) );
		}

		$label           = sanitize_text_field( $arguments['label'] );
		$relation_filter = isset( $arguments['relation_filter'] ) ? sanitize_text_field( $arguments['relation_filter'] ) : '';

		global $wpdb;

		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		// Try exact node_id match first, then fuzzy label search.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE node_id = %s LIMIT 1",
				$nodes_table,
				$label
			)
		);

		if ( ! $node ) {
			$like = '%' . $wpdb->esc_like( $label ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$node = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE label LIKE %s LIMIT 1",
					$nodes_table,
					$like
				)
			);
		}

		if ( ! $node ) {
			return new WP_Error( 'not_found', __( 'Node not found.', 'nvoos-graphify' ) );
		}

		// Build neighbor query.
		if ( '' !== $relation_filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$edges = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.relation, e.confidence, e.source_node_id, e.target_node_id,
							n.node_id AS neighbor_id, n.label AS neighbor_label, n.type AS neighbor_type
					 FROM %i AS e
					 INNER JOIN %i AS n
						ON n.node_id = CASE
							WHEN e.source_node_id = %s THEN e.target_node_id
							ELSE e.source_node_id
						END
					 WHERE ( e.source_node_id = %s OR e.target_node_id = %s )
					   AND e.relation = %s",
					$edges_table,
					$nodes_table,
					$node->node_id,
					$node->node_id,
					$node->node_id,
					$relation_filter
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$edges = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.relation, e.confidence, e.source_node_id, e.target_node_id,
							n.node_id AS neighbor_id, n.label AS neighbor_label, n.type AS neighbor_type
					 FROM %i AS e
					 INNER JOIN %i AS n
						ON n.node_id = CASE
							WHEN e.source_node_id = %s THEN e.target_node_id
							ELSE e.source_node_id
						END
					 WHERE e.source_node_id = %s OR e.target_node_id = %s",
					$edges_table,
					$nodes_table,
					$node->node_id,
					$node->node_id,
					$node->node_id
				)
			);
		}

		$neighbor_list = array();
		if ( $edges ) {
			foreach ( $edges as $edge ) {
				$direction = ( $edge->source_node_id === $node->node_id ) ? 'outgoing' : 'incoming';

				$neighbor_list[] = array(
					'node_id'    => sanitize_text_field( $edge->neighbor_id ),
					'label'      => sanitize_text_field( $edge->neighbor_label ),
					'type'       => sanitize_text_field( $edge->neighbor_type ),
					'relation'   => sanitize_text_field( $edge->relation ),
					'confidence' => sanitize_text_field( $edge->confidence ),
					'direction'  => $direction,
				);
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of neighbors found */
				__( 'Found %d neighbor(s).', 'nvoos-graphify' ),
				count( $neighbor_list )
			),
			'data'    => array(
				'node'      => array(
					'node_id' => sanitize_text_field( $node->node_id ),
					'label'   => sanitize_text_field( $node->label ),
					'type'    => sanitize_text_field( $node->type ),
				),
				'neighbors' => $neighbor_list,
			),
		);
	}
}
