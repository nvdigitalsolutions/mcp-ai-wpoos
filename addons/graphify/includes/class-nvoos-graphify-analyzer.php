<?php
/**
 * Graph analysis engine for the NV oOS Graphify addon.
 *
 * Provides analytical queries over the knowledge graph: god-node discovery,
 * surprising cross-community connections, knowledge-gap detection,
 * content recommendations, SEO insights, and shortest-path search.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_Analyzer
 *
 * Reads the persisted knowledge graph and returns structured insights
 * that power the Graphify dashboard, tools, and REST endpoints.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Analyzer {

	/**
	 * Confidence-score weights used when calculating surprise factor.
	 *
	 * @since 0.2.0
	 * @var array<string,float>
	 */
	const CONFIDENCE_WEIGHTS = array(
		'EXTRACTED' => 1.0,
		'INFERRED'  => 0.7,
		'AMBIGUOUS' => 0.4,
	);

	/**
	 * Bonus added when the source and target have different node types.
	 *
	 * @since 0.2.0
	 * @var float
	 */
	const CROSS_TYPE_BONUS = 0.3;

	/**
	 * Bonus added when the source and target belong to different communities.
	 *
	 * @since 0.2.0
	 * @var float
	 */
	const CROSS_COMMUNITY_BONUS = 0.3;

	/**
	 * Bonus added for peripheral-to-hub or hub-to-peripheral edges.
	 *
	 * @since 0.2.0
	 * @var float
	 */
	const PERIPHERAL_HUB_BONUS = 0.2;

	/**
	 * Degree threshold below which a node is considered peripheral.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const PERIPHERAL_THRESHOLD = 5;

	/**
	 * Degree threshold above which a node is considered a hub.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const HUB_THRESHOLD = 20;

	/**
	 * Minimum community size to be considered non-thin.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MIN_COMMUNITY_SIZE = 3;

	/**
	 * Minimum community size to qualify as a topic cluster.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const CLUSTER_MIN_SIZE = 3;

	/**
	 * Minimum hub degree inside a community for topic cluster qualification.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const CLUSTER_HUB_DEGREE = 5;

	/**
	 * Minimum shared term-neighbours for cannibalization detection.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const CANNIBALIZATION_THRESHOLD = 3;

	/**
	 * Default maximum suggestions returned by get_content_recommendations().
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_RECOMMENDATIONS = 20;

	/**
	 * Graph identifier scoping all queries.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private $graph_id;

	/**
	 * Nodes table name (fully qualified with prefix).
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private $nodes_table;

	/**
	 * Edges table name (fully qualified with prefix).
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private $edges_table;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param int $graph_id The graph identifier to analyse.
	 */
	public function __construct( $graph_id ) {
		$this->graph_id    = (int) $graph_id;
		$this->nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$this->edges_table = NV_oOS_Graphify_Database::get_edges_table();
	}

	/**
	 * Return the top-N most-connected nodes (content pillars / "god nodes").
	 *
	 * @since 0.2.0
	 *
	 * @param int $top_n Number of nodes to return. Default 10.
	 * @return array {
	 *     List of god-node records.
	 *
	 *     @type string $node_id      Unique node identifier.
	 *     @type string $label        Human-readable label.
	 *     @type string $node_type    E.g. 'post', 'term', 'user'.
	 *     @type string $source_url   Canonical URL of the source.
	 *     @type int    $degree       Number of connections.
	 *     @type int    $community_id Community cluster identifier.
	 * }
	 */
	public function get_god_nodes( $top_n = 10 ) {
		global $wpdb;

		$top_n = absint( $top_n );
		if ( $top_n < 1 ) {
			$top_n = 10;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, source_url, degree, community_id
				FROM {$this->nodes_table}
				WHERE graph_id = %d
				ORDER BY degree DESC
				LIMIT %d",
				$this->graph_id,
				$top_n
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'node_id'      => sanitize_text_field( $row['node_id'] ),
				'label'        => sanitize_text_field( $row['label'] ),
				'node_type'    => sanitize_text_field( $row['node_type'] ),
				'source_url'   => esc_url( $row['source_url'] ),
				'degree'       => (int) $row['degree'],
				'community_id' => (int) $row['community_id'],
			);
		}

		return $results;
	}

	/**
	 * Find the most "surprising" edges in the graph.
	 *
	 * Surprise is a composite score rewarding cross-community links,
	 * cross-content-type links, and peripheral-to-hub connections.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit Maximum number of edges to return. Default 20.
	 * @return array {
	 *     Sorted list of surprising connections.
	 *
	 *     @type array  $source         Source node details (node_id, label, node_type, community_id, degree).
	 *     @type array  $target         Target node details (same shape).
	 *     @type string $relation       Edge relation label.
	 *     @type string $confidence     Confidence tier (EXTRACTED, INFERRED, AMBIGUOUS).
	 *     @type float  $surprise_score Composite surprise score.
	 * }
	 */
	public function get_surprising_connections( $limit = 20 ) {
		global $wpdb;

		$limit = absint( $limit );
		if ( $limit < 1 ) {
			$limit = 20;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					e.source_node_id,
					e.target_node_id,
					e.relation,
					e.confidence,
					e.confidence_score,
					sn.label   AS source_label,
					sn.node_type AS source_type,
					sn.community_id AS source_community,
					sn.degree  AS source_degree,
					tn.label   AS target_label,
					tn.node_type AS target_type,
					tn.community_id AS target_community,
					tn.degree  AS target_degree
				FROM {$this->edges_table} AS e
				INNER JOIN {$this->nodes_table} AS sn
					ON sn.node_id = e.source_node_id AND sn.graph_id = e.graph_id
				INNER JOIN {$this->nodes_table} AS tn
					ON tn.node_id = e.target_node_id AND tn.graph_id = e.graph_id
				WHERE e.graph_id = %d",
				$this->graph_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$scored = array();
		foreach ( $rows as $row ) {
			$score = $this->calculate_surprise_score( $row );

			$scored[] = array(
				'source'         => array(
					'node_id'      => sanitize_text_field( $row['source_node_id'] ),
					'label'        => sanitize_text_field( $row['source_label'] ),
					'node_type'    => sanitize_text_field( $row['source_type'] ),
					'community_id' => (int) $row['source_community'],
					'degree'       => (int) $row['source_degree'],
				),
				'target'         => array(
					'node_id'      => sanitize_text_field( $row['target_node_id'] ),
					'label'        => sanitize_text_field( $row['target_label'] ),
					'node_type'    => sanitize_text_field( $row['target_type'] ),
					'community_id' => (int) $row['target_community'],
					'degree'       => (int) $row['target_degree'],
				),
				'relation'       => sanitize_text_field( $row['relation'] ),
				'confidence'     => sanitize_text_field( $row['confidence'] ),
				'surprise_score' => round( $score, 2 ),
			);
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['surprise_score'] <=> $a['surprise_score'];
			}
		);

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Calculate the composite surprise score for a single edge row.
	 *
	 * @since 0.2.0
	 *
	 * @param array $row Edge row with joined source/target node data.
	 * @return float Composite score.
	 */
	private function calculate_surprise_score( $row ) {
		$confidence = sanitize_text_field( $row['confidence'] );
		$base       = isset( self::CONFIDENCE_WEIGHTS[ $confidence ] )
			? self::CONFIDENCE_WEIGHTS[ $confidence ]
			: (float) $row['confidence_score'];

		// Cross-type bonus.
		if ( $row['source_type'] !== $row['target_type'] ) {
			$base += self::CROSS_TYPE_BONUS;
		}

		// Cross-community bonus (both must have community > 0).
		$src_comm = (int) $row['source_community'];
		$tgt_comm = (int) $row['target_community'];
		if ( $src_comm > 0 && $tgt_comm > 0 && $src_comm !== $tgt_comm ) {
			$base += self::CROSS_COMMUNITY_BONUS;
		}

		// Peripheral → hub bonus (either direction).
		$src_deg = (int) $row['source_degree'];
		$tgt_deg = (int) $row['target_degree'];
		if (
			( $src_deg < self::PERIPHERAL_THRESHOLD && $tgt_deg > self::HUB_THRESHOLD ) ||
			( $tgt_deg < self::PERIPHERAL_THRESHOLD && $src_deg > self::HUB_THRESHOLD )
		) {
			$base += self::PERIPHERAL_HUB_BONUS;
		}

		return $base;
	}

	/**
	 * Identify knowledge gaps in the graph.
	 *
	 * Returns orphan nodes (degree 0), thin communities (< 3 members),
	 * ambiguous-edge statistics, and the number of disconnected components.
	 *
	 * @since 0.2.0
	 *
	 * @return array {
	 *     Knowledge gap summary.
	 *
	 *     @type array $orphan_nodes             Nodes with zero connections.
	 *     @type array $thin_communities         Communities with fewer than 3 members.
	 *     @type array $ambiguous_edges           { count: int, percentage: float }.
	 *     @type int   $disconnected_components  Number of distinct connected sub-graphs.
	 * }
	 */
	public function get_knowledge_gaps() {
		$orphan_nodes     = $this->find_orphan_nodes();
		$thin_communities = $this->find_thin_communities();
		$ambiguous_edges  = $this->find_ambiguous_edge_stats();
		$disconnected     = $this->count_disconnected_components();

		return array(
			'orphan_nodes'             => $orphan_nodes,
			'thin_communities'         => $thin_communities,
			'ambiguous_edges'          => $ambiguous_edges,
			'disconnected_components'  => $disconnected,
		);
	}

	/**
	 * Find nodes with degree = 0 (isolated / orphan content).
	 *
	 * @since 0.2.0
	 *
	 * @return array List of orphan node arrays.
	 */
	private function find_orphan_nodes() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, source_url, community_id
				FROM {$this->nodes_table}
				WHERE graph_id = %d AND degree = 0",
				$this->graph_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'node_id'      => sanitize_text_field( $row['node_id'] ),
				'label'        => sanitize_text_field( $row['label'] ),
				'node_type'    => sanitize_text_field( $row['node_type'] ),
				'source_url'   => esc_url( $row['source_url'] ),
				'community_id' => (int) $row['community_id'],
			);
		}

		return $results;
	}

	/**
	 * Find communities with fewer than MIN_COMMUNITY_SIZE members.
	 *
	 * @since 0.2.0
	 *
	 * @return array List of thin community arrays with id, label, and size.
	 */
	private function find_thin_communities() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT community_id, COUNT(*) AS size, MIN(label) AS label
				FROM {$this->nodes_table}
				WHERE graph_id = %d AND community_id > 0
				GROUP BY community_id
				HAVING size < %d",
				$this->graph_id,
				self::MIN_COMMUNITY_SIZE
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'id'    => (int) $row['community_id'],
				'label' => sanitize_text_field( $row['label'] ),
				'size'  => (int) $row['size'],
			);
		}

		return $results;
	}

	/**
	 * Return ambiguous-edge count and percentage of total edges.
	 *
	 * @since 0.2.0
	 *
	 * @return array { count: int, percentage: float }
	 */
	private function find_ambiguous_edge_stats() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->edges_table} WHERE graph_id = %d",
				$this->graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ambiguous = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->edges_table} WHERE graph_id = %d AND confidence = %s",
				$this->graph_id,
				'AMBIGUOUS'
			)
		);

		$percentage = ( $total > 0 ) ? round( ( $ambiguous / $total ) * 100, 2 ) : 0.0;

		return array(
			'count'      => $ambiguous,
			'percentage' => $percentage,
		);
	}

	/**
	 * Count distinct connected components via iterative BFS over the adjacency list.
	 *
	 * @since 0.2.0
	 *
	 * @return int Number of disconnected sub-graphs.
	 */
	private function count_disconnected_components() {
		$adjacency = $this->build_adjacency_list();

		if ( empty( $adjacency ) ) {
			return 0;
		}

		$visited    = array();
		$components = 0;

		foreach ( $adjacency as $node => $neighbours ) {
			if ( isset( $visited[ $node ] ) ) {
				continue;
			}

			// BFS from this unvisited node.
			++$components;
			$queue = array( $node );
			$visited[ $node ] = true;

			while ( ! empty( $queue ) ) {
				$current = array_shift( $queue );
				if ( ! isset( $adjacency[ $current ] ) ) {
					continue;
				}
				foreach ( $adjacency[ $current ] as $neighbour ) {
					if ( ! isset( $visited[ $neighbour ] ) ) {
						$visited[ $neighbour ] = true;
						$queue[]               = $neighbour;
					}
				}
			}
		}

		// Include completely isolated nodes (degree 0) that aren't in the adjacency list.
		$all_node_ids = $this->get_all_node_ids();
		foreach ( $all_node_ids as $nid ) {
			if ( ! isset( $visited[ $nid ] ) ) {
				++$components;
				$visited[ $nid ] = true;
			}
		}

		return $components;
	}

	/**
	 * Build an undirected adjacency list from the edges table.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string[]> Node-id keyed adjacency list.
	 */
	private function build_adjacency_list() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id FROM {$this->edges_table} WHERE graph_id = %d",
				$this->graph_id
			),
			ARRAY_A
		);

		$adjacency = array();

		if ( empty( $edges ) ) {
			return $adjacency;
		}

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

		return $adjacency;
	}

	/**
	 * Retrieve all node IDs in the current graph.
	 *
	 * @since 0.2.0
	 *
	 * @return string[] List of node_id values.
	 */
	private function get_all_node_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT node_id FROM {$this->nodes_table} WHERE graph_id = %d",
				$this->graph_id
			)
		);

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Suggest internal links and content to create based on graph structure.
	 *
	 * - Same-community nodes that are NOT directly linked → suggest link.
	 * - Communities with no hub node (degree > 5) → suggest pillar content.
	 *
	 * @since 0.2.0
	 *
	 * @return array {
	 *     Content recommendations.
	 *
	 *     @type array $suggested_links List of link suggestions.
	 *     @type array $content_gaps    List of content gap suggestions.
	 * }
	 */
	public function get_content_recommendations() {
		$suggested_links = $this->find_missing_community_links();
		$content_gaps    = $this->find_hubless_communities();

		return array(
			'suggested_links' => $suggested_links,
			'content_gaps'    => $content_gaps,
		);
	}

	/**
	 * Find pairs of nodes in the same community that are not directly linked.
	 *
	 * Limits results to MAX_RECOMMENDATIONS.
	 *
	 * @since 0.2.0
	 *
	 * @return array List of suggested-link arrays.
	 */
	private function find_missing_community_links() {
		global $wpdb;

		// Get nodes grouped by community (only communities with >1 member).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nodes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, source_url, community_id
				FROM {$this->nodes_table}
				WHERE graph_id = %d AND community_id > 0
				ORDER BY community_id, degree DESC",
				$this->graph_id
			),
			ARRAY_A
		);

		if ( empty( $nodes ) ) {
			return array();
		}

		// Build a set of existing edges for fast lookup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edge_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id
				FROM {$this->edges_table}
				WHERE graph_id = %d",
				$this->graph_id
			),
			ARRAY_A
		);

		$edge_set = array();
		if ( ! empty( $edge_rows ) ) {
			foreach ( $edge_rows as $er ) {
				$edge_set[ $er['source_node_id'] . '|' . $er['target_node_id'] ] = true;
				$edge_set[ $er['target_node_id'] . '|' . $er['source_node_id'] ] = true;
			}
		}

		// Group nodes by community.
		$communities = array();
		foreach ( $nodes as $node ) {
			$cid = (int) $node['community_id'];
			if ( ! isset( $communities[ $cid ] ) ) {
				$communities[ $cid ] = array();
			}
			$communities[ $cid ][] = $node;
		}

		$suggestions = array();

		foreach ( $communities as $members ) {
			$count = count( $members );
			for ( $i = 0; $i < $count && count( $suggestions ) < self::MAX_RECOMMENDATIONS; $i++ ) {
				for ( $j = $i + 1; $j < $count && count( $suggestions ) < self::MAX_RECOMMENDATIONS; $j++ ) {
					$a = $members[ $i ];
					$b = $members[ $j ];

					$key = $a['node_id'] . '|' . $b['node_id'];
					if ( isset( $edge_set[ $key ] ) ) {
						continue;
					}

					$suggestions[] = array(
						'source_label' => sanitize_text_field( $a['label'] ),
						'source_url'   => esc_url( $a['source_url'] ),
						'target_label' => sanitize_text_field( $b['label'] ),
						'target_url'   => esc_url( $b['source_url'] ),
						'reason'       => 'Same community, no direct link',
					);
				}

				if ( count( $suggestions ) >= self::MAX_RECOMMENDATIONS ) {
					break;
				}
			}

			if ( count( $suggestions ) >= self::MAX_RECOMMENDATIONS ) {
				break;
			}
		}

		return array_slice( $suggestions, 0, self::MAX_RECOMMENDATIONS );
	}

	/**
	 * Find communities that have no hub node (max degree <= CLUSTER_HUB_DEGREE).
	 *
	 * @since 0.2.0
	 *
	 * @return array List of content-gap suggestion arrays.
	 */
	private function find_hubless_communities() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT community_id, MAX(degree) AS max_degree, MIN(label) AS label
				FROM {$this->nodes_table}
				WHERE graph_id = %d AND community_id > 0
				GROUP BY community_id
				HAVING max_degree <= %d",
				$this->graph_id,
				self::CLUSTER_HUB_DEGREE
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$gaps = array();
		foreach ( $rows as $row ) {
			$gaps[] = array(
				'community_label' => sanitize_text_field( $row['label'] ),
				'suggestion'      => 'Create a pillar post for this topic cluster',
			);
		}

		return $gaps;
	}

	/**
	 * Generate SEO-oriented insights from the knowledge graph.
	 *
	 * - Topic clusters: communities with size > 3 and at least one hub.
	 * - Cannibalization: post-type nodes sharing > 3 common term neighbours.
	 * - Orphan content: post/page nodes with no link-type edges.
	 *
	 * @since 0.2.0
	 *
	 * @return array {
	 *     SEO insight arrays.
	 *
	 *     @type array $topic_clusters  Communities qualifying as topic clusters.
	 *     @type array $cannibalization Pairs of nodes that may compete.
	 *     @type array $orphan_content  Nodes with no internal links.
	 * }
	 */
	public function get_seo_insights() {
		$topic_clusters   = $this->find_topic_clusters();
		$cannibalization  = $this->find_cannibalization();
		$orphan_content   = $this->find_orphan_content();

		return array(
			'topic_clusters'   => $topic_clusters,
			'cannibalization'  => $cannibalization,
			'orphan_content'   => $orphan_content,
		);
	}

	/**
	 * Find communities with size > CLUSTER_MIN_SIZE and at least one hub node.
	 *
	 * @since 0.2.0
	 *
	 * @return array List of topic cluster arrays.
	 */
	private function find_topic_clusters() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT community_id, COUNT(*) AS size, MAX(degree) AS max_degree, MIN(label) AS label
				FROM {$this->nodes_table}
				WHERE graph_id = %d AND community_id > 0
				GROUP BY community_id
				HAVING size > %d AND max_degree > %d",
				$this->graph_id,
				self::CLUSTER_MIN_SIZE,
				self::CLUSTER_HUB_DEGREE
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$clusters = array();
		foreach ( $rows as $row ) {
			$clusters[] = array(
				'community_id' => (int) $row['community_id'],
				'label'        => sanitize_text_field( $row['label'] ),
				'size'         => (int) $row['size'],
				'max_degree'   => (int) $row['max_degree'],
			);
		}

		return $clusters;
	}

	/**
	 * Detect potential keyword cannibalization.
	 *
	 * Finds pairs of post-type nodes that share more than CANNIBALIZATION_THRESHOLD
	 * common term-type neighbours (i.e. they target the same taxonomy terms).
	 *
	 * @since 0.2.0
	 *
	 * @return array List of cannibalization pairs.
	 */
	private function find_cannibalization() {
		global $wpdb;

		// Fetch post-type nodes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_nodes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, source_url
				FROM {$this->nodes_table}
				WHERE graph_id = %d AND node_type = %s",
				$this->graph_id,
				'post'
			),
			ARRAY_A
		);

		if ( empty( $post_nodes ) || count( $post_nodes ) < 2 ) {
			return array();
		}

		// Build post → term-neighbours map from edges.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$term_edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.source_node_id, e.target_node_id
				FROM {$this->edges_table} AS e
				INNER JOIN {$this->nodes_table} AS tn
					ON tn.node_id = e.target_node_id AND tn.graph_id = e.graph_id
				WHERE e.graph_id = %d AND tn.node_type = %s",
				$this->graph_id,
				'term'
			),
			ARRAY_A
		);

		$post_terms = array();
		if ( ! empty( $term_edges ) ) {
			foreach ( $term_edges as $te ) {
				$src = $te['source_node_id'];
				if ( ! isset( $post_terms[ $src ] ) ) {
					$post_terms[ $src ] = array();
				}
				$post_terms[ $src ][ $te['target_node_id'] ] = true;
			}
		}

		// Also check reverse direction (target → source where source is a term).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reverse_edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.source_node_id, e.target_node_id
				FROM {$this->edges_table} AS e
				INNER JOIN {$this->nodes_table} AS sn
					ON sn.node_id = e.source_node_id AND sn.graph_id = e.graph_id
				WHERE e.graph_id = %d AND sn.node_type = %s",
				$this->graph_id,
				'term'
			),
			ARRAY_A
		);

		if ( ! empty( $reverse_edges ) ) {
			foreach ( $reverse_edges as $re ) {
				$tgt = $re['target_node_id'];
				if ( ! isset( $post_terms[ $tgt ] ) ) {
					$post_terms[ $tgt ] = array();
				}
				$post_terms[ $tgt ][ $re['source_node_id'] ] = true;
			}
		}

		// Index post nodes for fast lookup.
		$post_index = array();
		foreach ( $post_nodes as $pn ) {
			$post_index[ $pn['node_id'] ] = $pn;
		}

		// Compare pairs.
		$results    = array();
		$node_ids   = array_keys( $post_index );
		$node_count = count( $node_ids );

		for ( $i = 0; $i < $node_count; $i++ ) {
			$a_id    = $node_ids[ $i ];
			$a_terms = isset( $post_terms[ $a_id ] ) ? $post_terms[ $a_id ] : array();

			if ( empty( $a_terms ) ) {
				continue;
			}

			for ( $j = $i + 1; $j < $node_count; $j++ ) {
				$b_id    = $node_ids[ $j ];
				$b_terms = isset( $post_terms[ $b_id ] ) ? $post_terms[ $b_id ] : array();

				if ( empty( $b_terms ) ) {
					continue;
				}

				$shared = count( array_intersect_key( $a_terms, $b_terms ) );
				if ( $shared > self::CANNIBALIZATION_THRESHOLD ) {
					$a = $post_index[ $a_id ];
					$b = $post_index[ $b_id ];

					$results[] = array(
						'node_a'       => array(
							'node_id'    => sanitize_text_field( $a['node_id'] ),
							'label'      => sanitize_text_field( $a['label'] ),
							'source_url' => esc_url( $a['source_url'] ),
						),
						'node_b'       => array(
							'node_id'    => sanitize_text_field( $b['node_id'] ),
							'label'      => sanitize_text_field( $b['label'] ),
							'source_url' => esc_url( $b['source_url'] ),
						),
						'shared_terms' => $shared,
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Find post/page nodes with no link-type edges (orphan content from an SEO perspective).
	 *
	 * @since 0.2.0
	 *
	 * @return array List of orphan content node arrays.
	 */
	private function find_orphan_content() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.node_id, n.label, n.node_type, n.source_url
				FROM {$this->nodes_table} AS n
				WHERE n.graph_id = %d
					AND n.node_type IN (%s, %s)
					AND n.node_id NOT IN (
						SELECT source_node_id FROM {$this->edges_table}
						WHERE graph_id = %d AND relation = %s
					)
					AND n.node_id NOT IN (
						SELECT target_node_id FROM {$this->edges_table}
						WHERE graph_id = %d AND relation = %s
					)",
				$this->graph_id,
				'post',
				'page',
				$this->graph_id,
				'links_to',
				$this->graph_id,
				'links_to'
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'node_id'    => sanitize_text_field( $row['node_id'] ),
				'label'      => sanitize_text_field( $row['label'] ),
				'node_type'  => sanitize_text_field( $row['node_type'] ),
				'source_url' => esc_url( $row['source_url'] ),
			);
		}

		return $results;
	}

	/**
	 * Find the shortest path between two nodes using BFS.
	 *
	 * Labels are resolved via LIKE search, so partial matches are accepted.
	 * The search is capped at $max_hops to avoid runaway traversal.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source_label Label (or partial) of the source node.
	 * @param string $target_label Label (or partial) of the target node.
	 * @param int    $max_hops     Maximum traversal depth. Default 6.
	 * @return array {
	 *     Path result.
	 *
	 *     @type bool   $found Whether a path was found.
	 *     @type array  $path  Ordered list of node_ids from source to target.
	 *     @type array  $edges List of edge arrays along the path.
	 *     @type int    $hops  Number of hops in the path.
	 * }
	 */
	public function find_shortest_path( $source_label, $target_label, $max_hops = 6 ) {
		$source_label = sanitize_text_field( $source_label );
		$target_label = sanitize_text_field( $target_label );
		$max_hops     = absint( $max_hops );

		if ( $max_hops < 1 ) {
			$max_hops = 6;
		}

		$source_id = $this->resolve_label_to_node_id( $source_label );
		$target_id = $this->resolve_label_to_node_id( $target_label );

		if ( null === $source_id || null === $target_id ) {
			return array(
				'found' => false,
				'path'  => array(),
				'edges' => array(),
				'hops'  => 0,
			);
		}

		// Trivial case: source is target.
		if ( $source_id === $target_id ) {
			return array(
				'found' => true,
				'path'  => array( $source_id ),
				'edges' => array(),
				'hops'  => 0,
			);
		}

		$adjacency  = $this->build_adjacency_list();
		$edge_index = $this->build_edge_index();

		// BFS.
		$visited = array( $source_id => true );
		$parent  = array( $source_id => null );
		$queue   = array( array( $source_id, 0 ) );

		$found = false;

		while ( ! empty( $queue ) ) {
			list( $current, $depth ) = array_shift( $queue );

			if ( $depth >= $max_hops ) {
				continue;
			}

			$neighbours = isset( $adjacency[ $current ] ) ? $adjacency[ $current ] : array();
			foreach ( $neighbours as $neighbour ) {
				if ( isset( $visited[ $neighbour ] ) ) {
					continue;
				}

				$visited[ $neighbour ] = true;
				$parent[ $neighbour ]  = $current;

				if ( $neighbour === $target_id ) {
					$found = true;
					break 2;
				}

				$queue[] = array( $neighbour, $depth + 1 );
			}
		}

		if ( ! $found ) {
			return array(
				'found' => false,
				'path'  => array(),
				'edges' => array(),
				'hops'  => 0,
			);
		}

		// Reconstruct path.
		$path = array();
		$node = $target_id;
		while ( null !== $node ) {
			array_unshift( $path, $node );
			$node = isset( $parent[ $node ] ) ? $parent[ $node ] : null;
		}

		// Build edge list along the path.
		$edges = array();
		for ( $i = 0, $len = count( $path ) - 1; $i < $len; $i++ ) {
			$src = $path[ $i ];
			$tgt = $path[ $i + 1 ];

			$relation = $this->lookup_edge_relation( $edge_index, $src, $tgt );

			$edges[] = array(
				'source'   => $src,
				'target'   => $tgt,
				'relation' => $relation,
			);
		}

		return array(
			'found' => true,
			'path'  => $path,
			'edges' => $edges,
			'hops'  => count( $edges ),
		);
	}

	/**
	 * Resolve a label to a node_id using LIKE search.
	 *
	 * Returns the first matching node_id, preferring exact matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label Label to search for.
	 * @return string|null Node ID or null if not found.
	 */
	private function resolve_label_to_node_id( $label ) {
		global $wpdb;

		if ( empty( $label ) ) {
			return null;
		}

		// Try exact match first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exact = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT node_id FROM {$this->nodes_table} WHERE graph_id = %d AND label = %s LIMIT 1",
				$this->graph_id,
				$label
			)
		);

		if ( null !== $exact ) {
			return $exact;
		}

		// Fall back to LIKE search.
		$like = '%' . $wpdb->esc_like( $label ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fuzzy = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT node_id FROM {$this->nodes_table} WHERE graph_id = %d AND label LIKE %s LIMIT 1",
				$this->graph_id,
				$like
			)
		);

		return $fuzzy;
	}

	/**
	 * Build a directed edge index keyed by "source|target" for relation lookup.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Edge key → relation map.
	 */
	private function build_edge_index() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id, relation FROM {$this->edges_table} WHERE graph_id = %d",
				$this->graph_id
			),
			ARRAY_A
		);

		$index = array();

		if ( ! empty( $edges ) ) {
			foreach ( $edges as $edge ) {
				$key           = $edge['source_node_id'] . '|' . $edge['target_node_id'];
				$index[ $key ] = $edge['relation'];
			}
		}

		return $index;
	}

	/**
	 * Look up the relation label for an edge, checking both directions.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $edge_index Edge index from build_edge_index().
	 * @param string $source     Source node ID.
	 * @param string $target     Target node ID.
	 * @return string Relation label, or 'related_to' as fallback.
	 */
	private function lookup_edge_relation( $edge_index, $source, $target ) {
		$key = $source . '|' . $target;
		if ( isset( $edge_index[ $key ] ) ) {
			return sanitize_text_field( $edge_index[ $key ] );
		}

		$reverse_key = $target . '|' . $source;
		if ( isset( $edge_index[ $reverse_key ] ) ) {
			return sanitize_text_field( $edge_index[ $reverse_key ] );
		}

		return 'related_to';
	}
}
