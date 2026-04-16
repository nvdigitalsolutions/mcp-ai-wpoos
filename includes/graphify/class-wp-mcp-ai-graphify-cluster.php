<?php
/**
 * Graphify Knowledge Graph — Community Detection (Louvain)
 *
 * PHP implementation of the Louvain modularity-based community detection
 * algorithm. Adapted from Graphify's cluster.py which uses Leiden via
 * graspologic. For WordPress-scale graphs (hundreds to low thousands of
 * nodes) Louvain in PHP is performant and dependency-free.
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
 * Assigns community IDs to graph nodes using the Louvain algorithm.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Cluster {

	/**
	 * Maximum fraction of total nodes a single community can hold
	 * before being split. Matches Graphify's 25% threshold.
	 *
	 * @var float
	 */
	const MAX_COMMUNITY_FRACTION = 0.25;

	/**
	 * Run community detection on a graph and persist results.
	 *
	 * @param int $graph_id Graph ID.
	 * @return array {
	 *     @type int   $community_count Number of communities detected.
	 *     @type array $communities     Map of community_id => array of node_ids.
	 * }
	 */
	public function cluster( $graph_id = 1 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		// Load all nodes and edges into memory.
		$nodes = $db::get_nodes(
			array(
				'graph_id' => $graph_id,
				'limit'    => 10000,
				'orderby'  => 'node_id',
				'order'    => 'ASC',
			)
		);

		$edges = $db::get_edges(
			array(
				'graph_id' => $graph_id,
				'limit'    => 50000,
			)
		);

		if ( empty( $nodes ) ) {
			return array(
				'community_count' => 0,
				'communities'     => array(),
			);
		}

		// Build adjacency list.
		$node_ids   = array_column( $nodes, 'node_id' );
		$adjacency  = $this->build_adjacency( $node_ids, $edges );

		// Run Louvain.
		$assignments = $this->louvain( $node_ids, $adjacency );

		// Split oversized communities.
		$assignments = $this->split_oversized( $assignments, $adjacency, count( $node_ids ) );

		// Renumber communities sequentially from 1.
		$assignments = $this->renumber( $assignments );

		// Persist community_id to database.
		$this->persist_communities( $assignments, $graph_id );

		// Build community map.
		$communities = array();
		foreach ( $assignments as $node_id => $community_id ) {
			if ( ! isset( $communities[ $community_id ] ) ) {
				$communities[ $community_id ] = array();
			}
			$communities[ $community_id ][] = $node_id;
		}

		$community_count = count( $communities );

		$db::update_graph_meta( $graph_id, array( 'community_count' => $community_count ) );

		/**
		 * Fires after community detection completes.
		 *
		 * @since 1.6.0
		 *
		 * @param int   $graph_id        Graph ID.
		 * @param int   $community_count Number of communities.
		 * @param array $communities     Map of community_id => node_ids.
		 */
		do_action( 'wp_mcp_ai_graphify_community_detected', $graph_id, $community_count, $communities );

		return array(
			'community_count' => $community_count,
			'communities'     => $communities,
		);
	}

	/**
	 * Build an adjacency list from edges.
	 *
	 * @param array $node_ids Array of node_id strings.
	 * @param array $edges    Array of edge rows.
	 * @return array Map of node_id => array of neighbor node_ids.
	 */
	protected function build_adjacency( $node_ids, $edges ) {
		$adj = array();
		foreach ( $node_ids as $nid ) {
			$adj[ $nid ] = array();
		}

		foreach ( $edges as $edge ) {
			$src = $edge['source_node_id'];
			$tgt = $edge['target_node_id'];

			if ( isset( $adj[ $src ] ) && isset( $adj[ $tgt ] ) ) {
				$adj[ $src ][] = $tgt;
				$adj[ $tgt ][] = $src;
			}
		}

		return $adj;
	}

	/**
	 * Louvain community detection.
	 *
	 * Simplified single-pass implementation suitable for WordPress-scale graphs.
	 * Each node starts in its own community. We iteratively move nodes to the
	 * community that gives the largest modularity gain until convergence.
	 *
	 * @param array $node_ids  Array of node_id strings.
	 * @param array $adjacency Adjacency list.
	 * @return array Map of node_id => community_id (int).
	 */
	protected function louvain( $node_ids, $adjacency ) {
		// Total number of edge endpoints (2 * edge_count for undirected).
		$m = 0;
		foreach ( $adjacency as $neighbors ) {
			$m += count( $neighbors );
		}

		// If no edges, each node is its own community.
		if ( 0 === $m ) {
			$assignments = array();
			$cid         = 1;
			foreach ( $node_ids as $nid ) {
				$assignments[ $nid ] = $cid;
				++$cid;
			}
			return $assignments;
		}

		// Initialize: each node in its own community.
		$community = array();
		$cid       = 0;
		foreach ( $node_ids as $nid ) {
			$community[ $nid ] = $cid;
			++$cid;
		}

		// Degree of each node.
		$degree = array();
		foreach ( $node_ids as $nid ) {
			$degree[ $nid ] = count( $adjacency[ $nid ] );
		}

		// Sum of degrees in each community.
		$sigma_tot = array();
		foreach ( $node_ids as $nid ) {
			$sigma_tot[ $community[ $nid ] ] = $degree[ $nid ];
		}

		$max_iterations = 50;
		$improved       = true;

		for ( $iter = 0; $iter < $max_iterations && $improved; $iter++ ) {
			$improved = false;

			foreach ( $node_ids as $nid ) {
				$current_comm = $community[ $nid ];
				$ki           = $degree[ $nid ];

				// Count edges from nid to each neighboring community.
				$neighbor_comms = array();
				foreach ( $adjacency[ $nid ] as $neighbor ) {
					$nc = $community[ $neighbor ];
					if ( ! isset( $neighbor_comms[ $nc ] ) ) {
						$neighbor_comms[ $nc ] = 0;
					}
					++$neighbor_comms[ $nc ];
				}

				// Remove node from current community.
				$sigma_tot[ $current_comm ] -= $ki;

				$best_comm = $current_comm;
				$best_gain = 0.0;

				// Evaluate moving to each neighboring community.
				foreach ( $neighbor_comms as $target_comm => $ki_in ) {
					$st   = isset( $sigma_tot[ $target_comm ] ) ? $sigma_tot[ $target_comm ] : 0;
					$gain = ( $ki_in / $m ) - ( $st * $ki ) / ( $m * $m );

					if ( $gain > $best_gain ) {
						$best_gain = $gain;
						$best_comm = $target_comm;
					}
				}

				// Move node to best community.
				$community[ $nid ] = $best_comm;
				$sigma_tot[ $best_comm ] = ( isset( $sigma_tot[ $best_comm ] ) ? $sigma_tot[ $best_comm ] : 0 ) + $ki;

				if ( $best_comm !== $current_comm ) {
					$improved = true;
				}
			}
		}

		return $community;
	}

	/**
	 * Split communities that exceed the maximum fraction of total nodes.
	 *
	 * Uses connected components within the oversized community as sub-communities.
	 *
	 * @param array $assignments Node → community map.
	 * @param array $adjacency   Adjacency list.
	 * @param int   $total_nodes Total number of nodes.
	 * @return array Updated assignments.
	 */
	protected function split_oversized( $assignments, $adjacency, $total_nodes ) {
		$max_size = (int) ceil( $total_nodes * self::MAX_COMMUNITY_FRACTION );

		if ( $max_size < 2 ) {
			return $assignments;
		}

		// Group nodes by community.
		$groups = array();
		foreach ( $assignments as $nid => $cid ) {
			if ( ! isset( $groups[ $cid ] ) ) {
				$groups[ $cid ] = array();
			}
			$groups[ $cid ][] = $nid;
		}

		$next_cid = max( $assignments ) + 1;

		foreach ( $groups as $cid => $members ) {
			if ( count( $members ) <= $max_size ) {
				continue;
			}

			// Find connected components within this community.
			$sub_adj = array();
			foreach ( $members as $nid ) {
				$sub_adj[ $nid ] = array();
				foreach ( $adjacency[ $nid ] as $neighbor ) {
					if ( isset( $assignments[ $neighbor ] ) && $assignments[ $neighbor ] === $cid ) {
						$sub_adj[ $nid ][] = $neighbor;
					}
				}
			}

			$visited    = array();
			$components = array();

			foreach ( $members as $nid ) {
				if ( isset( $visited[ $nid ] ) ) {
					continue;
				}

				// BFS for connected component.
				$component = array();
				$queue     = array( $nid );

				while ( ! empty( $queue ) ) {
					$current = array_shift( $queue );
					if ( isset( $visited[ $current ] ) ) {
						continue;
					}
					$visited[ $current ] = true;
					$component[]         = $current;

					foreach ( $sub_adj[ $current ] as $neighbor ) {
						if ( ! isset( $visited[ $neighbor ] ) ) {
							$queue[] = $neighbor;
						}
					}
				}

				$components[] = $component;
			}

			// Reassign: first component keeps original ID, rest get new IDs.
			$first = true;
			foreach ( $components as $component ) {
				if ( $first ) {
					$first = false;
					continue;
				}

				foreach ( $component as $nid ) {
					$assignments[ $nid ] = $next_cid;
				}
				++$next_cid;
			}
		}

		return $assignments;
	}

	/**
	 * Renumber community IDs sequentially starting from 1.
	 *
	 * @param array $assignments Node → community map.
	 * @return array Renumbered assignments.
	 */
	protected function renumber( $assignments ) {
		$mapping   = array();
		$next_id   = 1;
		$renumbered = array();

		foreach ( $assignments as $nid => $cid ) {
			if ( ! isset( $mapping[ $cid ] ) ) {
				$mapping[ $cid ] = $next_id;
				++$next_id;
			}
			$renumbered[ $nid ] = $mapping[ $cid ];
		}

		return $renumbered;
	}

	/**
	 * Persist community assignments to the nodes table.
	 *
	 * @param array $assignments Node_id → community_id map.
	 * @param int   $graph_id    Graph ID.
	 * @return void
	 */
	protected function persist_communities( $assignments, $graph_id ) {
		global $wpdb;

		$table = WP_MCP_AI_Graphify_Database::nodes_table();

		foreach ( $assignments as $node_id => $community_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array( 'community_id' => $community_id ),
				array(
					'graph_id' => $graph_id,
					'node_id'  => $node_id,
				),
				array( '%d' ),
				array( '%d', '%s' )
			);
		}
	}
}
