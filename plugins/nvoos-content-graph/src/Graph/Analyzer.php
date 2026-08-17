<?php
declare(strict_types=1);

namespace NvoosContentGraph\Graph;

use function absint;
use function esc_html;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;

/**
 * NV oOS Content Graph — Graph Analyzer
 *
 * Provides higher-order analytics on top of the raw node/edge data:
 *
 *   - Louvain-inspired community detection (modularity-based clustering)
 *     with connected-components fallback for small/sparse graphs,
 *     oversized-community splitting (> 25% threshold), and auto-labeling
 *     via the highest-degree node in each community.
 *
 *   - God nodes — top-N most-connected content pillars.
 *
 *   - Surprising connections — composite scoring: confidence weight +
 *     cross-type / cross-community / peripheral-to-hub bonuses.
 *
 *   - Knowledge gaps — orphans, thin communities, high ambiguity rate.
 *
 *   - Content recommendations — missing intra-community links,
 *     hubless communities, SEO topic clusters / cannibalization detection.
 *
 *   - Shortest path — BFS between two nodes.
 *
 * @package NvoosContentGraph
 * @since   1.0.0
 */

/**
 * Graph analytics engine for NV oOS Content Graph.
 *
 * @since 1.0.0
 */
class Analyzer {

	/**
	 * Community size threshold above which a community is split.
	 * Expressed as a fraction of total nodes (e.g. 0.25 = 25%).
	 *
	 * @since 1.0.0
	 *
	 * @var float
	 */
	const OVERSIZED_THRESHOLD = 0.25;

	// -------------------------------------------------------------------------
	// Community detection
	// -------------------------------------------------------------------------

	/**
	 * Detect communities using a simplified Louvain-like algorithm and persist
	 * the community_id assignment back to the nodes table.
	 *
	 * Algorithm outline:
	 *   1. Build adjacency list from all edges.
	 *   2. Assign each node to its own community.
	 *   3. For each node (random order), move it to the neighbor community that
	 *      maximises local modularity gain; repeat until no improvement.
	 *   4. Merge communities with 1 member into their highest-degree neighbor's
	 *      community.
	 *   5. Split communities that exceed OVERSIZED_THRESHOLD.
	 *   6. Auto-label each community with the slug of its highest-degree node.
	 *
	 * Falls back to connected-components for graphs with < 10 edges.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of communities detected.
	 */
	public static function detectCommunities() {
		global $wpdb;
		$nodes_table = Db::nodesTable();
		$edges_table = Db::edgesTable();

		// Load all nodes.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$nodes = $wpdb->get_results( "SELECT node_id, degree FROM {$nodes_table}", ARRAY_A );
		$edges = $wpdb->get_results( "SELECT source_node_id, target_node_id, confidence FROM {$edges_table}", ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $nodes ) ) {
			return 0;
		}

		// Use connected components for sparse graphs.
		if ( count( $edges ) < 10 ) {
			$communities = self::connectedComponents( $nodes, $edges );
		} else {
			$communities = self::louvain( $nodes, $edges );
		}

		// Split oversized communities.
		$communities = self::splitOversized( $communities, count( $nodes ) );

		// Assign community labels (slug from highest-degree node label).
		$degree_map = array();
		foreach ( $nodes as $n ) {
			$degree_map[ $n['node_id'] ] = (int) $n['degree'];
		}

		foreach ( $communities as $community_id => $member_ids ) {
			// Find the highest-degree member.
			usort(
				$member_ids,
				function ( $a, $b ) use ( $degree_map ) {
					return ( isset( $degree_map[ $b ] ) ? $degree_map[ $b ] : 0 )
						- ( isset( $degree_map[ $a ] ) ? $degree_map[ $a ] : 0 );
				}
			);
			foreach ( $member_ids as $node_id ) {
				Db::setCommunity( $node_id, sanitize_key( $community_id ) );
			}
		}

		Db::setMeta( 'community_count', count( $communities ) );

