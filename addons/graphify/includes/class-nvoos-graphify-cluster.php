<?php
/**
 * NV oOS Graphify Addon — Louvain Community Detection
 *
 * Implements the Louvain modularity-based community detection algorithm
 * for knowledge graph clustering. Assigns community labels to nodes and
 * updates graph metadata with community statistics.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Louvain community detection for knowledge graph nodes.
 *
 * Detects communities using a two-phase modularity optimisation loop:
 * Phase 1 greedily moves individual nodes between communities to maximise
 * modularity gain; Phase 2 contracts the graph so that each community
 * becomes a single super-node. The phases repeat until convergence.
 *
 * For very small graphs (< 20 nodes) a simpler connected-components
 * approach is used instead. Oversized communities (> 25 % of the graph)
 * are split by re-running detection on their subgraph.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Cluster {

	/**
	 * Minimum number of nodes required to use the full Louvain algorithm.
	 *
	 * Graphs below this threshold fall back to connected-components.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const LOUVAIN_THRESHOLD = 20;

	/**
	 * Maximum fraction of total nodes a single community may contain
	 * before it is split.
	 *
	 * @since 0.2.0
	 * @var float
	 */
	const MAX_COMMUNITY_FRACTION = 0.25;

	/**
	 * Graph identifier.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private $graph_id;

	/**
	 * Loaded node rows keyed by node_id.
	 *
	 * @since 0.2.0
	 * @var array
	 */
	private $nodes = array();

	/**
	 * Loaded edge rows.
	 *
	 * @since 0.2.0
	 * @var array
	 */
	private $edges = array();

	/**
	 * Adjacency list keyed by node_id → array of neighbour node_ids.
	 *
	 * @since 0.2.0
	 * @var array
	 */
	private $adjacency = array();

	/**
	 * Edge weight between pairs, keyed "nodeA|nodeB" (sorted).
	 *
	 * @since 0.2.0
	 * @var array
	 */
	private $edge_weights = array();

	/**
	 * Current community assignment keyed by node_id.
	 *
	 * @since 0.2.0
	 * @var array
	 */
	private $community_map = array();

	/**
	 * Total edge weight in the graph (sum of all weights, each edge counted once).
	 *
	 * @since 0.2.0
	 * @var float
	 */
	private $total_weight = 0.0;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param int $graph_id Graph identifier.
	 */
	public function __construct( $graph_id ) {
		$this->graph_id = (int) $graph_id;
	}

	/**
	 * Run community detection on the graph.
	 *
	 * Loads nodes and edges from the database, executes the Louvain
	 * algorithm (or connected-components for small graphs), splits
	 * oversized communities, persists results, and fires an action hook.
	 *
	 * @since 0.2.0
	 *
	 * @return array {
	 *     Community detection statistics.
	 *
	 *     @type int    $community_count Number of communities found.
	 *     @type int    $node_count      Total nodes processed.
	 *     @type float  $modularity      Final modularity score (0 for connected-components).
	 *     @type string $algorithm       Algorithm used: "louvain" or "connected_components".
	 *     @type array  $communities     Community summary list.
	 * }
	 */
	public function detect_communities() {
		$this->load_graph_data();

		$node_count = count( $this->nodes );

		if ( 0 === $node_count ) {
			return array(
				'community_count' => 0,
				'node_count'      => 0,
				'modularity'      => 0.0,
				'algorithm'       => 'none',
				'communities'     => array(),
			);
		}

		$this->build_adjacency();

		if ( $node_count < self::LOUVAIN_THRESHOLD ) {
			$this->detect_connected_components();
			$algorithm  = 'connected_components';
			$modularity = 0.0;
		} else {
			$modularity = $this->run_louvain();
			$algorithm  = 'louvain';
		}

		$this->split_oversized_communities();

		$this->persist_communities();

		$communities = $this->build_community_stats();

		$stats = array(
			'community_count' => count( $communities ),
			'node_count'      => $node_count,
			'modularity'      => round( $modularity, 6 ),
			'algorithm'       => $algorithm,
			'communities'     => $communities,
		);

		/**
		 * Fires after community detection has completed and results are persisted.
		 *
		 * @since 0.2.0
		 *
		 * @param array $stats    Detection statistics.
		 * @param int   $graph_id Graph identifier.
		 */
		do_action( 'nvoos_graphify_community_detected', $stats, $this->graph_id );

		return $stats;
	}

	/**
	 * Return all detected communities with summary information.
	 *
	 * @since 0.2.0
	 *
	 * @return array[] {
	 *     List of community summaries.
	 *
	 *     @type int    $id       Community identifier.
	 *     @type string $label    Human-readable label (highest-degree node label).
	 *     @type int    $size     Number of member nodes.
	 *     @type float  $cohesion Ratio of internal edges to maximum possible internal edges.
	 * }
	 */
	public function get_communities() {
		if ( empty( $this->community_map ) ) {
			$this->load_graph_data();
			$this->build_adjacency();
			$this->load_community_assignments();
		}

		return $this->build_community_stats();
	}

	/**
	 * Return nodes belonging to a specific community.
	 *
	 * @since 0.2.0
	 *
	 * @param int $community_id Community identifier.
	 * @return array[] {
	 *     List of node data arrays.
	 *
	 *     @type string $node_id   Node identifier.
	 *     @type string $label     Node label.
	 *     @type string $node_type Node type.
	 *     @type int    $degree    Node degree.
	 * }
	 */
	public function get_community_members( $community_id ) {
		$community_id = (int) $community_id;

		global $wpdb;
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, degree FROM {$nodes_table} WHERE graph_id = %d AND community_id = %d ORDER BY degree DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->graph_id,
				$community_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$members = array();
		foreach ( $rows as $row ) {
			$members[] = array(
				'node_id'   => sanitize_text_field( $row['node_id'] ),
				'label'     => sanitize_text_field( $row['label'] ),
				'node_type' => sanitize_text_field( $row['node_type'] ),
				'degree'    => (int) $row['degree'],
			);
		}

		return $members;
	}

	// ------------------------------------------------------------------
	// Data loading.
	// ------------------------------------------------------------------

	/**
	 * Load nodes and edges for the current graph from the database.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function load_graph_data() {
		global $wpdb;

		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, node_id, label, node_type, degree, community_id FROM {$nodes_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->graph_id
			),
			ARRAY_A
		);

		$this->nodes = array();
		if ( ! empty( $node_rows ) ) {
			foreach ( $node_rows as $row ) {
				$nid                 = sanitize_text_field( $row['node_id'] );
				$this->nodes[ $nid ] = array(
					'db_id'        => (int) $row['id'],
					'node_id'      => $nid,
					'label'        => sanitize_text_field( $row['label'] ),
					'node_type'    => sanitize_text_field( $row['node_type'] ),
					'degree'       => (int) $row['degree'],
					'community_id' => (int) $row['community_id'],
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edge_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id, confidence_score FROM {$edges_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->graph_id
			),
			ARRAY_A
		);

		$this->edges = array();
		if ( ! empty( $edge_rows ) ) {
			foreach ( $edge_rows as $row ) {
				$this->edges[] = array(
					'source' => sanitize_text_field( $row['source_node_id'] ),
					'target' => sanitize_text_field( $row['target_node_id'] ),
					'weight' => ! empty( $row['confidence_score'] ) ? (float) $row['confidence_score'] : 1.0,
				);
			}
		}
	}

	/**
	 * Build the adjacency list and edge-weight index from loaded edges.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function build_adjacency() {
		$this->adjacency    = array();
		$this->edge_weights = array();
		$this->total_weight = 0.0;

		foreach ( array_keys( $this->nodes ) as $nid ) {
			$this->adjacency[ $nid ] = array();
		}

		foreach ( $this->edges as $edge ) {
			$src = $edge['source'];
			$tgt = $edge['target'];

			if ( ! isset( $this->nodes[ $src ] ) || ! isset( $this->nodes[ $tgt ] ) ) {
				continue;
			}

			$this->adjacency[ $src ][] = $tgt;
			$this->adjacency[ $tgt ][] = $src;

			$key = $this->edge_key( $src, $tgt );
			if ( ! isset( $this->edge_weights[ $key ] ) ) {
				$this->edge_weights[ $key ] = 0.0;
			}
			$this->edge_weights[ $key ] += $edge['weight'];

			$this->total_weight += $edge['weight'];
		}
	}

	/**
	 * Load existing community assignments from the nodes table into memory.
	 *
	 * Used by get_communities() when called without prior detect_communities().
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function load_community_assignments() {
		$this->community_map = array();
		foreach ( $this->nodes as $nid => $node ) {
			$this->community_map[ $nid ] = $node['community_id'];
		}
	}

	// ------------------------------------------------------------------
	// Louvain algorithm.
	// ------------------------------------------------------------------

	/**
	 * Execute the full Louvain algorithm.
	 *
	 * The algorithm alternates between a local node-moving phase and a
	 * graph-contraction phase until no further modularity gain is possible.
	 *
	 * @since 0.2.0
	 *
	 * @return float Final modularity score.
	 */
	private function run_louvain() {
		$node_ids = array_keys( $this->nodes );

		// Initialise: each node in its own community.
		$this->community_map = array();
		$community_counter   = 0;
		foreach ( $node_ids as $nid ) {
			$this->community_map[ $nid ] = $community_counter;
			++$community_counter;
		}

		if ( $this->total_weight <= 0.0 ) {
			return 0.0;
		}

		// Iterative Louvain: Phase 1 + Phase 2 loop.
		$current_adjacency    = $this->adjacency;
		$current_edge_weights = $this->edge_weights;
		$current_nodes        = $node_ids;
		$current_total_weight = $this->total_weight;

		// Track the mapping from current super-nodes back to original node sets.
		$super_to_originals = array();
		foreach ( $node_ids as $nid ) {
			$super_to_originals[ $nid ] = array( $nid );
		}

		$max_outer_iterations = 20;

		for ( $outer = 0; $outer < $max_outer_iterations; $outer++ ) {
			// Phase 1: Local node moving.
			$local_communities = array();
			$counter           = 0;
			foreach ( $current_nodes as $nid ) {
				$local_communities[ $nid ] = $counter;
				++$counter;
			}

			$improved = $this->louvain_phase1(
				$current_nodes,
				$current_adjacency,
				$current_edge_weights,
				$current_total_weight,
				$local_communities
			);

			if ( ! $improved ) {
				break;
			}

			// Map local communities back to original node assignments.
			$this->apply_super_mapping( $local_communities, $super_to_originals );

			// Phase 2: Contract the graph.
			$contraction = $this->louvain_phase2(
				$current_nodes,
				$current_adjacency,
				$current_edge_weights,
				$local_communities,
				$super_to_originals
			);

			$current_nodes        = $contraction['nodes'];
			$current_adjacency    = $contraction['adjacency'];
			$current_edge_weights = $contraction['edge_weights'];
			$super_to_originals   = $contraction['super_to_originals'];

			// Stop if the contracted graph has no further structure.
			if ( count( $current_nodes ) <= 1 ) {
				break;
			}
		}

		return $this->calculate_modularity();
	}

	/**
	 * Louvain Phase 1 — greedily move nodes between communities.
	 *
	 * Iterates over nodes in random order, evaluating the modularity gain
	 * of moving each node to every neighbouring community. The best
	 * positive move is accepted. Repeats until a full pass produces no
	 * improvements.
	 *
	 * @since 0.2.0
	 *
	 * @param array $nodes        List of node identifiers.
	 * @param array $adjacency    Adjacency list (node → neighbours).
	 * @param array $edge_weights Edge weight index (key → weight).
	 * @param float $total_weight Sum of all edge weights.
	 * @param array $communities  Community assignment map (node → community), modified in place.
	 * @return bool Whether any move was made.
	 */
	private function louvain_phase1( $nodes, $adjacency, $edge_weights, $total_weight, &$communities ) {
		$any_moved        = false;
		$max_passes       = 50;
		$two_total_weight = 2.0 * $total_weight;

		if ( $two_total_weight <= 0.0 ) {
			return false;
		}

		// Pre-compute weighted degree for each node.
		$weighted_degree = array();
		foreach ( $nodes as $nid ) {
			$weighted_degree[ $nid ] = 0.0;
			if ( ! empty( $adjacency[ $nid ] ) ) {
				foreach ( $adjacency[ $nid ] as $neighbour ) {
					$key                      = $this->edge_key( $nid, $neighbour );
					$w                        = isset( $edge_weights[ $key ] ) ? $edge_weights[ $key ] : 0.0;
					$weighted_degree[ $nid ] += $w;
				}
			}
		}

		// Pre-compute sum_tot: total weighted degree per community.
		$sum_tot = array();
		foreach ( $nodes as $nid ) {
			$cid = $communities[ $nid ];
			if ( ! isset( $sum_tot[ $cid ] ) ) {
				$sum_tot[ $cid ] = 0.0;
			}
			$sum_tot[ $cid ] += $weighted_degree[ $nid ];
		}

		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$moved      = false;
			$node_order = $nodes;
			shuffle( $node_order );

			foreach ( $node_order as $nid ) {
				$current_community = $communities[ $nid ];
				$ki                = $weighted_degree[ $nid ];

				// Compute weights to each neighbouring community.
				$community_weights           = array();
				$weight_to_current_community = 0.0;

				if ( ! empty( $adjacency[ $nid ] ) ) {
					foreach ( $adjacency[ $nid ] as $neighbour ) {
						$nc  = $communities[ $neighbour ];
						$key = $this->edge_key( $nid, $neighbour );
						$w   = isset( $edge_weights[ $key ] ) ? $edge_weights[ $key ] : 0.0;

						if ( ! isset( $community_weights[ $nc ] ) ) {
							$community_weights[ $nc ] = 0.0;
						}
						$community_weights[ $nc ] += $w;

						if ( $nc === $current_community ) {
							$weight_to_current_community += $w;
						}
					}
				}

				// sum_tot for the current community excludes node i itself.
				$own_sum_tot = ( isset( $sum_tot[ $current_community ] ) ? $sum_tot[ $current_community ] : 0.0 ) - $ki;

				// Evaluate best community to move into.
				$best_gain      = 0.0;
				$best_community = $current_community;

				// Cost of removing node from its current community.
				$remove_cost = $weight_to_current_community - ( $ki * $own_sum_tot ) / $two_total_weight;

				foreach ( $community_weights as $candidate => $k_i_in ) {
					if ( $candidate === $current_community ) {
						continue;
					}

					$s_tot = isset( $sum_tot[ $candidate ] ) ? $sum_tot[ $candidate ] : 0.0;
					$gain  = ( $k_i_in - ( $ki * $s_tot ) / $two_total_weight ) - $remove_cost;

					if ( $gain > $best_gain ) {
						$best_gain      = $gain;
						$best_community = $candidate;
					}
				}

				if ( $best_community !== $current_community ) {
					// Update sum_tot incrementally.
					$sum_tot[ $current_community ] -= $ki;
					if ( ! isset( $sum_tot[ $best_community ] ) ) {
						$sum_tot[ $best_community ] = 0.0;
					}
					$sum_tot[ $best_community ] += $ki;

					$communities[ $nid ] = $best_community;
					$moved               = true;
					$any_moved           = true;
				}
			}

			if ( ! $moved ) {
				break;
			}
		}

		return $any_moved;
	}

	/**
	 * Louvain Phase 2 — contract the graph by merging communities into super-nodes.
	 *
	 * Each community becomes a single node in the new graph. Edge weights
	 * between communities are summed. Self-loops represent intra-community
	 * edges.
	 *
	 * @since 0.2.0
	 *
	 * @param array $nodes              List of node identifiers.
	 * @param array $adjacency          Adjacency list.
	 * @param array $edge_weights       Edge weight index.
	 * @param array $communities        Community assignment map.
	 * @param array $super_to_originals Mapping of super-nodes to original node sets.
	 * @return array {
	 *     Contracted graph data.
	 *
	 *     @type array $nodes              New super-node identifiers.
	 *     @type array $adjacency          New adjacency list.
	 *     @type array $edge_weights       New edge weight index.
	 *     @type array $super_to_originals Updated super-node to originals mapping.
	 * }
	 */
	private function louvain_phase2( $nodes, $adjacency, $edge_weights, $communities, $super_to_originals ) {
		// Gather unique community IDs.
		$unique_communities = array_values( array_unique( $communities ) );

		// Map community ID → super-node label.
		$community_label = array();
		$counter         = 0;
		foreach ( $unique_communities as $cid ) {
			$community_label[ $cid ] = 'super_' . $counter;
			++$counter;
		}

		// Build new super_to_originals.
		$new_super_to_originals = array();
		foreach ( $community_label as $cid => $label ) {
			$new_super_to_originals[ $label ] = array();
		}
		foreach ( $nodes as $nid ) {
			$label   = $community_label[ $communities[ $nid ] ];
			$members = isset( $super_to_originals[ $nid ] ) ? $super_to_originals[ $nid ] : array( $nid );
			foreach ( $members as $original ) {
				$new_super_to_originals[ $label ][] = $original;
			}
		}

		// Build contracted edge weights.
		$new_edge_weights = array();
		$new_adjacency    = array();
		$new_nodes        = array_values( $community_label );

		foreach ( $new_nodes as $label ) {
			$new_adjacency[ $label ] = array();
		}

		$visited_pairs = array();
		foreach ( $nodes as $nid ) {
			$src_label = $community_label[ $communities[ $nid ] ];
			if ( empty( $adjacency[ $nid ] ) ) {
				continue;
			}
			foreach ( $adjacency[ $nid ] as $neighbour ) {
				$tgt_label = $community_label[ $communities[ $neighbour ] ];
				if ( $src_label === $tgt_label ) {
					continue; // Skip self-loops in contracted graph.
				}
				$key = $this->edge_key( $nid, $neighbour );
				$w   = isset( $edge_weights[ $key ] ) ? $edge_weights[ $key ] : 0.0;

				$new_key = $this->edge_key( $src_label, $tgt_label );
				if ( ! isset( $new_edge_weights[ $new_key ] ) ) {
					$new_edge_weights[ $new_key ] = 0.0;
				}
				// Halve during accumulation: each undirected edge is visited twice.
				$new_edge_weights[ $new_key ] += $w / 2.0;

				if ( ! isset( $visited_pairs[ $new_key ] ) ) {
					$visited_pairs[ $new_key ]     = true;
					$new_adjacency[ $src_label ][] = $tgt_label;
					$new_adjacency[ $tgt_label ][] = $src_label;
				}
			}
		}

		return array(
			'nodes'              => $new_nodes,
			'adjacency'          => $new_adjacency,
			'edge_weights'       => $new_edge_weights,
			'super_to_originals' => $new_super_to_originals,
		);
	}

	/**
	 * Map Phase 1 local community assignments back through the super-node
	 * expansion to update the canonical community_map.
	 *
	 * @since 0.2.0
	 *
	 * @param array $local_communities  Phase 1 community assignments (super-node → community).
	 * @param array $super_to_originals Super-node to original nodes mapping.
	 * @return void
	 */
	private function apply_super_mapping( $local_communities, $super_to_originals ) {
		foreach ( $local_communities as $super_node => $community ) {
			if ( ! isset( $super_to_originals[ $super_node ] ) ) {
				continue;
			}
			foreach ( $super_to_originals[ $super_node ] as $original ) {
				$this->community_map[ $original ] = $community;
			}
		}
	}

	// ------------------------------------------------------------------
	// Connected-components fallback.
	// ------------------------------------------------------------------

	/**
	 * Detect communities using simple connected-components (BFS).
	 *
	 * Used as a lightweight fallback for graphs with fewer than
	 * LOUVAIN_THRESHOLD nodes.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function detect_connected_components() {
		$this->community_map = array();
		$visited             = array();
		$community_counter   = 0;

		foreach ( array_keys( $this->nodes ) as $nid ) {
			if ( isset( $visited[ $nid ] ) ) {
				continue;
			}

			$queue = array( $nid );
			while ( ! empty( $queue ) ) {
				$current = array_shift( $queue );
				if ( isset( $visited[ $current ] ) ) {
					continue;
				}
				$visited[ $current ]             = true;
				$this->community_map[ $current ] = $community_counter;

				if ( ! empty( $this->adjacency[ $current ] ) ) {
					foreach ( $this->adjacency[ $current ] as $neighbour ) {
						if ( ! isset( $visited[ $neighbour ] ) ) {
							$queue[] = $neighbour;
						}
					}
				}
			}

			++$community_counter;
		}
	}

	// ------------------------------------------------------------------
	// Oversized community splitting.
	// ------------------------------------------------------------------

	/**
	 * Split communities that exceed MAX_COMMUNITY_FRACTION of all nodes.
	 *
	 * Re-runs the Louvain algorithm on the subgraph of the oversized
	 * community and reassigns those nodes to newly created sub-communities.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function split_oversized_communities() {
		$total_nodes = count( $this->nodes );
		if ( 0 === $total_nodes ) {
			return;
		}

		$max_size = (int) ceil( $total_nodes * self::MAX_COMMUNITY_FRACTION );

		// Determine the next available community ID.
		$next_community = 0;
		foreach ( $this->community_map as $cid ) {
			if ( $cid >= $next_community ) {
				$next_community = $cid + 1;
			}
		}

		// Group nodes by community.
		$groups = array();
		foreach ( $this->community_map as $nid => $cid ) {
			if ( ! isset( $groups[ $cid ] ) ) {
				$groups[ $cid ] = array();
			}
			$groups[ $cid ][] = $nid;
		}

		foreach ( $groups as $cid => $members ) {
			if ( count( $members ) <= $max_size ) {
				continue;
			}

			$sub_result = $this->split_subgraph( $members );

			foreach ( $sub_result as $nid => $sub_community ) {
				$this->community_map[ $nid ] = $next_community + $sub_community;
			}

			$sub_max = 0;
			foreach ( $sub_result as $sc ) {
				if ( $sc > $sub_max ) {
					$sub_max = $sc;
				}
			}
			$next_community += $sub_max + 1;
		}
	}

	/**
	 * Run a small Louvain pass on a subgraph to split one community.
	 *
	 * @since 0.2.0
	 *
	 * @param array $member_ids Node IDs that belong to the oversized community.
	 * @return array Map of node_id → sub-community index.
	 */
	private function split_subgraph( $member_ids ) {
		$member_set = array_flip( $member_ids );

		// Build sub-adjacency.
		$sub_adjacency    = array();
		$sub_edge_weights = array();
		$sub_total_weight = 0.0;

		foreach ( $member_ids as $nid ) {
			$sub_adjacency[ $nid ] = array();
		}

		foreach ( $member_ids as $nid ) {
			if ( empty( $this->adjacency[ $nid ] ) ) {
				continue;
			}
			foreach ( $this->adjacency[ $nid ] as $neighbour ) {
				if ( ! isset( $member_set[ $neighbour ] ) ) {
					continue;
				}
				$key = $this->edge_key( $nid, $neighbour );
				$w   = isset( $this->edge_weights[ $key ] ) ? $this->edge_weights[ $key ] : 0.0;

				$sub_adjacency[ $nid ][] = $neighbour;

				if ( ! isset( $sub_edge_weights[ $key ] ) ) {
					$sub_edge_weights[ $key ] = $w;
					$sub_total_weight        += $w;
				}
			}
		}

		// Initialise each member as its own community.
		$sub_communities = array();
		$counter         = 0;
		foreach ( $member_ids as $nid ) {
			$sub_communities[ $nid ] = $counter;
			++$counter;
		}

		$this->louvain_phase1(
			$member_ids,
			$sub_adjacency,
			$sub_edge_weights,
			$sub_total_weight,
			$sub_communities
		);

		// Renumber communities sequentially from 0.
		$renumber = array();
		$index    = 0;
		$result   = array();
		foreach ( $sub_communities as $nid => $cid ) {
			if ( ! isset( $renumber[ $cid ] ) ) {
				$renumber[ $cid ] = $index;
				++$index;
			}
			$result[ $nid ] = $renumber[ $cid ];
		}

		return $result;
	}

	// ------------------------------------------------------------------
	// Modularity calculation.
	// ------------------------------------------------------------------

	/**
	 * Calculate the modularity Q of the current community assignment.
	 *
	 * Q = Σ [ e_ii − a_i² ] where e_ii is the fraction of edges within
	 * community i and a_i is the fraction of edge-weight incident to
	 * community i.
	 *
	 * @since 0.2.0
	 *
	 * @return float Modularity value in the range [-0.5, 1].
	 */
	private function calculate_modularity() {
		if ( $this->total_weight <= 0.0 ) {
			return 0.0;
		}

		// Compute e_cc (intra-community edge weight) and a_c (total weight incident to community).
		$e_cc = array();
		$a_c  = array();

		foreach ( $this->edges as $edge ) {
			$src = $edge['source'];
			$tgt = $edge['target'];
			$w   = $edge['weight'];

			if ( ! isset( $this->community_map[ $src ] ) || ! isset( $this->community_map[ $tgt ] ) ) {
				continue;
			}

			$c_src = $this->community_map[ $src ];
			$c_tgt = $this->community_map[ $tgt ];

			if ( ! isset( $a_c[ $c_src ] ) ) {
				$a_c[ $c_src ] = 0.0;
			}
			if ( ! isset( $a_c[ $c_tgt ] ) ) {
				$a_c[ $c_tgt ] = 0.0;
			}

			$a_c[ $c_src ] += $w;
			$a_c[ $c_tgt ] += $w;

			if ( $c_src === $c_tgt ) {
				if ( ! isset( $e_cc[ $c_src ] ) ) {
					$e_cc[ $c_src ] = 0.0;
				}
				$e_cc[ $c_src ] += $w;
			}
		}

		$q = 0.0;
		$m = $this->total_weight;

		$all_communities = array_unique( $this->community_map );
		foreach ( $all_communities as $cid ) {
			$eii = isset( $e_cc[ $cid ] ) ? $e_cc[ $cid ] / $m : 0.0;
			$ai  = isset( $a_c[ $cid ] ) ? $a_c[ $cid ] / ( 2.0 * $m ) : 0.0;
			$q  += $eii - ( $ai * $ai );
		}

		return $q;
	}

	// ------------------------------------------------------------------
	// Persistence.
	// ------------------------------------------------------------------

	/**
	 * Write community assignments to the nodes table and update graph meta.
	 *
	 * Each node receives a sequential community_id. The meta table's
	 * community_count is also updated.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function persist_communities() {
		global $wpdb;

		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$meta_table  = NV_oOS_Graphify_Database::get_meta_table();

		// Renumber communities to sequential integers starting at 1.
		$renumber = array();
		$index    = 1;
		foreach ( $this->community_map as $nid => $cid ) {
			if ( ! isset( $renumber[ $cid ] ) ) {
				$renumber[ $cid ] = $index;
				++$index;
			}
		}

		$labels = $this->generate_community_labels( $renumber );

		// Update each node's community_id.
		foreach ( $this->community_map as $nid => $cid ) {
			$new_cid = $renumber[ $cid ];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$nodes_table,
				array( 'community_id' => $new_cid ),
				array(
					'graph_id' => $this->graph_id,
					'node_id'  => $nid,
				),
				array( '%d' ),
				array( '%d', '%s' )
			);

			// Keep in-memory map consistent.
			$this->community_map[ $nid ] = $new_cid;
		}

		$community_count = count( $renumber );

		$meta_payload = wp_json_encode(
			array(
				'labels'    => $labels,
				'algorithm' => count( $this->nodes ) < self::LOUVAIN_THRESHOLD ? 'connected_components' : 'louvain',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$meta_table} SET community_count = %d, settings = %s WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$community_count,
				$meta_payload,
				$this->graph_id
			)
		);
	}

	/**
	 * Generate human-readable labels for each community.
	 *
	 * The label is taken from the highest-degree node within the community.
	 *
	 * @since 0.2.0
	 *
	 * @param array $renumber Map of raw community ID → sequential community ID.
	 * @return array Map of sequential community ID → label string.
	 */
	private function generate_community_labels( $renumber ) {
		// Group nodes by renumbered community and track max degree.
		$best_label  = array();
		$best_degree = array();

		foreach ( $this->community_map as $nid => $cid ) {
			$seq_id = isset( $renumber[ $cid ] ) ? $renumber[ $cid ] : $cid;
			$degree = isset( $this->nodes[ $nid ]['degree'] ) ? (int) $this->nodes[ $nid ]['degree'] : 0;

			if ( ! isset( $best_degree[ $seq_id ] ) || $degree > $best_degree[ $seq_id ] ) {
				$best_degree[ $seq_id ] = $degree;
				$best_label[ $seq_id ]  = isset( $this->nodes[ $nid ]['label'] ) ? $this->nodes[ $nid ]['label'] : $nid;
			}
		}

		return $best_label;
	}

	// ------------------------------------------------------------------
	// Community statistics.
	// ------------------------------------------------------------------

	/**
	 * Build an array of community summary objects from the current state.
	 *
	 * @since 0.2.0
	 *
	 * @return array[] List of community summaries with id, label, size, and cohesion.
	 */
	private function build_community_stats() {
		if ( empty( $this->community_map ) ) {
			return array();
		}

		// Group nodes by community.
		$groups = array();
		foreach ( $this->community_map as $nid => $cid ) {
			if ( ! isset( $groups[ $cid ] ) ) {
				$groups[ $cid ] = array();
			}
			$groups[ $cid ][] = $nid;
		}

		$communities = array();
		foreach ( $groups as $cid => $members ) {
			$size     = count( $members );
			$label    = $this->get_highest_degree_label( $members );
			$cohesion = $this->compute_cohesion( $members );

			$communities[] = array(
				'id'       => (int) $cid,
				'label'    => $label,
				'size'     => $size,
				'cohesion' => round( $cohesion, 4 ),
			);
		}

		// Sort by size descending for readability.
		usort(
			$communities,
			function ( $a, $b ) {
				return $b['size'] - $a['size'];
			}
		);

		return $communities;
	}

	/**
	 * Return the label of the highest-degree node among the given members.
	 *
	 * @since 0.2.0
	 *
	 * @param array $members List of node IDs.
	 * @return string Best label.
	 */
	private function get_highest_degree_label( $members ) {
		$best_label  = '';
		$best_degree = -1;

		foreach ( $members as $nid ) {
			$degree = isset( $this->nodes[ $nid ]['degree'] ) ? (int) $this->nodes[ $nid ]['degree'] : 0;
			if ( $degree > $best_degree ) {
				$best_degree = $degree;
				$best_label  = isset( $this->nodes[ $nid ]['label'] ) ? $this->nodes[ $nid ]['label'] : $nid;
			}
		}

		return $best_label;
	}

	/**
	 * Compute the cohesion of a community.
	 *
	 * Cohesion is the ratio of actual internal edges to the maximum
	 * possible internal edges (n*(n-1)/2 for an undirected graph).
	 *
	 * @since 0.2.0
	 *
	 * @param array $members List of node IDs in the community.
	 * @return float Cohesion value between 0.0 and 1.0.
	 */
	private function compute_cohesion( $members ) {
		$n = count( $members );
		if ( $n < 2 ) {
			return 1.0;
		}

		$max_edges  = ( $n * ( $n - 1 ) ) / 2.0;
		$member_set = array_flip( $members );
		$internal   = 0;

		foreach ( $members as $nid ) {
			if ( empty( $this->adjacency[ $nid ] ) ) {
				continue;
			}
			foreach ( $this->adjacency[ $nid ] as $neighbour ) {
				if ( isset( $member_set[ $neighbour ] ) ) {
					++$internal;
				}
			}
		}

		// Each edge counted twice in undirected adjacency.
		$internal_edges = $internal / 2.0;

		return $internal_edges / $max_edges;
	}

	// ------------------------------------------------------------------
	// Utilities.
	// ------------------------------------------------------------------

	/**
	 * Generate a canonical edge key for an undirected pair.
	 *
	 * The two identifiers are sorted so that edge(A,B) == edge(B,A).
	 *
	 * @since 0.2.0
	 *
	 * @param string $a First node identifier.
	 * @param string $b Second node identifier.
	 * @return string Pipe-separated key.
	 */
	private function edge_key( $a, $b ) {
		if ( strcmp( $a, $b ) <= 0 ) {
			return $a . '|' . $b;
		}
		return $b . '|' . $a;
	}
}
