<?php
/**
 * Tool for querying the knowledge graph via natural language.
 *
 * Extracts keywords from a question, finds matching nodes, performs
 * BFS or DFS traversal, and returns a content subgraph with a
 * human-readable summary.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query Knowledge Graph Tool.
 *
 * Converts a natural language question into a graph traversal,
 * returning the relevant subgraph as structured context for AI
 * reasoning about site architecture and content relationships.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Query_Graph implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Common English stop words excluded from keyword extraction.
	 *
	 * @var array
	 */
	private static $stop_words = array(
		'the', 'a', 'an', 'is', 'are', 'was', 'were',
		'in', 'on', 'at', 'to', 'for', 'of', 'with',
		'and', 'or', 'but', 'not', 'this', 'that',
		'what', 'how', 'which', 'where', 'who', 'why',
		'does', 'do', 'has', 'have', 'been',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_query_graph';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Query Knowledge Graph', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Navigates the WordPress knowledge graph using natural language questions. Extracts keywords, performs BFS/DFS traversal from matching nodes, and returns relevant content subgraph as context for AI reasoning about site architecture and content relationships.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'question'    => array(
					'type'        => 'string',
					'description' => __( "Natural language question about the site's content and relationships. Keywords will be extracted and used to search the graph.", 'mcp-ai-wpoos' ),
				),
				'mode'        => array(
					'type'        => 'string',
					'enum'        => array( 'bfs', 'dfs' ),
					'description' => __( 'Graph traversal mode. BFS (breadth-first) explores neighbors layer by layer. DFS (depth-first) follows paths deeply.', 'mcp-ai-wpoos' ),
					'default'     => 'bfs',
				),
				'depth'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum traversal depth from starting nodes.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 6,
					'default'     => 2,
				),
				'max_results' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of nodes to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
			),
			'required'             => array( 'question' ),
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
			'profession_tags'       => array( 'developer', 'content_strategist', 'seo_specialist', 'editor', 'writer' ),
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
				__( 'You do not have permission to query the knowledge graph.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['question'] ) ) {
			return new WP_Error(
				'missing_question',
				__( 'A question is required to query the knowledge graph.', 'mcp-ai-wpoos' )
			);
		}

		$question    = sanitize_text_field( $arguments['question'] );
		$mode        = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'bfs';
		$depth       = isset( $arguments['depth'] ) ? absint( $arguments['depth'] ) : 2;
		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 20;

		$depth       = max( 1, min( 6, $depth ) );
		$max_results = max( 1, min( 100, $max_results ) );

		$keywords = $this->extract_keywords( $question );

		if ( empty( $keywords ) ) {
			return new WP_Error(
				'no_keywords',
				__( 'Could not extract meaningful keywords from the question.', 'mcp-ai-wpoos' )
			);
		}

		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		$seed_nodes = $this->find_seed_nodes( $wpdb, $nodes_table, $graph_id, $keywords );

		if ( empty( $seed_nodes ) ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: the original question */
					__( 'No matching nodes found for "%s".', 'mcp-ai-wpoos' ),
					$question
				),
				'data'    => array(
					'question'       => $question,
					'keywords'       => $keywords,
					'traversal_mode' => $mode,
					'depth'          => $depth,
					'nodes'          => array(),
					'edges'          => array(),
					'summary'        => '',
				),
			);
		}

		if ( 'dfs' === $mode ) {
			$visited = $this->traverse_dfs( $wpdb, $edges_table, $seed_nodes, $depth, $max_results );
		} else {
			$visited = $this->traverse_bfs( $wpdb, $edges_table, $seed_nodes, $depth, $max_results );
		}

		$result_nodes = $this->fetch_node_details( $wpdb, $nodes_table, $edges_table, $graph_id, $visited );
		$result_edges = $this->fetch_edges_between( $wpdb, $edges_table, $graph_id, $visited );
		$summary      = $this->build_summary( $question, $result_nodes, $result_edges );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: node count, 2: the original question */
				__( 'Found %1$d related content nodes for "%2$s".', 'mcp-ai-wpoos' ),
				count( $result_nodes ),
				$question
			),
			'data'    => array(
				'question'       => $question,
				'keywords'       => $keywords,
				'traversal_mode' => $mode,
				'depth'          => $depth,
				'nodes'          => $result_nodes,
				'edges'          => $result_edges,
				'summary'        => $summary,
			),
		);
	}

	/**
	 * Extract meaningful keywords from a natural language question.
	 *
	 * Splits the question on whitespace, lowercases, removes stop words,
	 * and filters out words shorter than 3 characters.
	 *
	 * @param string $question The natural language question.
	 * @return array Unique keyword strings.
	 */
	private function extract_keywords( $question ) {
		$words = preg_split( '/\s+/', strtolower( $question ) );
		if ( ! is_array( $words ) ) {
			return array();
		}

		$keywords = array();
		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-z0-9]/', '', $word );
			if ( strlen( $word ) < 3 ) {
				continue;
			}
			if ( in_array( $word, self::$stop_words, true ) ) {
				continue;
			}
			$keywords[] = $word;
		}

		return array_values( array_unique( $keywords ) );
	}

	/**
	 * Find seed nodes whose labels match any of the given keywords.
	 *
	 * @param wpdb   $wpdb        WordPress database instance.
	 * @param string $nodes_table  Fully qualified nodes table name.
	 * @param string $graph_id     Current graph identifier.
	 * @param array  $keywords     Keywords to match against node labels.
	 * @return array Array of node ID strings.
	 */
	private function find_seed_nodes( $wpdb, $nodes_table, $graph_id, $keywords ) {
		$like_clauses = array();
		$values       = array( $graph_id );

		foreach ( $keywords as $keyword ) {
			$like_clauses[] = 'label LIKE %s';
			$values[]       = '%' . $wpdb->esc_like( $keyword ) . '%';
		}

		$where_likes = implode( ' OR ', $like_clauses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT node_id FROM {$nodes_table} WHERE graph_id = %d AND ( {$where_likes} ) LIMIT 50",
				$values
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Perform breadth-first traversal from seed nodes.
	 *
	 * @param wpdb   $wpdb        WordPress database instance.
	 * @param string $edges_table  Fully qualified edges table name.
	 * @param array  $seed_nodes   Starting node IDs.
	 * @param int    $depth        Maximum traversal depth.
	 * @param int    $max_results  Maximum nodes to collect.
	 * @return array Visited node IDs.
	 */
	private function traverse_bfs( $wpdb, $edges_table, $seed_nodes, $depth, $max_results ) {
		$visited       = array();
		$queue         = array();

		foreach ( $seed_nodes as $node_id ) {
			$queue[]             = array( 'id' => $node_id, 'depth' => 0 );
			$visited[ $node_id ] = true;
		}

		while ( ! empty( $queue ) && count( $visited ) < $max_results ) {
			$current = array_shift( $queue );

			if ( $current['depth'] >= $depth ) {
				continue;
			}

			$neighbors = $this->get_neighbors( $wpdb, $edges_table, $current['id'] );

			foreach ( $neighbors as $neighbor_id ) {
				if ( isset( $visited[ $neighbor_id ] ) ) {
					continue;
				}
				$visited[ $neighbor_id ] = true;
				$queue[]                 = array( 'id' => $neighbor_id, 'depth' => $current['depth'] + 1 );

				if ( count( $visited ) >= $max_results ) {
					break;
				}
			}
		}

		return array_keys( $visited );
	}

	/**
	 * Perform depth-first traversal from seed nodes.
	 *
	 * @param wpdb   $wpdb        WordPress database instance.
	 * @param string $edges_table  Fully qualified edges table name.
	 * @param array  $seed_nodes   Starting node IDs.
	 * @param int    $depth        Maximum traversal depth.
	 * @param int    $max_results  Maximum nodes to collect.
	 * @return array Visited node IDs.
	 */
	private function traverse_dfs( $wpdb, $edges_table, $seed_nodes, $depth, $max_results ) {
		$visited = array();
		$stack   = array();

		foreach ( $seed_nodes as $node_id ) {
			$stack[]             = array( 'id' => $node_id, 'depth' => 0 );
			$visited[ $node_id ] = true;
		}

		while ( ! empty( $stack ) && count( $visited ) < $max_results ) {
			$current = array_pop( $stack );

			if ( $current['depth'] >= $depth ) {
				continue;
			}

			$neighbors = $this->get_neighbors( $wpdb, $edges_table, $current['id'] );

			foreach ( $neighbors as $neighbor_id ) {
				if ( isset( $visited[ $neighbor_id ] ) ) {
					continue;
				}
				$visited[ $neighbor_id ] = true;
				$stack[]                 = array( 'id' => $neighbor_id, 'depth' => $current['depth'] + 1 );

				if ( count( $visited ) >= $max_results ) {
					break;
				}
			}
		}

		return array_keys( $visited );
	}

	/**
	 * Get neighbor node IDs connected to a given node via edges.
	 *
	 * @param wpdb   $wpdb        WordPress database instance.
	 * @param string $edges_table  Fully qualified edges table name.
	 * @param string $node_id      Node identifier.
	 * @return array Neighbor node IDs.
	 */
	private function get_neighbors( $wpdb, $edges_table, $node_id ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CASE WHEN source_node_id = %s THEN target_node_id ELSE source_node_id END AS neighbor_id FROM {$edges_table} WHERE source_node_id = %s OR target_node_id = %s",
				$node_id,
				$node_id,
				$node_id
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Fetch node details for all visited nodes.
	 *
	 * @param wpdb   $wpdb        WordPress database instance.
	 * @param string $nodes_table  Fully qualified nodes table name.
	 * @param string $edges_table  Fully qualified edges table name.
	 * @param string $graph_id     Current graph identifier.
	 * @param array  $node_ids     Node IDs to fetch.
	 * @return array Node detail arrays with id, label, type, source_url, degree.
	 */
	private function fetch_node_details( $wpdb, $nodes_table, $edges_table, $graph_id, $node_ids ) {
		if ( empty( $node_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $node_ids ), '%s' ) );
		$values       = array_merge( array( $graph_id ), $node_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, source_url, degree FROM {$nodes_table} WHERE graph_id = %d AND node_id IN ( {$placeholders} )",
				$values
			)
		);

		$nodes = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$nodes[] = array(
					'id'         => $row->node_id,
					'label'      => $row->label,
					'type'       => $row->node_type,
					'source_url' => $row->source_url,
					'degree'     => (int) $row->degree,
				);
			}
		}

		return $nodes;
	}

	/**
	 * Fetch edges that connect any two visited nodes.
	 *
	 * @param wpdb   $wpdb        WordPress database instance.
	 * @param string $edges_table  Fully qualified edges table name.
	 * @param string $graph_id     Current graph identifier.
	 * @param array  $node_ids     Visited node IDs.
	 * @return array Edge detail arrays with source, target, relation, confidence.
	 */
	private function fetch_edges_between( $wpdb, $edges_table, $graph_id, $node_ids ) {
		if ( count( $node_ids ) < 2 ) {
			return array();
		}

		$placeholders_source = implode( ',', array_fill( 0, count( $node_ids ), '%s' ) );
		$placeholders_target = implode( ',', array_fill( 0, count( $node_ids ), '%s' ) );
		$values              = array_merge( array( $graph_id ), $node_ids, $node_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id, relation, confidence FROM {$edges_table} WHERE graph_id = %d AND source_node_id IN ( {$placeholders_source} ) AND target_node_id IN ( {$placeholders_target} )",
				$values
			)
		);

		$edges = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$edges[] = array(
					'source'     => $row->source_node_id,
					'target'     => $row->target_node_id,
					'relation'   => $row->relation,
					'confidence' => $row->confidence,
				);
			}
		}

		return $edges;
	}

	/**
	 * Build a human-readable text summary of the subgraph.
	 *
	 * Limited to the first 10 connections to keep the summary concise.
	 *
	 * @param string $question     The original question.
	 * @param array  $nodes        Node detail arrays.
	 * @param array  $edges        Edge detail arrays.
	 * @return string Summary text.
	 */
	private function build_summary( $question, $nodes, $edges ) {
		if ( empty( $edges ) ) {
			if ( empty( $nodes ) ) {
				return '';
			}
			$labels = array();
			foreach ( array_slice( $nodes, 0, 10 ) as $node ) {
				$labels[] = $node['label'] . ' (' . $node['type'] . ')';
			}
			return sprintf(
				/* translators: 1: the question, 2: comma-separated node labels */
				__( "Content Map for '%1\$s': Found nodes — %2\$s.", 'mcp-ai-wpoos' ),
				$question,
				implode( ', ', $labels )
			);
		}

		$node_map = array();
		foreach ( $nodes as $node ) {
			$node_map[ $node['id'] ] = $node;
		}

		$lines = array();
		$count = 0;

		foreach ( $edges as $edge ) {
			if ( $count >= 10 ) {
				break;
			}

			$source_label = isset( $node_map[ $edge['source'] ] ) ? $node_map[ $edge['source'] ]['label'] : $edge['source'];
			$source_type  = isset( $node_map[ $edge['source'] ] ) ? $node_map[ $edge['source'] ]['type'] : 'unknown';
			$target_label = isset( $node_map[ $edge['target'] ] ) ? $node_map[ $edge['target'] ]['label'] : $edge['target'];

			$lines[] = sprintf(
				'%s (%s) connects to %s via %s',
				$source_label,
				$source_type,
				$target_label,
				$edge['relation']
			);

			++$count;
		}

		return sprintf(
			/* translators: 1: the question, 2: connection descriptions */
			__( "Content Map for '%1\$s': %2\$s.", 'mcp-ai-wpoos' ),
			$question,
			implode( '. ', $lines )
		);
	}
}