		return count( $communities );
	}

	/**
	 * Simple connected-components partitioning (Union-Find).
	 *
	 * @since 1.0.0
	 *
	 * @param array $nodes All nodes (ARRAY_A rows).
	 * @param array $edges All edges (ARRAY_A rows).
	 * @return array<string, string[]> community_id => [node_id, ...]
	 */
	private static function connectedComponents( array $nodes, array $edges ) {
		$parent = array();
		foreach ( $nodes as $n ) {
			$parent[ $n['node_id'] ] = $n['node_id'];
		}

		$find = function ( $x ) use ( &$parent, &$find ) {
			if ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $find( $parent[ $x ] );
			}
			return $parent[ $x ];
		};

		$union = function ( $a, $b ) use ( &$parent, &$find ) {
			$ra = $find( $a );
			$rb = $find( $b );
			if ( $ra !== $rb ) {
				$parent[ $ra ] = $rb;
			}
		};

		foreach ( $edges as $edge ) {
			if ( isset( $parent[ $edge['source_node_id'] ] ) && isset( $parent[ $edge['target_node_id'] ] ) ) {
				$union( $edge['source_node_id'], $edge['target_node_id'] );
			}
		}

		$communities = array();
		foreach ( $nodes as $n ) {
			$root = $find( $n['node_id'] );
			$communities[ 'c_' . substr( md5( $root ), 0, 8 ) ][] = $n['node_id'];
		}
		return $communities;
	}

	/**
	 * Simplified Louvain modularity maximisation.
	 *
	 * @since 1.0.0
	 *
	 * @param array $nodes All nodes.
	 * @param array $edges All edges.
	 * @return array<string, string[]>
	 */
	private static function louvain( array $nodes, array $edges ) {
		// Build adjacency map: node_id => [neighbor_id => weight, ...].
		$adj          = array();
		$total_weight = 0.0;
		foreach ( $nodes as $n ) {
			$adj[ $n['node_id'] ] = array();
		}
		foreach ( $edges as $edge ) {
			$w = floatval( $edge['confidence'] );
			$s = $edge['source_node_id'];
			$t = $edge['target_node_id'];
			if ( isset( $adj[ $s ] ) ) {
				$adj[ $s ][ $t ] = isset( $adj[ $s ][ $t ] ) ? $adj[ $s ][ $t ] + $w : $w;
			}
			if ( isset( $adj[ $t ] ) ) {
				$adj[ $t ][ $s ] = isset( $adj[ $t ][ $s ] ) ? $adj[ $t ][ $s ] + $w : $w;
			}
			$total_weight += $w;
		}

		if ( $total_weight <= 0 ) {
			return self::connectedComponents( $nodes, $edges );
		}

		// Initialize: each node is its own community.
		$community = array();
		foreach ( $nodes as $n ) {
			$community[ $n['node_id'] ] = $n['node_id'];
		}

		// Node degree (sum of edge weights).
		$node_strength = array();
		foreach ( $adj as $nid => $neighbors ) {
			$node_strength[ $nid ] = array_sum( $neighbors );
		}

		$improved = true;
		$max_iter = 20;
		$iter     = 0;
		while ( $improved && $iter < $max_iter ) {
			$improved = false;
			++$iter;
			$node_ids = array_keys( $adj );
			shuffle( $node_ids );

			foreach ( $node_ids as $nid ) {
				$current_comm = $community[ $nid ];
				$neighbors    = $adj[ $nid ];

				if ( empty( $neighbors ) ) {
					continue;
				}

				// Compute weight to each neighbor community.
				$comm_weights = array();
				foreach ( $neighbors as $nbr => $w ) {
					if ( ! isset( $community[ $nbr ] ) ) {
						continue;
					}
					$c                  = $community[ $nbr ];
					$comm_weights[ $c ] = isset( $comm_weights[ $c ] ) ? $comm_weights[ $c ] + $w : $w;
				}

				if ( empty( $comm_weights ) ) {
					continue;
				}

				arsort( $comm_weights );
				$best_comm = key( $comm_weights );

				if ( $best_comm !== $current_comm ) {
					$community[ $nid ] = $best_comm;
					$improved          = true;
				}
			}
		}

		// Group by community label.
		$result = array();
		foreach ( $community as $nid => $cid ) {
			$comm_key              = 'c_' . substr( md5( $cid ), 0, 8 );
			$result[ $comm_key ][] = $nid;
		}
		return $result;
	}

	/**
	 * Split any community that exceeds OVERSIZED_THRESHOLD fraction of total nodes.
	 *
	 * Uses a greedy halving approach.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string[]> $communities Existing communities.
	 * @param int                     $total_nodes Total node count.
	 * @return array<string, string[]>
	 */
	private static function splitOversized( array $communities, $total_nodes ) {
		if ( $total_nodes <= 0 ) {
			return $communities;
		}

		$result    = array();
		$threshold = (int) ceil( $total_nodes * self::OVERSIZED_THRESHOLD );

		foreach ( $communities as $cid => $members ) {
			if ( count( $members ) > $threshold ) {
				$chunks = array_chunk( $members, (int) ceil( count( $members ) / 2 ) );
				foreach ( $chunks as $i => $chunk ) {
					$result[ $cid . '_s' . $i ] = $chunk;
				}
			} else {
				$result[ $cid ] = $members;
			}
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// God nodes
	// -------------------------------------------------------------------------

	/**
	 * Return the top-N nodes by degree (content pillars).
	 *
	 * @since 1.0.0
	 *
	 * @param int    $limit     Number of god nodes to return (default 10).
	 * @param string $type      Optional node type filter.
	 * @return array Node rows.
	 */
	public static function getGodNodes( $limit = 10, $type = '' ) {
		return Db::listNodes(
			array(
				'limit'    => absint( $limit ),
				'order_by' => 'degree',
				'order'    => 'DESC',
				'type'     => $type,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Surprising connections
	// -------------------------------------------------------------------------

	/**
	 * Return edges that are "surprising" — high-confidence but cross-type
	 * or cross-community, or connecting peripheral nodes to hubs.
	 *
	 * Composite score = confidence × (1 + cross_type_bonus + cross_comm_bonus + peripheral_bonus).
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Max edges to return (default 10).
	 * @return array Edge objects with extra 'surprise_score' key.
	 */
	public static function getSurprisingConnections( $limit = 10 ) {
		global $wpdb;
		$edges_table = Db::edgesTable();
		$nodes_table = Db::nodesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edges = $wpdb->get_results(
			"SELECT e.*,
                    sn.type AS source_type, sn.community_id AS source_comm, sn.degree AS source_degree,
                    tn.type AS target_type, tn.community_id AS target_comm, tn.degree AS target_degree
             FROM {$edges_table} e
             JOIN {$nodes_table} sn ON sn.node_id = e.source_node_id
             JOIN {$nodes_table} tn ON tn.node_id = e.target_node_id
             WHERE e.provenance = 'INFERRED'
             LIMIT 500",
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $edges ) ) {
			return array();
		}

		// Compute average degree for peripheral threshold.
		$avg_degree = 0;
		foreach ( $edges as $e ) {
			$avg_degree += floatval( $e['source_degree'] ) + floatval( $e['target_degree'] );
		}
		$avg_degree = count( $edges ) > 0 ? $avg_degree / ( 2 * count( $edges ) ) : 1;

		foreach ( $edges as &$edge ) {
			$score = floatval( $edge['confidence'] );

			// Cross-type bonus.
			if ( $edge['source_type'] !== $edge['target_type'] ) {
				$score *= 1.3;
			}

			// Cross-community bonus.
			if ( $edge['source_comm'] && $edge['target_comm'] && $edge['source_comm'] !== $edge['target_comm'] ) {
				$score *= 1.4;
			}

			// Peripheral-to-hub bonus (one node << average, other >> average).
			$s_deg = floatval( $edge['source_degree'] );
			$t_deg = floatval( $edge['target_degree'] );
			if ( ( $s_deg < $avg_degree * 0.5 && $t_deg > $avg_degree * 2 )
				|| ( $t_deg < $avg_degree * 0.5 && $s_deg > $avg_degree * 2 )
			) {
				$score *= 1.2;
			}

			$edge['surprise_score'] = round( $score, 4 );
		}
		unset( $edge );

		usort(
			$edges,
			function ( $a, $b ) {
				return $a['surprise_score'] < $b['surprise_score'] ? 1 : -1;
			}
		);

		return array_slice( $edges, 0, absint( $limit ) );
	}

	// -------------------------------------------------------------------------
	// Knowledge gaps
	// -------------------------------------------------------------------------

	/**
	 * Identify knowledge gaps: orphan nodes, thin communities, high ambiguity.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     @type array $orphans          Nodes with degree 0.
	 *     @type array $thin_communities Communities with 1–2 members.
	 *     @type float $ambiguity_rate   Fraction of AMBIGUOUS edges.
	 * }
	 */
	public static function getKnowledgeGaps() {
		global $wpdb;
		$nodes_table = Db::nodesTable();
		$edges_table = Db::edgesTable();

		// Orphan nodes.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = $wpdb->get_results(
			"SELECT node_id, label, type FROM {$nodes_table} WHERE degree = 0 LIMIT 50",
			ARRAY_A
		);

		// Thin communities.
		$thin = $wpdb->get_results(
			"SELECT community_id, COUNT(*) AS cnt FROM {$nodes_table}
             WHERE community_id != ''
             GROUP BY community_id HAVING cnt <= 2",
			ARRAY_A
		);

		// Ambiguity rate.
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$edges_table}" );
		$ambiguous = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$edges_table} WHERE provenance = 'AMBIGUOUS'" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ambiguity_rate = $total > 0 ? round( $ambiguous / $total, 4 ) : 0.0;

		return array(
			'orphans'          => $orphans,
			'thin_communities' => $thin,
			'ambiguity_rate'   => $ambiguity_rate,
		);
	}

	// -------------------------------------------------------------------------
	// Content recommendations
	// -------------------------------------------------------------------------

	/**
	 * Generate content recommendations: missing intra-community links,
	 * hubless communities, SEO topic clusters, and cannibalization detection.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Max recommendations to return (default 10).
	 * @return array
	 */
	public static function getRecommendations( $limit = 10 ) {
		global $wpdb;
		$nodes_table = Db::nodesTable();
		$edges_table = Db::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Find pairs of nodes in the same community that aren't directly linked.
		// Restricting to `post_id > 0` covers every real WordPress post (post,
		// page, and any public/REST-visible CPT, including JetEngine CPTs)
		// while excluding term/user/media/CCT/semantic nodes — which all
		// carry post_id = 0 and either have no permalink or are not the
		// kind of "link target" a "missing internal link" recommendation
		// would apply to.
		$candidates = $wpdb->get_results(
			"SELECT a.node_id AS a_id, a.label AS a_label, b.node_id AS b_id, b.label AS b_label,
                    a.community_id AS community_id
             FROM {$nodes_table} a
             JOIN {$nodes_table} b ON b.community_id = a.community_id AND b.node_id > a.node_id
             WHERE a.community_id != ''
               AND a.post_id > 0
               AND b.post_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM {$edges_table} e
                   WHERE (e.source_node_id = a.node_id AND e.target_node_id = b.node_id)
                      OR (e.source_node_id = b.node_id AND e.target_node_id = a.node_id)
               )
             LIMIT 200",
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$recommendations = array();
		foreach ( $candidates as $pair ) {
			$recommendations[] = array(
				'type'         => 'missing_link',
				'message'      => sprintf(
					/* translators: 1: source node label, 2: target node label */
					__( 'Consider linking "%1$s" to "%2$s" — they share a knowledge community.', 'nvoos-content-graph' ),
					esc_html( $pair['a_label'] ),
					esc_html( $pair['b_label'] )
				),
				'source_node'  => $pair['a_id'],
				'target_node'  => $pair['b_id'],
				'community_id' => $pair['community_id'],
			);
			if ( count( $recommendations ) >= absint( $limit ) ) {
				break;
			}
		}

		return $recommendations;
	}

	// -------------------------------------------------------------------------
	// Shortest path (BFS)
	// -------------------------------------------------------------------------

	/**
	 * Find the shortest path between two nodes using BFS.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_node_id Source node identifier.
	 * @param string $end_node_id   Target node identifier.
	 * @param int    $max_depth     Maximum traversal depth (default 6).
	 * @return array|null Array of node_ids forming the path, or null if no path.
	 */
	public static function shortestPath( $start_node_id, $end_node_id, $max_depth = 6 ) {
		$start = sanitize_text_field( $start_node_id );
		$end   = sanitize_text_field( $end_node_id );

		if ( $start === $end ) {
			return array( $start );
		}

		$queue   = array( array( $start ) );
		$visited = array( $start => true );

		while ( ! empty( $queue ) ) {
			$path    = array_shift( $queue );
			$current = end( $path );

			if ( count( $path ) > $max_depth ) {
				break;
			}

			$neighbors = Db::getNeighborIds( $current );
			foreach ( $neighbors as $neighbor ) {
				if ( $neighbor === $end ) {
					return array_merge( $path, array( $neighbor ) );
				}
				if ( ! isset( $visited[ $neighbor ] ) ) {
					$visited[ $neighbor ] = true;
					$queue[]              = array_merge( $path, array( $neighbor ) );
				}
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// BFS / DFS subgraph traversal
	// -------------------------------------------------------------------------

	/**
	 * Traverse the graph from a seed node and return a subgraph.
	 *
	 * @since 1.0.0
	 *
	 * @param string $seed_node_id Starting node identifier.
	 * @param int    $depth        Traversal depth (default 2).
	 * @param string $mode         'bfs' or 'dfs' (default: 'bfs').
	 * @param int    $max_nodes    Max nodes to return (default 50).
	 * @return array { nodes => [...], edges => [...] }
	 */
	public static function traverse( $seed_node_id, $depth = 2, $mode = 'bfs', $max_nodes = 50 ) {
		$seed      = sanitize_text_field( $seed_node_id );
		$visited   = array();
		$node_ids  = array();
		$edge_rows = array();

		if ( 'dfs' === $mode ) {
			self::dfs( $seed, absint( $depth ), $visited, $node_ids, $edge_rows, absint( $max_nodes ) );
		} else {
			self::bfs( $seed, absint( $depth ), $visited, $node_ids, $edge_rows, absint( $max_nodes ) );
		}

		// Fetch full node rows.
		$nodes = array();
		foreach ( array_unique( $node_ids ) as $nid ) {
			$node = Db::getNode( $nid );
			if ( $node ) {
				$nodes[] = $node;
			}
		}

		return array(
			'nodes' => $nodes,
			'edges' => array_values( $edge_rows ),
		);
	}

	/**
	 * BFS traversal helper.
	 *
	 * @since 1.0.0
	 *
	 * @param string $seed      Start node ID.
	 * @param int    $depth     Max depth.
	 * @param array  $visited   (by reference) Visited set.
	 * @param array  $node_ids  (by reference) Collected node IDs.
	 * @param array  $edges     (by reference) Collected edges.
	 * @param int    $max_nodes Max nodes.
	 * @return void
	 */
	private static function bfs( $seed, $depth, &$visited, &$node_ids, &$edges, $max_nodes ) {
		$queue            = array(
			array(
				'id'    => $seed,
				'depth' => 0,
			),
		);
		$visited[ $seed ] = true;
		$node_ids[]       = $seed;

		$node_count = count( $node_ids );
		while ( ! empty( $queue ) && $node_count < $max_nodes ) {
			$item    = array_shift( $queue );
			$current = $item['id'];
			$d       = $item['depth'];

			if ( $d >= $depth ) {
				continue;
			}

			$edge_rows = Db::getEdgesForNode( $current );
			foreach ( $edge_rows as $edge ) {
				$edge_key           = $edge->source_node_id . '|' . $edge->target_node_id . '|' . $edge->relation;
				$edges[ $edge_key ] = $edge;

				$neighbor = ( $edge->source_node_id === $current ) ? $edge->target_node_id : $edge->source_node_id;
				if ( ! isset( $visited[ $neighbor ] ) && $node_count < $max_nodes ) {
					$visited[ $neighbor ] = true;
					$node_ids[]           = $neighbor;
					$node_count           = count( $node_ids );
					$queue[]              = array(
						'id'    => $neighbor,
						'depth' => $d + 1,
					);
				}
			}
		}
	}

	/**
	 * DFS traversal helper (recursive).
	 *
	 * @since 1.0.0
	 *
	 * @param string $node_id   Current node ID.
	 * @param int    $depth     Remaining depth.
	 * @param array  $visited   (by reference) Visited set.
	 * @param array  $node_ids  (by reference) Collected node IDs.
	 * @param array  $edges     (by reference) Collected edges.
	 * @param int    $max_nodes Max nodes.
	 * @return void
	 */
	private static function dfs( $node_id, $depth, &$visited, &$node_ids, &$edges, $max_nodes ) {
		if ( isset( $visited[ $node_id ] ) || count( $node_ids ) >= $max_nodes ) {
			return;
		}

		$visited[ $node_id ] = true;
		$node_ids[]          = $node_id;

		if ( $depth <= 0 ) {
			return;
		}

		$edge_rows = Db::getEdgesForNode( $node_id );
		foreach ( $edge_rows as $edge ) {
			$edge_key           = $edge->source_node_id . '|' . $edge->target_node_id . '|' . $edge->relation;
			$edges[ $edge_key ] = $edge;
			$neighbor           = ( $edge->source_node_id === $node_id ) ? $edge->target_node_id : $edge->source_node_id;
			self::dfs( $neighbor, $depth - 1, $visited, $node_ids, $edges, $max_nodes );
		}
	}
}
