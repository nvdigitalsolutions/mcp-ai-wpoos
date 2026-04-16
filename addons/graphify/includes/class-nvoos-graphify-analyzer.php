<?php
/**
 * Graph analysis methods — god nodes, gaps, paths, and NL queries.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_Analyzer
 *
 * Provides analytical queries over the persisted knowledge graph:
 * hub detection, surprising edges, knowledge gaps, content
 * recommendations, SEO insights, shortest paths, and natural-
 * language subgraph retrieval.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Analyzer {

	/**
	 * Graph identifier.
	 *
	 * @var int
	 */
	private $graph_id;

	/**
	 * Database table names.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * English stop words used for keyword extraction.
	 *
	 * @var array
	 */
	private static $stop_words = array(
		'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to',
		'for', 'of', 'with', 'by', 'from', 'is', 'it', 'as', 'was',
		'are', 'were', 'been', 'be', 'have', 'has', 'had', 'do', 'does',
		'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can',
		'shall', 'not', 'no', 'nor', 'so', 'if', 'then', 'than', 'that',
		'this', 'these', 'those', 'what', 'which', 'who', 'whom', 'how',
		'when', 'where', 'why', 'all', 'each', 'every', 'both', 'few',
		'more', 'most', 'other', 'some', 'such', 'only', 'own', 'same',
		'about', 'up', 'out', 'into', 'over', 'after', 'before', 'between',
	);

	/**
	 * Constructor.
	 *
	 * @param int $graph_id Graph identifier. Default 1.
	 */
	public function __construct( $graph_id = 1 ) {
		$this->graph_id = absint( $graph_id );
		if ( $this->graph_id < 1 ) {
			$this->graph_id = 1;
		}
		$this->tables = NV_oOS_Graphify_DB::get_table_names();
	}

	/**
	 * Get the most-connected nodes in the graph (hub / "god" nodes).
	 *
	 * @param int $top_n Number of nodes to return. Default 10.
	 * @return array Array of node arrays.
	 */
	public function get_god_nodes( $top_n = 10 ) {
		global $wpdb;

		$top_n = absint( $top_n );
		if ( $top_n < 1 ) {
			$top_n = 10;
		}

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, node_type, degree, community_id, source_url
				 FROM %i
				 WHERE graph_id = %d
				 ORDER BY degree DESC
				 LIMIT %d',
				$this->tables['nodes'],
				$this->graph_id,
				$top_n
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Find edges that cross community or content-type boundaries.
	 *
	 * Scoring:
	 *  - base: confidence_score
	 *  - +0.3 cross-community
	 *  - +0.2 cross-content-type
	 *  - +0.1 peripheral-to-hub
	 *
	 * @param int $top_n Number of edges to return. Default 10.
	 * @return array Ranked edge arrays with computed surprise_score.
	 */
	public function get_surprising_connections( $top_n = 10 ) {
		global $wpdb;

		$top_n = absint( $top_n );
		if ( $top_n < 1 ) {
			$top_n = 10;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT e.source_node_id, e.target_node_id, e.relation, e.confidence, e.confidence_score,
				        s.label AS source_label, s.node_type AS source_type, s.community_id AS source_community, s.degree AS source_degree,
				        t.label AS target_label, t.node_type AS target_type, t.community_id AS target_community, t.degree AS target_degree
				 FROM %i AS e
				 INNER JOIN %i AS s ON s.graph_id = e.graph_id AND s.node_id = e.source_node_id
				 INNER JOIN %i AS t ON t.graph_id = e.graph_id AND t.node_id = e.target_node_id
				 WHERE e.graph_id = %d',
				$this->tables['edges'],
				$this->tables['nodes'],
				$this->tables['nodes'],
				$this->graph_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		// Score each edge.
		foreach ( $rows as &$row ) {
			$score = floatval( $row['confidence_score'] );

			// Cross-community bonus.
			if ( $row['source_community'] !== $row['target_community'] ) {
				$score += 0.3;
			}

			// Cross-content-type bonus.
			if ( $row['source_type'] !== $row['target_type'] ) {
				$score += 0.2;
			}

			// Peripheral-to-hub bonus.
			$src_deg = (int) $row['source_degree'];
			$tgt_deg = (int) $row['target_degree'];
			if (
				( $src_deg < 3 && $tgt_deg > 10 ) ||
				( $tgt_deg < 3 && $src_deg > 10 )
			) {
				$score += 0.1;
			}

			$row['surprise_score'] = round( $score, 4 );
		}
		unset( $row );

		// Sort descending by surprise_score.
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['surprise_score'] === $b['surprise_score'] ) {
					return 0;
				}
				return ( $a['surprise_score'] > $b['surprise_score'] ) ? -1 : 1;
			}
		);

		return array_slice( $rows, 0, $top_n );
	}

	/**
	 * Identify knowledge gaps in the graph.
	 *
	 * @return array Associative array with orphan_nodes, thin_communities,
	 *               high_ambiguity, and isolated_posts.
	 */
	public function get_knowledge_gaps() {
		global $wpdb;

		// Orphan nodes (degree 0).
		$orphan_nodes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, node_type FROM %i WHERE graph_id = %d AND degree = 0',
				$this->tables['nodes'],
				$this->graph_id
			),
			ARRAY_A
		);

		// Thin communities (< 3 members).
		$community_sizes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT community_id, COUNT(*) AS member_count
				 FROM %i
				 WHERE graph_id = %d AND community_id IS NOT NULL
				 GROUP BY community_id
				 HAVING member_count < 3',
				$this->tables['nodes'],
				$this->graph_id
			),
			ARRAY_A
		);

		// High-ambiguity edges.
		$high_ambiguity = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT source_node_id, target_node_id, relation, confidence_score
				 FROM %i
				 WHERE graph_id = %d AND confidence = %s',
				$this->tables['edges'],
				$this->graph_id,
				'AMBIGUOUS'
			),
			ARRAY_A
		);

		// Isolated posts (post/page nodes with no edges).
		$isolated_posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT n.node_id, n.label, n.source_id, n.source_url
				 FROM %i AS n
				 WHERE n.graph_id = %d
				   AND n.node_type IN ( %s, %s )
				   AND n.degree = 0',
				$this->tables['nodes'],
				$this->graph_id,
				'post',
				'page'
			),
			ARRAY_A
		);

		return array(
			'orphan_nodes'     => is_array( $orphan_nodes ) ? $orphan_nodes : array(),
			'thin_communities' => is_array( $community_sizes ) ? $community_sizes : array(),
			'high_ambiguity'   => is_array( $high_ambiguity ) ? $high_ambiguity : array(),
			'isolated_posts'   => is_array( $isolated_posts ) ? $isolated_posts : array(),
		);
	}

	/**
	 * Generate content recommendations from knowledge gaps.
	 *
	 * Suggests internal links between unconnected posts in the same
	 * community and identifies thin communities as new-content candidates.
	 *
	 * @return array Structured recommendations.
	 */
	public function get_content_recommendations() {
		global $wpdb;

		$recommendations = array(
			'suggested_links'    => array(),
			'content_to_create'  => array(),
		);

		// -- Suggested internal links: posts in the same community, not yet connected.
		$community_posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, community_id, source_url
				 FROM %i
				 WHERE graph_id = %d AND node_type IN ( %s, %s ) AND community_id IS NOT NULL
				 ORDER BY community_id, degree DESC',
				$this->tables['nodes'],
				$this->graph_id,
				'post',
				'page'
			),
			ARRAY_A
		);

		// Group by community.
		$grouped = array();
		if ( is_array( $community_posts ) ) {
			foreach ( $community_posts as $row ) {
				$grouped[ $row['community_id'] ][] = $row;
			}
		}

		// Build an edge lookup for fast existence check.
		$existing_edges = array();
		$edge_rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT source_node_id, target_node_id FROM %i WHERE graph_id = %d AND relation = %s',
				$this->tables['edges'],
				$this->graph_id,
				'links_to'
			),
			ARRAY_A
		);
		if ( is_array( $edge_rows ) ) {
			foreach ( $edge_rows as $er ) {
				$existing_edges[ $er['source_node_id'] . '|' . $er['target_node_id'] ] = true;
			}
		}

		foreach ( $grouped as $community_members ) {
			$count = count( $community_members );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a = $community_members[ $i ]['node_id'];
					$b = $community_members[ $j ]['node_id'];
					if (
						! isset( $existing_edges[ $a . '|' . $b ] ) &&
						! isset( $existing_edges[ $b . '|' . $a ] )
					) {
						$recommendations['suggested_links'][] = array(
							'from_node_id'  => $a,
							'from_label'    => $community_members[ $i ]['label'],
							'from_url'      => $community_members[ $i ]['source_url'],
							'to_node_id'    => $b,
							'to_label'      => $community_members[ $j ]['label'],
							'to_url'        => $community_members[ $j ]['source_url'],
							'community_id'  => $community_members[ $i ]['community_id'],
						);
					}
				}
			}
		}

		// -- Content to create: thin communities.
		$thin = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT community_id, COUNT(*) AS member_count
				 FROM %i
				 WHERE graph_id = %d AND community_id IS NOT NULL
				 GROUP BY community_id
				 HAVING member_count < 3',
				$this->tables['nodes'],
				$this->graph_id
			),
			ARRAY_A
		);

		if ( is_array( $thin ) ) {
			foreach ( $thin as $tc ) {
				$community_label = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'SELECT label FROM %i WHERE graph_id = %d AND community_id = %d ORDER BY degree DESC LIMIT 1',
						$this->tables['nodes'],
						$this->graph_id,
						(int) $tc['community_id']
					)
				);

				$recommendations['content_to_create'][] = array(
					'community_id'   => (int) $tc['community_id'],
					'community_label' => $community_label ? $community_label : __( 'Unnamed', 'nvoos-graphify' ),
					'current_size'   => (int) $tc['member_count'],
					'suggestion'     => sprintf(
						/* translators: %s: community label */
						__( 'Create more content about "%s" to strengthen this topic cluster.', 'nvoos-graphify' ),
						$community_label ? $community_label : __( 'Unnamed', 'nvoos-graphify' )
					),
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Generate SEO-oriented insights from the graph.
	 *
	 * @return array Structured SEO insights.
	 */
	public function get_seo_insights() {
		global $wpdb;

		$insights = array(
			'content_clusters'       => array(),
			'cannibalization_risks'  => array(),
			'pillar_candidates'      => array(),
		);

		// -- Content clusters: communities mapped to topic labels.
		$clusters = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT community_id, COUNT(*) AS member_count
				 FROM %i
				 WHERE graph_id = %d AND community_id IS NOT NULL
				 GROUP BY community_id
				 ORDER BY member_count DESC',
				$this->tables['nodes'],
				$this->graph_id
			),
			ARRAY_A
		);

		if ( is_array( $clusters ) ) {
			foreach ( $clusters as $cluster ) {
				$top_node = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'SELECT node_id, label, degree FROM %i WHERE graph_id = %d AND community_id = %d ORDER BY degree DESC LIMIT 1',
						$this->tables['nodes'],
						$this->graph_id,
						(int) $cluster['community_id']
					),
					ARRAY_A
				);

				$insights['content_clusters'][] = array(
					'community_id'  => (int) $cluster['community_id'],
					'member_count'  => (int) $cluster['member_count'],
					'topic_label'   => $top_node ? $top_node['label'] : __( 'Unknown', 'nvoos-graphify' ),
					'hub_node_id'   => $top_node ? $top_node['node_id'] : '',
					'hub_degree'    => $top_node ? (int) $top_node['degree'] : 0,
				);
			}
		}

		// -- Cannibalization risks: post/page nodes in the same community with
		//    very similar labels (Levenshtein distance < 30% of label length).
		$post_nodes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, community_id, source_url
				 FROM %i
				 WHERE graph_id = %d AND node_type IN ( %s, %s ) AND community_id IS NOT NULL
				 ORDER BY community_id',
				$this->tables['nodes'],
				$this->graph_id,
				'post',
				'page'
			),
			ARRAY_A
		);

		$by_community = array();
		if ( is_array( $post_nodes ) ) {
			foreach ( $post_nodes as $pn ) {
				$by_community[ $pn['community_id'] ][] = $pn;
			}
		}

		foreach ( $by_community as $members ) {
			$count = count( $members );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$label_a = strtolower( $members[ $i ]['label'] );
					$label_b = strtolower( $members[ $j ]['label'] );
					$max_len = max( strlen( $label_a ), strlen( $label_b ) );

					if ( $max_len < 1 ) {
						continue;
					}

					$distance  = levenshtein( $label_a, $label_b );
					$threshold = $max_len * 0.3;

					if ( $distance < $threshold ) {
						$insights['cannibalization_risks'][] = array(
							'node_a'     => $members[ $i ]['node_id'],
							'label_a'    => $members[ $i ]['label'],
							'url_a'      => $members[ $i ]['source_url'],
							'node_b'     => $members[ $j ]['node_id'],
							'label_b'    => $members[ $j ]['label'],
							'url_b'      => $members[ $j ]['source_url'],
							'distance'   => $distance,
							'similarity' => round( 1 - ( $distance / $max_len ), 2 ),
						);
					}
				}
			}
		}

		// -- Pillar content candidates: god nodes that are posts/pages.
		$pillar = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, degree, community_id, source_url
				 FROM %i
				 WHERE graph_id = %d AND node_type IN ( %s, %s )
				 ORDER BY degree DESC
				 LIMIT 10',
				$this->tables['nodes'],
				$this->graph_id,
				'post',
				'page'
			),
			ARRAY_A
		);

		$insights['pillar_candidates'] = is_array( $pillar ) ? $pillar : array();

		return $insights;
	}

	/**
	 * Find the shortest path between two nodes using BFS.
	 *
	 * @param string $source_node_id Source node identifier.
	 * @param string $target_node_id Target node identifier.
	 * @param int    $max_hops       Maximum traversal depth. Default 10.
	 * @return array Array of node_ids forming the path, or empty array.
	 */
	public function get_shortest_path( $source_node_id, $target_node_id, $max_hops = 10 ) {
		global $wpdb;

		$source_node_id = sanitize_text_field( $source_node_id );
		$target_node_id = sanitize_text_field( $target_node_id );
		$max_hops       = absint( $max_hops );

		if ( $source_node_id === $target_node_id ) {
			return array( $source_node_id );
		}

		// Load adjacency for the graph.
		$edges = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT source_node_id, target_node_id FROM %i WHERE graph_id = %d',
				$this->tables['edges'],
				$this->graph_id
			),
			ARRAY_A
		);

		$adjacency = array();
		if ( is_array( $edges ) ) {
			foreach ( $edges as $edge ) {
				$src = $edge['source_node_id'];
				$tgt = $edge['target_node_id'];
				if ( ! isset( $adjacency[ $src ] ) ) {
					$adjacency[ $src ] = array();
				}
				if ( ! isset( $adjacency[ $tgt ] ) ) {
					$adjacency[ $tgt ] = array();
				}
				$adjacency[ $src ][] = $tgt;
				$adjacency[ $tgt ][] = $src;
			}
		}

		if ( ! isset( $adjacency[ $source_node_id ] ) ) {
			return array();
		}

		// BFS.
		$visited = array( $source_node_id => true );
		$parent  = array( $source_node_id => null );
		$queue   = array( array( $source_node_id, 0 ) );

		while ( ! empty( $queue ) ) {
			list( $current, $depth ) = array_shift( $queue );

			if ( $depth >= $max_hops ) {
				continue;
			}

			if ( ! isset( $adjacency[ $current ] ) ) {
				continue;
			}

			foreach ( $adjacency[ $current ] as $neighbour ) {
				if ( isset( $visited[ $neighbour ] ) ) {
					continue;
				}

				$visited[ $neighbour ] = true;
				$parent[ $neighbour ]  = $current;

				if ( $neighbour === $target_node_id ) {
					// Reconstruct path.
					$path = array();
					$step = $target_node_id;
					while ( null !== $step ) {
						array_unshift( $path, $step );
						$step = isset( $parent[ $step ] ) ? $parent[ $step ] : null;
					}
					return $path;
				}

				$queue[] = array( $neighbour, $depth + 1 );
			}
		}

		return array();
	}

	/**
	 * Natural-language graph query.
	 *
	 * Extracts keywords from a question, matches graph nodes, traverses
	 * the neighbourhood, and returns a formatted text context.
	 *
	 * @param string $question     Natural language question.
	 * @param string $mode         Traversal mode: 'bfs' or 'dfs'. Default 'bfs'.
	 * @param int    $depth        Traversal depth. Default 3.
	 * @param int    $token_budget Approximate token budget (~4 chars/token). Default 4000.
	 * @return string Formatted context string.
	 */
	public function query_graph( $question, $mode = 'bfs', $depth = 3, $token_budget = 4000 ) {
		global $wpdb;

		$depth        = absint( $depth );
		$token_budget = absint( $token_budget );
		$char_budget  = $token_budget * 4;

		// 1. Extract keywords.
		$keywords = $this->extract_keywords( $question );
		if ( empty( $keywords ) ) {
			return '';
		}

		// 2. Find seed nodes matching keywords.
		$seed_nodes = array();
		foreach ( $keywords as $keyword ) {
			$like    = '%' . $wpdb->esc_like( $keyword ) . '%';
			$matches = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT node_id, label, node_type, degree FROM %i WHERE graph_id = %d AND label LIKE %s LIMIT 5',
					$this->tables['nodes'],
					$this->graph_id,
					$like
				),
				ARRAY_A
			);
			if ( is_array( $matches ) ) {
				foreach ( $matches as $match ) {
					$seed_nodes[ $match['node_id'] ] = $match;
				}
			}
		}

		if ( empty( $seed_nodes ) ) {
			return '';
		}

		// 3. Load adjacency.
		$edge_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT source_node_id, target_node_id, relation FROM %i WHERE graph_id = %d',
				$this->tables['edges'],
				$this->graph_id
			),
			ARRAY_A
		);

		$adjacency  = array();
		$edge_label = array();
		if ( is_array( $edge_rows ) ) {
			foreach ( $edge_rows as $er ) {
				$src = $er['source_node_id'];
				$tgt = $er['target_node_id'];
				if ( ! isset( $adjacency[ $src ] ) ) {
					$adjacency[ $src ] = array();
				}
				if ( ! isset( $adjacency[ $tgt ] ) ) {
					$adjacency[ $tgt ] = array();
				}
				$adjacency[ $src ][] = $tgt;
				$adjacency[ $tgt ][] = $src;

				$edge_label[ $src . '|' . $tgt ] = $er['relation'];
				$edge_label[ $tgt . '|' . $src ] = $er['relation'];
			}
		}

		// Load all node labels for context building.
		$all_nodes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_id, label, node_type FROM %i WHERE graph_id = %d',
				$this->tables['nodes'],
				$this->graph_id
			),
			ARRAY_A
		);

		$node_labels = array();
		if ( is_array( $all_nodes ) ) {
			foreach ( $all_nodes as $n ) {
				$node_labels[ $n['node_id'] ] = $n;
			}
		}

		// 4. Traverse from seeds.
		$visited_nodes = array();
		$visited_edges = array();

		if ( 'dfs' === $mode ) {
			$this->traverse_dfs( array_keys( $seed_nodes ), $adjacency, $depth, $visited_nodes, $visited_edges, $edge_label );
		} else {
			$this->traverse_bfs( array_keys( $seed_nodes ), $adjacency, $depth, $visited_nodes, $visited_edges, $edge_label );
		}

		// 5. Format output.
		$lines = array();

		$lines[] = '## Graph Context';
		$lines[] = '';

		// Node descriptions.
		$lines[] = '### Nodes';
		foreach ( $visited_nodes as $nid => $true ) {
			if ( isset( $node_labels[ $nid ] ) ) {
				$n       = $node_labels[ $nid ];
				$lines[] = sprintf( '- **%s** (%s): %s', $n['label'], $n['node_type'], $nid );
			}
		}

		$lines[] = '';
		$lines[] = '### Relationships';
		foreach ( $visited_edges as $edge_key => $relation ) {
			$parts = explode( '|', $edge_key );
			if ( count( $parts ) === 2 ) {
				$src_label = isset( $node_labels[ $parts[0] ] ) ? $node_labels[ $parts[0] ]['label'] : $parts[0];
				$tgt_label = isset( $node_labels[ $parts[1] ] ) ? $node_labels[ $parts[1] ]['label'] : $parts[1];
				$lines[]   = sprintf( '- %s -[%s]-> %s', $src_label, $relation, $tgt_label );
			}
		}

		$output = implode( "\n", $lines );

		// Respect token budget.
		if ( strlen( $output ) > $char_budget ) {
			$output = substr( $output, 0, $char_budget );
			// Trim to last newline for clean truncation.
			$last_newline = strrpos( $output, "\n" );
			if ( false !== $last_newline && $last_newline > 0 ) {
				$output = substr( $output, 0, $last_newline );
			}
			$output .= "\n\n[Truncated to fit token budget]";
		}

		return $output;
	}

	/**
	 * BFS traversal from seed nodes.
	 *
	 * @param array $seeds          Array of seed node_ids.
	 * @param array $adjacency      Adjacency list.
	 * @param int   $depth          Max depth.
	 * @param array $visited_nodes  Passed by reference — node_id => true.
	 * @param array $visited_edges  Passed by reference — "src|tgt" => relation.
	 * @param array $edge_label     Edge label lookup.
	 * @return void
	 */
	private function traverse_bfs( $seeds, $adjacency, $depth, &$visited_nodes, &$visited_edges, $edge_label ) {
		$queue = array();
		foreach ( $seeds as $seed ) {
			$queue[]                   = array( $seed, 0 );
			$visited_nodes[ $seed ]    = true;
		}

		while ( ! empty( $queue ) ) {
			list( $current, $current_depth ) = array_shift( $queue );

			if ( $current_depth >= $depth ) {
				continue;
			}

			if ( ! isset( $adjacency[ $current ] ) ) {
				continue;
			}

			foreach ( $adjacency[ $current ] as $neighbour ) {
				$edge_key = $current . '|' . $neighbour;
				if ( ! isset( $visited_edges[ $edge_key ] ) ) {
					$relation                     = isset( $edge_label[ $edge_key ] ) ? $edge_label[ $edge_key ] : 'related_to';
					$visited_edges[ $edge_key ]   = $relation;
				}

				if ( ! isset( $visited_nodes[ $neighbour ] ) ) {
					$visited_nodes[ $neighbour ] = true;
					$queue[]                     = array( $neighbour, $current_depth + 1 );
				}
			}
		}
	}

	/**
	 * DFS traversal from seed nodes.
	 *
	 * @param array $seeds          Array of seed node_ids.
	 * @param array $adjacency      Adjacency list.
	 * @param int   $depth          Max depth.
	 * @param array $visited_nodes  Passed by reference.
	 * @param array $visited_edges  Passed by reference.
	 * @param array $edge_label     Edge label lookup.
	 * @return void
	 */
	private function traverse_dfs( $seeds, $adjacency, $depth, &$visited_nodes, &$visited_edges, $edge_label ) {
		$stack = array();
		foreach ( $seeds as $seed ) {
			$stack[]                   = array( $seed, 0 );
			$visited_nodes[ $seed ]    = true;
		}

		while ( ! empty( $stack ) ) {
			list( $current, $current_depth ) = array_pop( $stack );

			if ( $current_depth >= $depth ) {
				continue;
			}

			if ( ! isset( $adjacency[ $current ] ) ) {
				continue;
			}

			foreach ( $adjacency[ $current ] as $neighbour ) {
				$edge_key = $current . '|' . $neighbour;
				if ( ! isset( $visited_edges[ $edge_key ] ) ) {
					$relation                     = isset( $edge_label[ $edge_key ] ) ? $edge_label[ $edge_key ] : 'related_to';
					$visited_edges[ $edge_key ]   = $relation;
				}

				if ( ! isset( $visited_nodes[ $neighbour ] ) ) {
					$visited_nodes[ $neighbour ] = true;
					$stack[]                     = array( $neighbour, $current_depth + 1 );
				}
			}
		}
	}

	/**
	 * Extract keywords from a natural-language question.
	 *
	 * @param string $text Input text.
	 * @return array Unique lowercase keywords (stop words removed).
	 */
	private function extract_keywords( $text ) {
		$text  = strtolower( sanitize_text_field( $text ) );
		$words = preg_split( '/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) ) {
			return array();
		}

		$stop_set = array_flip( self::$stop_words );
		$keywords = array();

		foreach ( $words as $word ) {
			if ( strlen( $word ) < 2 ) {
				continue;
			}
			if ( isset( $stop_set[ $word ] ) ) {
				continue;
			}
			$keywords[ $word ] = true;
		}

		return array_keys( $keywords );
	}
}
