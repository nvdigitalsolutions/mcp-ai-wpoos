<?php
/**
 * Tool: Get Community
 *
 * Retrieves all nodes belonging to a specific graph community.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List every node in a given community cluster.
 *
 * Returns labels, types, degree counts, and source URLs for all nodes
 * assigned to the requested community ID.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Get_Community implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_get_community';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Community', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve all nodes belonging to a specific community cluster in the knowledge graph. Returns labels, types, degree counts, and source URLs for each node.', 'nvoos-graphify' );
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
				'community_id' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'The community cluster ID to retrieve.', 'nvoos-graphify' ),
				),
			),
			'required'   => array( 'community_id' ),
		);
	}

	/**
	 * Execute the community lookup.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Community node list on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		if ( ! isset( $arguments['community_id'] ) ) {
			return new WP_Error( 'missing_community_id', __( 'A community_id is required.', 'nvoos-graphify' ) );
		}

		$community_id = absint( $arguments['community_id'] );

		global $wpdb;

		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$nodes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE community_id = %d ORDER BY label ASC",
				$nodes_table,
				$community_id
			)
		);

		if ( ! $nodes ) {
			return new WP_Error(
				'empty_community',
				__( 'No nodes found for the specified community.', 'nvoos-graphify' )
			);
		}

		$node_ids = wp_list_pluck( $nodes, 'node_id' );

		// Build degree counts via a single query for all community nodes.
		$id_placeholders = implode( ',', array_fill( 0, count( $node_ids ), '%s' ) );

		$degree_args = array_merge(
			array( $edges_table ),
			$node_ids,
			$node_ids
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$degree_rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT node_id, COUNT(*) AS degree FROM (
					SELECT source_node_id AS node_id FROM %i WHERE source_node_id IN ({$id_placeholders})
					UNION ALL
					SELECT target_node_id AS node_id FROM %i WHERE target_node_id IN ({$id_placeholders})
				) AS combined GROUP BY node_id",
				array_merge(
					array( $edges_table ),
					$node_ids,
					array( $edges_table ),
					$node_ids
				)
			)
		);

		$degrees = array();
		if ( $degree_rows ) {
			foreach ( $degree_rows as $row ) {
				$degrees[ $row->node_id ] = absint( $row->degree );
			}
		}

		$result = array();
		foreach ( $nodes as $node ) {
			$source_url = null;
			if ( 'post' === $node->source_type && ! empty( $node->source_id ) ) {
				$source_url = get_permalink( absint( $node->source_id ) );
			}

			$result[] = array(
				'node_id'    => sanitize_text_field( $node->node_id ),
				'label'      => sanitize_text_field( $node->label ),
				'type'       => sanitize_text_field( $node->type ),
				'degree'     => isset( $degrees[ $node->node_id ] ) ? $degrees[ $node->node_id ] : 0,
				'source_url' => $source_url ? esc_url( $source_url ) : null,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: number of nodes, 2: community ID */
				__( 'Found %1$d node(s) in community %2$d.', 'nvoos-graphify' ),
				count( $result ),
				$community_id
			),
			'data'    => array(
				'community_id' => $community_id,
				'nodes'        => $result,
			),
		);
	}
}
