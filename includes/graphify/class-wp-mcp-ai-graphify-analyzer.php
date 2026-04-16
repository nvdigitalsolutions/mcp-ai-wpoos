<?php
/**
 * Graphify Knowledge Graph — Graph Analyzer
 *
 * Computes analytics over the knowledge graph: god nodes (most connected),
 * surprising cross-community connections, knowledge gaps, and summary stats.
 * Adapted from Graphify's analyze.py.
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
 * Analytical queries over the knowledge graph.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Analyzer {

	/**
	 * Get comprehensive graph statistics.
	 *
	 * @param int $graph_id Graph ID.
	 * @return array Summary statistics.
	 */
	public function graph_stats( $graph_id = 1 ) {
		$db   = 'WP_MCP_AI_Graphify_Database';
		$meta = $db::get_graph_meta( $graph_id );

		if ( ! $meta ) {
			return array(
				'error' => 'Graph not found.',
			);
		}

		// Type breakdown.
		$nodes = $db::get_nodes(
			array(
				'graph_id' => $graph_id,
				'limit'    => 10000,
			)
		);

		$type_counts = array();
		$total_degree = 0;
		foreach ( $nodes as $node ) {
			$nt = $node['node_type'];
			if ( ! isset( $type_counts[ $nt ] ) ) {
				$type_counts[ $nt ] = 0;
			}
			++$type_counts[ $nt ];
			$total_degree += (int) $node['degree'];
		}

		$node_count = (int) $meta['node_count'];
		$edge_count = (int) $meta['edge_count'];

		// Confidence breakdown.
		$edges = $db::get_edges(
			array(
				'graph_id' => $graph_id,
				'limit'    => 50000,
			)
		);

		$confidence_counts = array(
			'EXTRACTED' => 0,
			'INFERRED'  => 0,
			'AMBIGUOUS' => 0,
		);

		$relation_counts = array();
		foreach ( $edges as $edge ) {
			$conf = $edge['confidence'];
			if ( isset( $confidence_counts[ $conf ] ) ) {
				++$confidence_counts[ $conf ];
			}

			$rel = $edge['relation'];
			if ( ! isset( $relation_counts[ $rel ] ) ) {
				$relation_counts[ $rel ] = 0;
			}
			++$relation_counts[ $rel ];
		}

		arsort( $relation_counts );

		return array(
			'graph_id'         => $graph_id,
			'node_count'       => $node_count,
			'edge_count'       => $edge_count,
			'community_count'  => (int) $meta['community_count'],
			'average_degree'   => $node_count > 0 ? round( $total_degree / $node_count, 2 ) : 0,
			'node_types'       => $type_counts,
			'confidence'       => $confidence_counts,
			'relations'        => $relation_counts,
			'last_built'       => $meta['last_built'],
			'build_status'     => $meta['build_status'],
		);
	}

	/**
	 * Get the most connected nodes (god nodes / content pillars).
	 *
	 * @param int $graph_id Graph ID.
	 * @param int $top_n    Number of results. Default 10.
	 * @return array Array of node data sorted by degree descending.
	 */
	public function god_nodes( $graph_id = 1, $top_n = 10 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		return $db::get_nodes(
			array(
				'graph_id' => $graph_id,
				'orderby'  => 'degree',
				'order'    => 'DESC',
				'limit'    => max( 1, min( $top_n, 100 ) ),
			)
		);
	}

	/**
	 * Find surprising connections — edges that cross communities or content types.
	 *
	 * Scoring mirrors Graphify's composite approach:
	 * - Cross-community bonus: +2 if source and target are in different communities
	 * - Cross-type bonus: +1 if source and target have different node_types
	 * - Peripheral-to-hub bonus: +1 if one end has degree ≤ 2 and the other has degree ≥ 5
	 * - Confidence weight: INFERRED +1, AMBIGUOUS +2
	 *
	 * @param int $graph_id Graph ID.
	 * @param int $top_n    Number of results. Default 10.
	 * @return array Array of edge data with surprise scores.
	 */
	public function surprising_connections( $graph_id = 1, $top_n = 10 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		// Load all nodes for lookups.
		$all_nodes = $db::get_nodes(
			array(
				'graph_id' => $graph_id,
				'limit'    => 10000,
			)
		);

		$node_map = array();
		foreach ( $all_nodes as $node ) {
			$node_map[ $node['node_id'] ] = $node;
		}

		// Load all edges.
		$all_edges = $db::get_edges(
			array(
				'graph_id' => $graph_id,
				'limit'    => 50000,
			)
		);

		$scored = array();

		foreach ( $all_edges as $edge ) {
			$src = isset( $node_map[ $edge['source_node_id'] ] ) ? $node_map[ $edge['source_node_id'] ] : null;
			$tgt = isset( $node_map[ $edge['target_node_id'] ] ) ? $node_map[ $edge['target_node_id'] ] : null;

			if ( ! $src || ! $tgt ) {
				continue;
			}

			$score = 0;

			// Cross-community.
			if ( (int) $src['community_id'] !== (int) $tgt['community_id'] && (int) $src['community_id'] > 0 && (int) $tgt['community_id'] > 0 ) {
				$score += 2;
			}

			// Cross-type.
			if ( $src['node_type'] !== $tgt['node_type'] ) {
				$score += 1;
			}

			// Peripheral → hub.
			$src_deg = (int) $src['degree'];
			$tgt_deg = (int) $tgt['degree'];
			if ( ( $src_deg <= 2 && $tgt_deg >= 5 ) || ( $tgt_deg <= 2 && $src_deg >= 5 ) ) {
				$score += 1;
			}

			// Confidence weight.
			if ( 'INFERRED' === $edge['confidence'] ) {
				$score += 1;
			} elseif ( 'AMBIGUOUS' === $edge['confidence'] ) {
				$score += 2;
			}

			if ( $score > 0 ) {
				$edge['surprise_score'] = $score;
				$edge['source_label']   = $src['label'];
				$edge['target_label']   = $tgt['label'];
				$scored[]               = $edge;
			}
		}

		// Sort by surprise score descending.
		usort(
			$scored,
			function ( $a, $b ) {
				return $b['surprise_score'] - $a['surprise_score'];
			}
		);

		return array_slice( $scored, 0, max( 1, min( $top_n, 100 ) ) );
	}

	/**
	 * Identify knowledge gaps: orphan nodes (degree 0) and thin communities.
	 *
	 * @param int $graph_id Graph ID.
	 * @return array {
	 *     @type array $orphan_nodes     Nodes with degree 0.
	 *     @type array $thin_communities Communities with fewer than 3 nodes.
	 *     @type int   $orphan_count     Count of orphan nodes.
	 *     @type int   $thin_count       Count of thin communities.
	 * }
	 */
	public function knowledge_gaps( $graph_id = 1 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		// Get orphans (degree = 0).
		global $wpdb;
		$nodes_table = $db::nodes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, source_url FROM {$nodes_table} WHERE graph_id = %d AND degree = 0 ORDER BY label ASC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			),
			ARRAY_A
		);

		$orphan_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$nodes_table} WHERE graph_id = %d AND degree = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// Find thin communities.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thin = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT community_id, COUNT(*) AS size FROM {$nodes_table} WHERE graph_id = %d AND community_id > 0 GROUP BY community_id HAVING size < 3 ORDER BY size ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			),
			ARRAY_A
		);

		return array(
			'orphan_nodes'     => $orphans,
			'thin_communities' => $thin,
			'orphan_count'     => $orphan_count,
			'thin_count'       => count( $thin ),
		);
	}
}
