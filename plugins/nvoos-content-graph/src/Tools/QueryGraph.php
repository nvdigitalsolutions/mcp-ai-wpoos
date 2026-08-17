<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tools;

use function absint;
use function sanitize_text_field;
use function __;

/**
 * Tool: nvoos_content_graph_query_graph
 *
 * BFS/DFS traversal from keyword-matched seed nodes.
 *
 * @since 1.0.0
 */
class QueryGraph extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'nvoos_content_graph_query_graph';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return __( 'Query Knowledge Graph', 'nvoos-content-graph' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string {
		return __( 'Traverse the knowledge graph from seed nodes matching a keyword search. Returns the subgraph (nodes and edges) reachable within a given depth. Use mode="bfs" for breadth-first (wide exploration) or mode="dfs" for depth-first (deep paths). Returns a text summary, seed node list, subgraph nodes, and edges.', 'nvoos-content-graph' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'question'  => array(
					'type'        => 'string',
					'description' => __( 'Natural language question or keyword to search for seed nodes (e.g. "SEO", "WordPress performance", "product launch").', 'nvoos-content-graph' ),
					'minLength'   => 1,
					'maxLength'   => 500,
				),
				'mode'      => array(
					'type'        => 'string',
					'enum'        => array( 'bfs', 'dfs' ),
					'description' => __( 'Traversal mode: bfs (breadth-first, default) or dfs (depth-first).', 'nvoos-content-graph' ),
					'default'     => 'bfs',
				),
				'depth'     => array(
					'type'        => 'integer',
					'description' => __( 'How many hops from seed nodes to traverse (1–5, default: 2).', 'nvoos-content-graph' ),
					'minimum'     => 1,
					'maximum'     => 5,
					'default'     => 2,
				),
				'max_nodes' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of nodes to return (default: 50).', 'nvoos-content-graph' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
			),
			'required'             => array( 'question' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCapabilityFlags(): array {
		return array( 'read-only', 'cacheable' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$question  = sanitize_text_field( $arguments['question'] ?? '' );
		$mode      = isset( $arguments['mode'] ) && 'dfs' === $arguments['mode'] ? 'dfs' : 'bfs';
		$depth     = isset( $arguments['depth'] ) ? max( 1, min( 5, absint( $arguments['depth'] ) ) ) : 2;
		$max_nodes = isset( $arguments['max_nodes'] ) ? max( 1, min( 200, absint( $arguments['max_nodes'] ) ) ) : 50;

		if ( ! $question ) {
			return new \WP_Error(
				'query_graph_question_required',
				__( 'A question or search keyword is required.', 'nvoos-content-graph' )
			);
		}

		// Find seed nodes via label search.
		$seeds = \NvoosContentGraph\Graph\Db::searchNodes( $question, '', 5 );
		if ( empty( $seeds ) ) {
			return new \WP_Error(
				'query_graph_no_nodes',
				__( 'No nodes found matching that query. Try building the graph first with nvoos_content_graph_build_graph.', 'nvoos-content-graph' )
			);
		}

		// Traverse from each seed node and merge results.
		$all_node_ids = array();
		$all_edges    = array();
		foreach ( $seeds as $seed ) {
			$subgraph = \NvoosContentGraph\Graph\Analyzer::traverse( $seed->node_id, $depth, $mode, $max_nodes );
			foreach ( $subgraph['nodes'] as $n ) {
				$all_node_ids[ $n->node_id ] = $n;
			}
			foreach ( $subgraph['edges'] as $e ) {
				$ekey               = $e->source_node_id . '|' . $e->target_node_id . '|' . $e->relation;
				$all_edges[ $ekey ] = $e;
			}
		}

		$nodes = array_values( $all_node_ids );
		$edges = array_values( $all_edges );

		$summary = sprintf(
			/* translators: 1: question, 2: node count, 3: edge count, 4: traversal mode, 5: depth */
			__( 'Found %2$d nodes and %3$d edges related to "%1$s" via %4$s traversal (depth %5$d).', 'nvoos-content-graph' ),
			$question,
			count( $nodes ),
			count( $edges ),
			strtoupper( $mode ),
			$depth
		);

		return array(
			'success'    => true,
			'question'   => $question,
			'mode'       => $mode,
			'depth'      => $depth,
			'seed_nodes' => array_map(
				function ( $n ) {
					return array(
						'node_id' => $n->node_id,
						'label'   => $n->label,
					);
				},
				$seeds
			),
			'node_count' => count( $nodes ),
			'edge_count' => count( $edges ),
			'nodes'      => $nodes,
			'edges'      => $edges,
			'summary'    => $summary,
		);
	}
}
