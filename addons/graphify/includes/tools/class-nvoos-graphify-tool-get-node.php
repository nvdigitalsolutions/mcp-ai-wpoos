<?php
/**
 * Tool for looking up a single node in the knowledge graph.
 *
 * Returns full node details, community membership, degree, and
 * a list of directly connected neighbors with edge metadata.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Node Tool.
 *
 * Looks up a content node by label or post ID and returns its
 * details together with immediate neighbor information.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_Get_Node implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_get_node';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Get Knowledge Graph Node', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Look up full details for a content node in the knowledge graph by label or post ID.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'Node label to search for (partial match supported). At least one of label or post_id is required.', 'mcp-ai-wpoos' ),
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID to look up. At least one of label or post_id is required.', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'cacheable',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 0.2.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_strategist', 'editor' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view graph nodes.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		$label   = isset( $arguments['label'] ) ? sanitize_text_field( $arguments['label'] ) : '';
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( '' === $label && 0 === $post_id ) {
			return new WP_Error(
				'missing_identifier',
				__( 'At least one of label or post_id is required.', 'mcp-ai-wpoos' )
			);
		}

		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		$node = null;

		$select_cols = 'node_id, label, node_type, source_id, source_url, degree, community';

		if ( $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$node = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT {$select_cols} FROM {$nodes_table} WHERE graph_id = %d AND source_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$graph_id,
					(string) $post_id
				)
			);
		}

		if ( ! $node && '' !== $label ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$node = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT {$select_cols} FROM {$nodes_table} WHERE graph_id = %d AND label LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$graph_id,
					'%' . $wpdb->esc_like( $label ) . '%'
				)
			);
		}

		if ( ! $node ) {
			return new WP_Error(
				'node_not_found',
				__( 'No node found matching the given criteria.', 'mcp-ai-wpoos' )
			);
		}

		// Fetch neighbors via edges.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.source_node_id, e.target_node_id, e.relation, e.confidence,
						n.label AS neighbor_label, n.node_type AS neighbor_type
				FROM {$edges_table} e
				INNER JOIN {$nodes_table} n
					ON n.graph_id = %d
					AND n.node_id = CASE WHEN e.source_node_id = %s THEN e.target_node_id ELSE e.source_node_id END
				WHERE ( e.source_node_id = %s OR e.target_node_id = %s )
					AND e.graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				$node->node_id,
				$node->node_id,
				$node->node_id,
				$graph_id
			)
		);

		$neighbors = array();
		if ( is_array( $edges ) ) {
			foreach ( $edges as $edge ) {
				$is_outgoing = ( $edge->source_node_id === $node->node_id );
				$neighbors[] = array(
					'label'      => $edge->neighbor_label,
					'type'       => $edge->neighbor_type,
					'relation'   => $edge->relation,
					'confidence' => $edge->confidence,
					'direction'  => $is_outgoing ? 'outgoing' : 'incoming',
				);
			}
		}

		$node_data = array(
			'node_id'    => $node->node_id,
			'label'      => $node->label,
			'node_type'  => $node->node_type,
			'source_id'  => $node->source_id,
			'source_url' => $node->source_url,
			'degree'     => isset( $node->degree ) ? (int) $node->degree : count( $neighbors ),
			'community'  => isset( $node->community ) ? $node->community : null,
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: node label, 2: neighbor count */
				__( 'Found node "%1$s" with %2$d neighbors.', 'mcp-ai-wpoos' ),
				$node->label,
				count( $neighbors )
			),
			'data'    => array(
				'node'      => $node_data,
				'neighbors' => $neighbors,
			),
		);
	}
}
