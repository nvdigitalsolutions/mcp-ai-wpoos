<?php
/**
 * Tool for retrieving all directly connected nodes for a graph node.
 *
 * Finds a node by label, then returns every neighbor with edge
 * metadata including relation type, confidence, and direction.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Neighbors Tool.
 *
 * Retrieves all directly connected content for a node in the
 * knowledge graph, optionally filtered by edge relation type.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_Get_Neighbors implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_get_neighbors';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Get Node Neighbors', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Get all directly connected content for a node in the knowledge graph.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'           => array(
					'type'        => 'string',
					'description' => __( 'Label of the node whose neighbors to retrieve.', 'mcp-ai-wpoos' ),
				),
				'relation_filter' => array(
					'type'        => 'string',
					'description' => __( 'Optional edge relation type to filter by (e.g. "related_to", "links_to").', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'label' ),
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
				__( 'You do not have permission to view graph neighbors.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['label'] ) ) {
			return new WP_Error(
				'missing_label',
				__( 'A node label is required.', 'mcp-ai-wpoos' )
			);
		}

		$label           = sanitize_text_field( $arguments['label'] );
		$relation_filter = isset( $arguments['relation_filter'] ) ? sanitize_text_field( $arguments['relation_filter'] ) : '';

		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		// Find the node by label.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, source_url, degree FROM {$nodes_table} WHERE graph_id = %d AND label LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				'%' . $wpdb->esc_like( $label ) . '%'
			)
		);

		if ( ! $node ) {
			return new WP_Error(
				'node_not_found',
				sprintf(
					/* translators: %s: the searched label */
					__( 'No node found matching label "%s".', 'mcp-ai-wpoos' ),
					$label
				)
			);
		}

		// Build the edge query with optional relation filter.
		$edge_sql = "SELECT e.source_node_id, e.target_node_id, e.relation, e.confidence,
						n.node_id AS neighbor_node_id, n.label AS neighbor_label,
						n.node_type AS neighbor_type, n.source_url AS neighbor_url
					FROM {$edges_table} e
					INNER JOIN {$nodes_table} n
						ON n.graph_id = %d
						AND n.node_id = CASE WHEN e.source_node_id = %s THEN e.target_node_id ELSE e.source_node_id END
					WHERE ( e.source_node_id = %s OR e.target_node_id = %s )
						AND e.graph_id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params = array( $graph_id, $node->node_id, $node->node_id, $node->node_id, $graph_id );

		if ( '' !== $relation_filter ) {
			$edge_sql .= ' AND e.relation = %s';
			$params[]  = $relation_filter;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				$edge_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$params
			)
		);

		$neighbors = array();
		if ( is_array( $edges ) ) {
			foreach ( $edges as $edge ) {
				$is_outgoing = ( $edge->source_node_id === $node->node_id );
				$neighbors[] = array(
					'node_id'    => $edge->neighbor_node_id,
					'label'      => $edge->neighbor_label,
					'type'       => $edge->neighbor_type,
					'source_url' => $edge->neighbor_url,
					'relation'   => $edge->relation,
					'confidence' => $edge->confidence,
					'direction'  => $is_outgoing ? 'outgoing' : 'incoming',
				);
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: node label, 2: neighbor count */
				__( 'Node "%1$s" has %2$d neighbors.', 'mcp-ai-wpoos' ),
				$node->label,
				count( $neighbors )
			),
			'data'    => array(
				'node'      => array(
					'node_id'    => $node->node_id,
					'label'      => $node->label,
					'type'       => $node->node_type,
					'source_url' => $node->source_url,
					'degree'     => (int) $node->degree,
				),
				'neighbors' => $neighbors,
			),
		);
	}
}
