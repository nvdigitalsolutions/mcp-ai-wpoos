<?php
/**
 * Graphify Knowledge Graph — Graph Builder
 *
 * Merges extracted nodes and edges, deduplicates, and persists to the
 * database. Analogous to Graphify's build.py.
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes extracted graph data to the database with deduplication.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Builder {

	/**
	 * Build (persist) graph data from extraction results.
	 *
	 * @param int   $graph_id   Graph ID.
	 * @param array $extraction Extraction results from extractor (nodes + edges arrays).
	 * @param bool  $full_build If true, clears existing graph first. If false, merges incrementally.
	 * @return array {
	 *     Build summary.
	 *
	 *     @type int $nodes_written Number of nodes upserted.
	 *     @type int $edges_written Number of edges upserted.
	 *     @type int $total_nodes   Total nodes in graph after build.
	 *     @type int $total_edges   Total edges in graph after build.
	 * }
	 */
	public function build( $graph_id, $extraction, $full_build = true ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		// Mark build in-progress.
		$db::update_graph_meta( $graph_id, array( 'build_status' => 'building' ) );

		// Full build: clear existing graph data.
		if ( $full_build ) {
			$db::clear_graph( $graph_id );
		}

		// 1. Upsert nodes.
		$nodes_written = 0;
		if ( ! empty( $extraction['nodes'] ) ) {
			$nodes_written = $db::bulk_upsert_nodes( $extraction['nodes'] );
		}

		// 2. Upsert edges.
		$edges_written = 0;
		if ( ! empty( $extraction['edges'] ) ) {
			$edges_written = $db::bulk_upsert_edges( $extraction['edges'] );
		}

		// 3. Recalculate node degrees based on actual edges.
		$db::recalculate_degrees( $graph_id );

		// 4. Update graph meta with final counts.
		$total_nodes = $db::count_nodes( $graph_id );
		$total_edges = $db::count_edges( $graph_id );

		$db::update_graph_meta(
			$graph_id,
			array(
				'node_count'   => $total_nodes,
				'edge_count'   => $total_edges,
				'last_built'   => current_time( 'mysql', true ),
				'build_status' => 'complete',
			)
		);

		/**
		 * Fires after the knowledge graph has been built.
		 *
		 * @since 1.6.0
		 *
		 * @param int   $graph_id     Graph ID.
		 * @param int   $total_nodes  Total node count.
		 * @param int   $total_edges  Total edge count.
		 * @param bool  $full_build   Whether this was a full build.
		 */
		do_action( 'wp_mcp_ai_graphify_graph_built', $graph_id, $total_nodes, $total_edges, $full_build );

		return array(
			'nodes_written' => $nodes_written,
			'edges_written' => $edges_written,
			'total_nodes'   => $total_nodes,
			'total_edges'   => $total_edges,
		);
	}
}
