<?php
/**
 * Louvain community detection for the knowledge graph.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_Cluster
 *
 * Implements the Louvain modularity-optimisation algorithm in pure PHP
 * to partition graph nodes into communities. Falls back to simple
 * connected-component detection for very small graphs (< 10 nodes).
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Cluster {

	/**
	 * Database table names.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tables = NV_oOS_Graphify_DB::get_table_names();
	}

	/**
	 * Run community detection for a graph and persist results.
	 *
	 * @param int $graph_id Graph identifier. Default 1.
	 * @return array Associative array of community_id => array of node_ids.
	 */
	public function detect_communities( $graph_id = 1 ) {
		global $wpdb;

		$graph_id = absint( $graph_id );

		// 1. Load nodes.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, degree FROM %i WHERE graph_id = %d',
				$this->tables['nodes'],
				$graph_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$nodes     = array();
		$nodes_map = array();
		foreach ( $rows as $row ) {
			$nodes[]                       = $row['node_id'];
			$nodes_map[ $row['node_id'] ] = $row;
		}

		// 2. Load edges and build adjacency list (undirected, weighted).
		$edge_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT source_node_id, target_node_id, confidence_score FROM %i WHERE graph_id = %d',
				$this->tables['edges'],
				$graph_id
			),
			ARRAY_A
		);

		$adjacency = array();
		foreach ( $nodes as $nid ) {
			$adjacency[ $nid ] = array();
		}

		foreach ( $edge_rows as $edge ) {
			$src    = $edge['source_node_id'];
			$tgt    = $edge['target_node_id'];
			$weight = floatval( $edge['confidence_score'] );
			if ( $weight <= 0 ) {
				$weight = 1.0;
			}

			if ( ! isset( $adjacency[ $src ] ) ) {
				$adjacency[ $src ] = array();
			}
			if ( ! isset( $adjacency[ $tgt ] ) ) {
				$adjacency[ $tgt ] = array();
			}

			// Accumulate weight for multi-edges.
			if ( ! isset( $adjacency[ $src ][ $tgt ] ) ) {
				$adjacency[ $src ][ $tgt ] = 0.0;
			}
			$adjacency[ $src ][ $tgt ] += $weight;

			if ( ! isset( $adjacency[ $tgt ][ $src ] ) ) {
				$adjacency[ $tgt ][ $src ] = 0.0;
			}
			$adjacency[ $tgt ][ $src ] += $weight;
		}

		// 3. Choose algorithm.
		if ( count( $nodes ) < 10 ) {
			$communities = $this->connected_components( $nodes, $adjacency );
		} else {
			$communities = $this->louvain( $nodes, $adjacency );
		}

		// 4. Split oversized communities.
		$communities = $this->split_large_communities( $communities, $adjacency );

		// 5. Label communities.
		$communities = $this->label_communities( $communities, $nodes_map );

		// 6. Persist community assignments.
		$community_index = 0;
		foreach ( $communities as &$community ) {
			$community['community_id'] = $community_index;

			foreach ( $community['members'] as $member_node_id ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$this->tables['nodes'],
					array( 'community_id' => $community_index ),
					array(
						'graph_id' => $graph_id,
						'node_id'  => $member_node_id,
					),
					array( '%d' ),
					array( '%d', '%s' )
				);
			}

			++$community_index;
		}
		unset( $community );

		// 7. Update graph meta.
		NV_oOS_Graphify_DB::update_graph_meta(
			$graph_id,
			array( 'community_count' => count( $communities ) )
		);

		/**
		 * Fires after community detection is complete.
		 *
		 * @param int   $graph_id    Graph identifier.
		 * @param array $communities Detected communities.
		 */
		do_action( 'nvoos_graphify_community_detected', $graph_id, $communities );

		return $communities;
	}

	/**
	 * Louvain modularity-optimisation algorithm.
	 *
	 * @param array $nodes     Array of node_id strings.
	 * @param array $adjacency Adjacency list: node_id => array( neighbour_id => weight ).
	 * @return array Communities as arrays with 'members' key.
	 */
	public function louvain( $nodes, $adjacency ) {

		// Total edge weight (each undirected edge counted once).
		$m = 0.0;
		foreach ( $adjacency as $nid => $neighbours ) {
			foreach ( $neighbours as $weight ) {
				$m += $weight;
			}
		}
		$m = $m / 2.0;

		if ( $m <= 0 ) {
			// No edges — each node is its own community.
			return $this->nodes_as_individual_communities( $nodes );
		}

		// Initialise: each node in its own community.
		$node_to_community = array();
		$community_nodes   = array();
		$idx               = 0;
		foreach ( $nodes as $nid ) {
			$node_to_community[ $nid ] = $idx;
			$community_nodes[ $idx ]   = array( $nid );
			++$idx;
		}

		// Pre-compute node degrees (sum of edge weights).
		$k = array();
		foreach ( $nodes as $nid ) {
			$k[ $nid ] = 0.0;
			if ( isset( $adjacency[ $nid ] ) ) {
				foreach ( $adjacency[ $nid ] as $weight ) {
					$k[ $nid ] += $weight;
				}
			}
		}

		// Phase 1: local moving.
		$improved = true;
		$max_iter = 50;
		$iter     = 0;

		while ( $improved && $iter < $max_iter ) {
			$improved = false;
			++$iter;

			foreach ( $nodes as $nid ) {
				$current_community = $node_to_community[ $nid ];

				// Compute k_i_in for current community and each neighbour's community.
				$neighbour_communities = array();
				if ( isset( $adjacency[ $nid ] ) ) {
					foreach ( $adjacency[ $nid ] as $neighbour_id => $weight ) {
						$nc = $node_to_community[ $neighbour_id ];
						if ( ! isset( $neighbour_communities[ $nc ] ) ) {
							$neighbour_communities[ $nc ] = 0.0;
						}
						$neighbour_communities[ $nc ] += $weight;
					}
				}

				// Compute sigma_tot for each candidate community.
				$best_community = $current_community;
				$best_gain      = 0.0;

				// Remove node from its community temporarily.
				$k_i_in_current = isset( $neighbour_communities[ $current_community ] )
					? $neighbour_communities[ $current_community ]
					: 0.0;

				// sigma_tot for current community (excluding node i).
				$sigma_tot_current = 0.0;
				foreach ( $community_nodes[ $current_community ] as $cn ) {
					if ( $cn === $nid ) {
						continue;
					}
					$sigma_tot_current += $k[ $cn ];
				}

				foreach ( $neighbour_communities as $candidate_community => $k_i_in_candidate ) {
					if ( $candidate_community === $current_community ) {
						continue;
					}

					// sigma_tot for candidate community.
					$sigma_tot_candidate = 0.0;
					if ( isset( $community_nodes[ $candidate_community ] ) ) {
						foreach ( $community_nodes[ $candidate_community ] as $cn ) {
							$sigma_tot_candidate += $k[ $cn ];
						}
					}

					// Modularity gain for moving node i from current to candidate.
					$delta_q = ( $k_i_in_candidate / ( 2.0 * $m ) )
						- ( $sigma_tot_candidate * $k[ $nid ] / ( 2.0 * $m * $m ) )
						- ( ( -$k_i_in_current ) / ( 2.0 * $m ) )
						+ ( $sigma_tot_current * $k[ $nid ] / ( 2.0 * $m * $m ) );

					if ( $delta_q > $best_gain ) {
						$best_gain      = $delta_q;
						$best_community = $candidate_community;
					}
				}

				// Move node if beneficial.
				if ( $best_community !== $current_community && $best_gain > 1e-10 ) {
					// Remove from current.
					$community_nodes[ $current_community ] = array_values(
						array_diff( $community_nodes[ $current_community ], array( $nid ) )
					);
					if ( empty( $community_nodes[ $current_community ] ) ) {
						unset( $community_nodes[ $current_community ] );
					}

					// Add to best.
					if ( ! isset( $community_nodes[ $best_community ] ) ) {
						$community_nodes[ $best_community ] = array();
					}
					$community_nodes[ $best_community ][] = $nid;
					$node_to_community[ $nid ]             = $best_community;
					$improved                              = true;
				}
			}
		}

		// Phase 2: community aggregation (one pass).
		// Build super-graph and recurse if communities changed.
		$final_communities = array();
		foreach ( $community_nodes as $members ) {
			if ( ! empty( $members ) ) {
				$final_communities[] = $members;
			}
		}

		// If Phase 1 produced fewer communities than nodes, try Phase 2 aggregation.
		if ( count( $final_communities ) < count( $nodes ) && count( $final_communities ) > 1 ) {
			$super_nodes     = array();
			$super_adjacency = array();
			$community_map   = array(); // original_community_idx => super_node_id.
			$super_to_members = array(); // super_node_id => array of original node_ids.

			$sidx = 0;
			foreach ( $final_communities as $members ) {
				$super_id                     = 'super_' . $sidx;
				$super_nodes[]                = $super_id;
				$super_adjacency[ $super_id ] = array();
				$super_to_members[ $super_id ] = $members;

				foreach ( $members as $nid ) {
					$community_map[ $nid ] = $super_id;
				}
				++$sidx;
			}

			// Build super-graph edges.
			foreach ( $adjacency as $src => $neighbours ) {
				if ( ! isset( $community_map[ $src ] ) ) {
					continue;
				}
				$src_super = $community_map[ $src ];
				foreach ( $neighbours as $tgt => $weight ) {
					if ( ! isset( $community_map[ $tgt ] ) ) {
						continue;
					}
					$tgt_super = $community_map[ $tgt ];
					if ( $src_super === $tgt_super ) {
						continue; // Skip intra-community edges.
					}
					if ( ! isset( $super_adjacency[ $src_super ][ $tgt_super ] ) ) {
						$super_adjacency[ $src_super ][ $tgt_super ] = 0.0;
					}
					$super_adjacency[ $src_super ][ $tgt_super ] += $weight;
				}
			}

			// Recurse on super-graph if it has edges.
			$has_super_edges = false;
			foreach ( $super_adjacency as $neighbours ) {
				if ( ! empty( $neighbours ) ) {
					$has_super_edges = true;
					break;
				}
			}

			if ( $has_super_edges && count( $super_nodes ) >= 10 ) {
				$super_communities = $this->louvain( $super_nodes, $super_adjacency );

				// Map super-communities back to original nodes.
				$result = array();
				foreach ( $super_communities as $sc ) {
					$merged_members = array();
					foreach ( $sc['members'] as $super_id ) {
						if ( isset( $super_to_members[ $super_id ] ) ) {
							$merged_members = array_merge( $merged_members, $super_to_members[ $super_id ] );
						}
					}
					if ( ! empty( $merged_members ) ) {
						$result[] = array( 'members' => $merged_members );
					}
				}
				return $result;
			}
		}

		// Format output.
		$result = array();
		foreach ( $final_communities as $members ) {
			$result[] = array( 'members' => $members );
		}

		return $result;
	}

	/**
	 * Find connected components using BFS.
	 *
	 * @param array $nodes     Array of node_id strings.
	 * @param array $adjacency Adjacency list: node_id => array( neighbour_id => weight ).
	 * @return array Array of component arrays with 'members' key.
	 */
	public function connected_components( $nodes, $adjacency ) {
		$visited    = array();
		$components = array();

		foreach ( $nodes as $nid ) {
			if ( isset( $visited[ $nid ] ) ) {
				continue;
			}

			$component = array();
			$queue     = array( $nid );
			$visited[ $nid ] = true;

			while ( ! empty( $queue ) ) {
				$current     = array_shift( $queue );
				$component[] = $current;

				if ( isset( $adjacency[ $current ] ) ) {
					foreach ( $adjacency[ $current ] as $neighbour_id => $weight ) {
						if ( ! isset( $visited[ $neighbour_id ] ) ) {
							$visited[ $neighbour_id ] = true;
							$queue[]                  = $neighbour_id;
						}
					}
				}
			}

			$components[] = array( 'members' => $component );
		}

		return $components;
	}

	/**
	 * Split communities that contain more than max_fraction of all nodes.
	 *
	 * Uses connected-component detection within the community's subgraph
	 * to break it into smaller groups.
	 *
	 * @param array $communities Array of communities with 'members' key.
	 * @param array $adjacency   Full graph adjacency list.
	 * @param float $max_fraction Maximum fraction of total nodes per community. Default 0.25.
	 * @return array Updated communities.
	 */
	public function split_large_communities( $communities, $adjacency, $max_fraction = 0.25 ) {
		$total_nodes = 0;
		foreach ( $communities as $community ) {
			$total_nodes += count( $community['members'] );
		}

		if ( $total_nodes < 1 ) {
			return $communities;
		}

		$max_size = (int) ceil( $total_nodes * $max_fraction );
		if ( $max_size < 2 ) {
			$max_size = 2;
		}

		$result = array();

		foreach ( $communities as $community ) {
			if ( count( $community['members'] ) <= $max_size ) {
				$result[] = $community;
				continue;
			}

			// Build subgraph adjacency for this community.
			$member_set   = array_flip( $community['members'] );
			$sub_adjacency = array();
			foreach ( $community['members'] as $nid ) {
				$sub_adjacency[ $nid ] = array();
				if ( isset( $adjacency[ $nid ] ) ) {
					foreach ( $adjacency[ $nid ] as $neighbour_id => $weight ) {
						if ( isset( $member_set[ $neighbour_id ] ) ) {
							$sub_adjacency[ $nid ][ $neighbour_id ] = $weight;
						}
					}
				}
			}

			$sub_components = $this->connected_components( $community['members'], $sub_adjacency );

			// If connected-component split didn't help, keep the original.
			if ( count( $sub_components ) <= 1 ) {
				$result[] = $community;
			} else {
				foreach ( $sub_components as $sc ) {
					$result[] = $sc;
				}
			}
		}

		return $result;
	}

	/**
	 * Assign labels to communities based on the highest-degree member.
	 *
	 * @param array $communities Array of communities with 'members' key.
	 * @param array $nodes_map   Associative array of node_id => node row data.
	 * @return array Communities with 'label' key added.
	 */
	public function label_communities( $communities, $nodes_map ) {
		foreach ( $communities as &$community ) {
			$best_label  = '';
			$best_degree = -1;

			foreach ( $community['members'] as $member_node_id ) {
				$degree = 0;
				if ( isset( $nodes_map[ $member_node_id ]['degree'] ) ) {
					$degree = (int) $nodes_map[ $member_node_id ]['degree'];
				}
				if ( $degree > $best_degree ) {
					$best_degree = $degree;
					$best_label  = isset( $nodes_map[ $member_node_id ]['label'] )
						? $nodes_map[ $member_node_id ]['label']
						: $member_node_id;
				}
			}

			$community['label'] = $best_label;
			$community['size']  = count( $community['members'] );
		}
		unset( $community );

		return $communities;
	}

	/**
	 * Create individual communities for nodes with no edges.
	 *
	 * @param array $nodes Array of node_id strings.
	 * @return array Array of single-member communities.
	 */
	private function nodes_as_individual_communities( $nodes ) {
		$communities = array();
		foreach ( $nodes as $nid ) {
			$communities[] = array( 'members' => array( $nid ) );
		}
		return $communities;
	}
}
