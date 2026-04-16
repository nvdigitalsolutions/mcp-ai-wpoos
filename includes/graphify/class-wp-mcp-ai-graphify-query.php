<?php
/**
 * Graphify Knowledge Graph — Graph Query Engine
 *
 * BFS/DFS traversal, keyword search, shortest path, and text rendering
 * for feeding graph context to LLMs. Adapted from Graphify's MCP query tools.
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
 * Traverses the knowledge graph and renders subgraphs as text for LLM consumption.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Query {

	/**
	 * Search nodes by keyword matching against labels.
	 *
	 * @param string $query    Search keyword(s).
	 * @param int    $graph_id Graph ID.
	 * @param int    $limit    Max results.
	 * @return array Matching node rows.
	 */
	public function search_nodes( $query, $graph_id = 1, $limit = 20 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		return $db::get_nodes(
			array(
				'graph_id' => $graph_id,
				'search'   => $query,
				'orderby'  => 'degree',
				'order'    => 'DESC',
				'limit'    => $limit,
			)
		);
	}

	/**
	 * BFS traversal from start nodes.
	 *
	 * @param array $start_node_ids Array of node_id strings to start from.
	 * @param int   $depth          Max depth to traverse. Default 2.
	 * @param int   $graph_id       Graph ID.
	 * @return array {
	 *     @type array $nodes Array of node rows encountered.
	 *     @type array $edges Array of edge rows traversed.
	 * }
	 */
	public function bfs( $start_node_ids, $depth = 2, $graph_id = 1 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		$visited_nodes = array();
		$collected_edges = array();
		$queue          = array();

		// Initialize queue: (node_id, current_depth).
		foreach ( $start_node_ids as $nid ) {
			$queue[] = array( $nid, 0 );
			$visited_nodes[ $nid ] = true;
		}

		while ( ! empty( $queue ) ) {
			$item          = array_shift( $queue );
			$current_nid   = $item[0];
			$current_depth = $item[1];

			if ( $current_depth >= $depth ) {
				continue;
			}

			$edges = $db::get_edges_for_node( $current_nid, $graph_id );

			foreach ( $edges as $edge ) {
				$collected_edges[ $edge['id'] ] = $edge;

				$neighbor = ( $edge['source_node_id'] === $current_nid )
					? $edge['target_node_id']
					: $edge['source_node_id'];

				if ( ! isset( $visited_nodes[ $neighbor ] ) ) {
					$visited_nodes[ $neighbor ] = true;
					$queue[] = array( $neighbor, $current_depth + 1 );
				}
			}
		}

		// Fetch full node data for visited nodes.
		$node_data = array();
		foreach ( array_keys( $visited_nodes ) as $nid ) {
			$node = $db::get_node( $nid, $graph_id );
			if ( $node ) {
				$node_data[] = $node;
			}
		}

		return array(
			'nodes' => $node_data,
			'edges' => array_values( $collected_edges ),
		);
	}

	/**
	 * DFS traversal from start nodes.
	 *
	 * @param array $start_node_ids Array of node_id strings.
	 * @param int   $depth          Max depth. Default 2.
	 * @param int   $graph_id       Graph ID.
	 * @return array Same structure as bfs().
	 */
	public function dfs( $start_node_ids, $depth = 2, $graph_id = 1 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		$visited_nodes   = array();
		$collected_edges = array();

		foreach ( $start_node_ids as $nid ) {
			$this->dfs_recursive( $nid, 0, $depth, $graph_id, $db, $visited_nodes, $collected_edges );
		}

		// Fetch full node data.
		$node_data = array();
		foreach ( array_keys( $visited_nodes ) as $nid ) {
			$node = $db::get_node( $nid, $graph_id );
			if ( $node ) {
				$node_data[] = $node;
			}
		}

		return array(
			'nodes' => $node_data,
			'edges' => array_values( $collected_edges ),
		);
	}

	/**
	 * Recursive DFS helper.
	 *
	 * @param string $node_id         Current node.
	 * @param int    $current_depth   Current recursion depth.
	 * @param int    $max_depth       Max depth.
	 * @param int    $graph_id        Graph ID.
	 * @param string $db              Database class name.
	 * @param array  $visited_nodes   Visited map (by reference).
	 * @param array  $collected_edges Collected edges (by reference).
	 * @return void
	 */
	protected function dfs_recursive( $node_id, $current_depth, $max_depth, $graph_id, $db, &$visited_nodes, &$collected_edges ) {
		if ( isset( $visited_nodes[ $node_id ] ) ) {
			return;
		}

		$visited_nodes[ $node_id ] = true;

		if ( $current_depth >= $max_depth ) {
			return;
		}

		$edges = $db::get_edges_for_node( $node_id, $graph_id );

		foreach ( $edges as $edge ) {
			$collected_edges[ $edge['id'] ] = $edge;

			$neighbor = ( $edge['source_node_id'] === $node_id )
				? $edge['target_node_id']
				: $edge['source_node_id'];

			$this->dfs_recursive( $neighbor, $current_depth + 1, $max_depth, $graph_id, $db, $visited_nodes, $collected_edges );
		}
	}

	/**
	 * Find shortest path between two nodes using BFS.
	 *
	 * @param string $source   Source node_id.
	 * @param string $target   Target node_id.
	 * @param int    $graph_id Graph ID.
	 * @param int    $max_hops Maximum hops. Default 6.
	 * @return array|null Path as array of node_ids, or null if not found.
	 */
	public function shortest_path( $source, $target, $graph_id = 1, $max_hops = 6 ) {
		$db = 'WP_MCP_AI_Graphify_Database';

		if ( $source === $target ) {
			return array( $source );
		}

		$visited = array( $source => true );
		$parent  = array( $source => null );
		$queue   = array( array( $source, 0 ) );

		while ( ! empty( $queue ) ) {
			$item  = array_shift( $queue );
			$current = $item[0];
			$hops    = $item[1];

			if ( $hops >= $max_hops ) {
				continue;
			}

			$neighbors = $db::get_neighbor_ids( $current, $graph_id );

			foreach ( $neighbors as $neighbor ) {
				if ( isset( $visited[ $neighbor ] ) ) {
					continue;
				}

				$visited[ $neighbor ] = true;
				$parent[ $neighbor ]  = $current;

				if ( $neighbor === $target ) {
					// Reconstruct path.
					$path = array();
					$node = $target;
					while ( null !== $node ) {
						array_unshift( $path, $node );
						$node = $parent[ $node ];
					}
					return $path;
				}

				$queue[] = array( $neighbor, $hops + 1 );
			}
		}

		return null; // No path found.
	}

	/**
	 * Render a subgraph as formatted text for LLM context.
	 *
	 * @param array $nodes        Array of node rows.
	 * @param array $edges        Array of edge rows.
	 * @param int   $token_budget Approximate max tokens (1 token ≈ 4 chars). Default 4000.
	 * @return string Text representation of the subgraph.
	 */
	public function subgraph_to_text( $nodes, $edges, $token_budget = 4000 ) {
		$char_budget = $token_budget * 4;
		$lines       = array();

		// Build node label lookup.
		$labels = array();
		foreach ( $nodes as $node ) {
			$labels[ $node['node_id'] ] = $node['label'];
		}

		// Section: Nodes.
		$lines[] = '## Knowledge Graph Context';
		$lines[] = '';
		$lines[] = '### Nodes (' . count( $nodes ) . ')';

		foreach ( $nodes as $node ) {
			$line = '- **' . $node['label'] . '** [' . $node['node_type'] . '] (degree: ' . $node['degree'] . ')';
			if ( ! empty( $node['source_url'] ) ) {
				$line .= ' — ' . $node['source_url'];
			}
			$lines[] = $line;
		}

		$lines[] = '';
		$lines[] = '### Relationships (' . count( $edges ) . ')';

		foreach ( $edges as $edge ) {
			$src_label = isset( $labels[ $edge['source_node_id'] ] ) ? $labels[ $edge['source_node_id'] ] : $edge['source_node_id'];
			$tgt_label = isset( $labels[ $edge['target_node_id'] ] ) ? $labels[ $edge['target_node_id'] ] : $edge['target_node_id'];

			$lines[] = '- ' . $src_label . ' —[' . $edge['relation'] . ']→ ' . $tgt_label . ' (' . $edge['confidence'] . ')';
		}

		$text = implode( "\n", $lines );

		// Truncate to budget if needed.
		if ( strlen( $text ) > $char_budget ) {
			$text = substr( $text, 0, $char_budget );
			$text .= "\n\n[...truncated to token budget]";
		}

		return $text;
	}

	/**
	 * Query the graph using a natural language question.
	 *
	 * Extracts keywords, finds matching nodes, then traverses via BFS/DFS.
	 *
	 * @param string $question     Natural language question.
	 * @param string $mode         Traversal mode: 'bfs' or 'dfs'. Default 'bfs'.
	 * @param int    $depth        Traversal depth. Default 2.
	 * @param int    $token_budget Token budget for text output. Default 4000.
	 * @param int    $graph_id     Graph ID.
	 * @return array {
	 *     @type string $context       Text representation for LLM.
	 *     @type int    $nodes_found   Number of nodes in subgraph.
	 *     @type int    $edges_found   Number of edges in subgraph.
	 *     @type array  $anchor_nodes  Starting node labels.
	 * }
	 */
	public function query( $question, $mode = 'bfs', $depth = 2, $token_budget = 4000, $graph_id = 1 ) {
		// Extract keywords from question (simple tokenization).
		$keywords = $this->extract_keywords( $question );

		if ( empty( $keywords ) ) {
			return array(
				'context'      => '',
				'nodes_found'  => 0,
				'edges_found'  => 0,
				'anchor_nodes' => array(),
				'message'      => __( 'Could not extract meaningful keywords from the question.', 'mcp-ai-wpoos' ),
			);
		}

		// Search for matching nodes.
		$anchor_nodes = array();
		foreach ( $keywords as $keyword ) {
			$matches = $this->search_nodes( $keyword, $graph_id, 5 );
			foreach ( $matches as $match ) {
				$anchor_nodes[ $match['node_id'] ] = $match;
			}
		}

		if ( empty( $anchor_nodes ) ) {
			return array(
				'context'      => '',
				'nodes_found'  => 0,
				'edges_found'  => 0,
				'anchor_nodes' => array(),
				'message'      => sprintf(
					/* translators: %s: search keywords */
					__( 'No nodes found matching: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $keywords )
				),
			);
		}

		// Traverse from anchor nodes.
		$start_ids = array_keys( $anchor_nodes );

		if ( 'dfs' === $mode ) {
			$subgraph = $this->dfs( $start_ids, $depth, $graph_id );
		} else {
			$subgraph = $this->bfs( $start_ids, $depth, $graph_id );
		}

		// Render to text.
		$context = $this->subgraph_to_text( $subgraph['nodes'], $subgraph['edges'], $token_budget );

		$anchor_labels = array();
		foreach ( $anchor_nodes as $node ) {
			$anchor_labels[] = $node['label'];
		}

		return array(
			'context'      => $context,
			'nodes_found'  => count( $subgraph['nodes'] ),
			'edges_found'  => count( $subgraph['edges'] ),
			'anchor_nodes' => $anchor_labels,
		);
	}

	/**
	 * Extract keywords from a natural language question.
	 *
	 * Simple approach: tokenize, remove stop words, keep significant terms.
	 *
	 * @param string $question Input question.
	 * @return array Array of keyword strings.
	 */
	protected function extract_keywords( $question ) {
		// Normalize.
		$text = strtolower( $question );
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$words = preg_split( '/\s+/', trim( $text ) );

		// Common stop words to filter out.
		$stop_words = array(
			'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
			'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
			'should', 'may', 'might', 'can', 'shall', 'must', 'need',
			'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she', 'it',
			'they', 'them', 'their', 'this', 'that', 'these', 'those',
			'what', 'which', 'who', 'whom', 'when', 'where', 'why', 'how',
			'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from',
			'about', 'between', 'through', 'during', 'before', 'after',
			'and', 'or', 'but', 'not', 'no', 'if', 'then', 'else',
			'all', 'each', 'every', 'any', 'some', 'many', 'much',
			'more', 'most', 'very', 'just', 'also', 'only', 'so', 'too',
			'tell', 'show', 'find', 'get', 'give', 'know', 'think', 'see',
			'want', 'look', 'make', 'go', 'come', 'take', 'use',
			'related', 'content', 'posts', 'pages', 'site', 'blog', 'graph',
		);

		$keywords = array();
		foreach ( $words as $word ) {
			if ( strlen( $word ) >= 3 && ! in_array( $word, $stop_words, true ) ) {
				$keywords[] = $word;
			}
		}

		// Limit to top 5 keywords.
		return array_slice( array_unique( $keywords ), 0, 5 );
	}
}
