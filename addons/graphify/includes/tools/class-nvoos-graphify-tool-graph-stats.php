<?php
/**
 * Tool for retrieving knowledge graph statistics.
 *
 * Returns summary data about node and edge counts, relationship
 * type breakdown, community count, and content type distribution.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge Graph Statistics Tool.
 *
 * Queries the graph tables and returns aggregated metrics without
 * modifying any data. Results are cacheable.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Graph_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_graph_stats';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Knowledge Graph Statistics', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Returns summary statistics about the WordPress knowledge graph including node count, edge count, community count, relationship type breakdown, and content type distribution.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
	 * @since 0.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_strategist', 'seo_specialist', 'editor' ),
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
				__( 'You do not have permission to view graph statistics.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();
		$meta_table  = NV_oOS_Graphify_Database::get_meta_table();

		// Total node count.
		$total_nodes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$nodes_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// Total edge count.
		$total_edges = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$edges_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// Node counts grouped by type.
		$node_type_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_type, COUNT(*) AS cnt FROM {$nodes_table} WHERE graph_id = %d GROUP BY node_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		$node_types = array();
		if ( is_array( $node_type_rows ) ) {
			foreach ( $node_type_rows as $row ) {
				$node_types[ $row->node_type ] = (int) $row->cnt;
			}
		}

		// Edge counts grouped by relation.
		$edge_type_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT relation, COUNT(*) AS cnt FROM {$edges_table} WHERE graph_id = %d GROUP BY relation", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		$edge_types = array();
		if ( is_array( $edge_type_rows ) ) {
			foreach ( $edge_type_rows as $row ) {
				$edge_types[ $row->relation ] = (int) $row->cnt;
			}
		}

		// Edge counts grouped by confidence.
		$confidence_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT confidence, COUNT(*) AS cnt FROM {$edges_table} WHERE graph_id = %d GROUP BY confidence", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		$confidence_breakdown = array();
		if ( is_array( $confidence_rows ) ) {
			foreach ( $confidence_rows as $row ) {
				$confidence_breakdown[ $row->confidence ] = (int) $row->cnt;
			}
		}

		// Graph meta (single row with direct columns).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT last_built, build_status, community_count FROM {$meta_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		$last_built      = $meta ? $meta->last_built : '';
		$status          = $meta ? $meta->build_status : 'idle';
		$community_count = $meta ? (int) $meta->community_count : 0;

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: node count, 2: edge count */
				__( 'Knowledge graph has %1$d nodes and %2$d edges.', 'mcp-ai-wpoos' ),
				$total_nodes,
				$total_edges
			),
			'data'    => array(
				'graph_id'              => $graph_id,
				'node_count'            => $total_nodes,
				'edge_count'            => $total_edges,
				'community_count'       => $community_count,
				'last_built'            => $last_built,
				'build_status'          => $status,
				'node_types'            => $node_types,
				'edge_types'            => $edge_types,
				'confidence_breakdown'  => $confidence_breakdown,
			),
		);
	}
}
