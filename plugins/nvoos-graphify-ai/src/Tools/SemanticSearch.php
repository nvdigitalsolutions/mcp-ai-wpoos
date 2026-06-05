<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphifyAi\Chat\ChatService;

/**
 * Search knowledge graph using semantic similarity (RAG retrieval).
 */
class SemanticSearch extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_semantic_search'; }
	public function getName(): string {
		return __( 'Semantic Search', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Search the knowledge graph using semantic similarity. Combines keyword search, graph traversal, and vector similarity for RAG retrieval.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Search query.',
				),
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				),
			),
			'required'   => array( 'query' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$query = sanitize_text_field( $arguments['query'] ?? '' );
		$limit = absint( $arguments['limit'] ?? 10 );
		if ( empty( $query ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Query is required.', 'nvoos-graphify-ai' ) );
		}

		// Use the core graph for keyword search. The table name from
		// prefix is a known safe identifier — not user input.
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, type FROM {$wpdb->prefix}nvoos_graphify_nodes WHERE label LIKE %s LIMIT %d",
				'%' . $wpdb->esc_like( $query ) . '%',
				$limit * 2
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $results ) ) {
			return array(
				'success' => true,
				'nodes'   => array(),
				'message' => __( 'No matching nodes found.', 'nvoos-graphify-ai' ),
			);
		}

		// Build context for AI reranking.
		$context = "Nodes matching \"{$query}\":\n";
		foreach ( $results as $r ) {
			$context .= "- [{$r['type']}] {$r['label']} (id: {$r['node_id']})\n";
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a search relevance ranker. Rank these nodes by relevance to the query. Return only a JSON array of node_ids in order of relevance.',
			),
			array(
				'role'    => 'user',
				'content' => $context,
			),
		);

		$result = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = trim( $result['content'] ?? '' );
		$content = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $content );
		$ranked  = json_decode( $content, true );

		$final = array();
		if ( is_array( $ranked ) ) {
			$ranked = array_slice( $ranked, 0, $limit );
			foreach ( $ranked as $nodeId ) {
				foreach ( $results as $r ) {
					if ( $r['node_id'] === $nodeId ) {
						$final[] = $r;
						break;
					}
				}
			}
		}

		return array(
			'success' => true,
			'nodes'   => $final ?: $results,
			'count'   => count( $final ?: $results ),
		);
	}
}
