<?php
/**
 * Tool: Get Node
 *
 * Retrieves a single node and its immediate neighbors from the knowledge graph.
 *
 * @package NVoOS_Graphify
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Look up a knowledge-graph node by label, post ID, or node ID.
 *
 * Returns the node's metadata together with a list of directly connected
 * neighbor nodes and the relationship types linking them.
 *
 * @since 1.0.0
 */
class NV_oOS_Graphify_Tool_Get_Node implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_get_node';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Graph Node', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Look up a node in the knowledge graph by its label (fuzzy), WordPress post ID, or internal node ID. Returns node details and its immediate neighbors.', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'local-only' );
	}

	/**
	 * Get the JSON Schema for accepted parameters.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'Fuzzy label search (LIKE match).', 'nvoos-graphify' ),
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID to look up the corresponding node.', 'nvoos-graphify' ),
				),
				'node_id' => array(
					'type'        => 'string',
					'description' => __( 'Internal graph node ID for an exact match.', 'nvoos-graphify' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Execute the node lookup.
	 *
	 * @since  1.0.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Node details on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$label   = isset( $arguments['label'] ) ? sanitize_text_field( $arguments['label'] ) : '';
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$node_id = isset( $arguments['node_id'] ) ? sanitize_text_field( $arguments['node_id'] ) : '';

		if ( '' === $label && 0 === $post_id && '' === $node_id ) {
			return new WP_Error(
				'missing_identifier',
				__( 'At least one of label, post_id, or node_id is required.', 'nvoos-graphify' )
			);
		}

		global $wpdb;

		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		$node = null;

		if ( '' !== $node_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$node = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE node_id = %s LIMIT 1",
					$nodes_table,
					$node_id
				)
			);
		} elseif ( $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$node = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE source_type = %s AND source_id = %d LIMIT 1",
					$nodes_table,
					'post',
					$post_id
				)
			);
		} elseif ( '' !== $label ) {
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

		// Fetch neighbors via edges where this node is source or target.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$neighbors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.node_id, n.label, n.type, e.relation, e.confidence,
						CASE WHEN e.source_node_id = %s THEN 'outgoing' ELSE 'incoming' END AS direction
				 FROM %i AS e
				 INNER JOIN %i AS n
					ON n.node_id = CASE
						WHEN e.source_node_id = %s THEN e.target_node_id
						ELSE e.source_node_id
					END
				 WHERE e.source_node_id = %s OR e.target_node_id = %s",
				$node->node_id,
				$edges_table,
				$nodes_table,
				$node->node_id,
				$node->node_id,
				$node->node_id
			)
		);

		$neighbor_list = array();
		if ( $neighbors ) {
			foreach ( $neighbors as $nb ) {
				$neighbor_list[] = array(
					'node_id'    => sanitize_text_field( $nb->node_id ),
					'label'      => sanitize_text_field( $nb->label ),
					'type'       => sanitize_text_field( $nb->type ),
					'relation'   => sanitize_text_field( $nb->relation ),
					'confidence' => sanitize_text_field( $nb->confidence ),
					'direction'  => sanitize_text_field( $nb->direction ),
				);
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Node retrieved.', 'nvoos-graphify' ),
			'data'    => array(
				'node'      => array(
					'node_id'     => sanitize_text_field( $node->node_id ),
					'label'       => sanitize_text_field( $node->label ),
					'type'        => sanitize_text_field( $node->type ),
					'source_type' => isset( $node->source_type ) ? sanitize_text_field( $node->source_type ) : null,
					'source_id'   => isset( $node->source_id ) ? absint( $node->source_id ) : null,
				),
				'neighbors' => $neighbor_list,
			),
		);
	}
}
