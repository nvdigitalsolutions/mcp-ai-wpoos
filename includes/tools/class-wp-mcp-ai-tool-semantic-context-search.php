<?php
/**
 * Tool for semantic search of agent contexts using vector embeddings.
 *
 * Provides AI-powered semantic search beyond keyword matching.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 5.5).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Semantic search for agent contexts using vector embeddings.
 *
 * This tool enables semantic understanding of context relevance using
 * OpenAI embeddings and cosine similarity.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Semantic_Context_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'semantic_context_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Semantic Context Search', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search agent contexts using semantic similarity based on vector embeddings. More accurate than keyword matching for understanding context relevance. Requires OpenAI API key for embedding generation.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'query'    => array(
					'type'        => 'string',
					'description' => __( 'Natural language search query to find semantically similar contexts', 'mcp-ai-wpoos' ),
				),
				'filters'  => array(
					'type'        => 'object',
					'description' => __( 'Optional filters to narrow results', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'context_types' => array(
							'type'        => 'array',
							'description' => __( 'Filter by context types', 'mcp-ai-wpoos' ),
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'learning', 'fact', 'preference', 'pattern', 'workflow', 'decision', 'result', 'insight', 'note', 'generic' ),
							),
						),
						'importance'    => array(
							'type'        => 'array',
							'description' => __( 'Filter by importance levels', 'mcp-ai-wpoos' ),
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'low', 'medium', 'high', 'critical' ),
							),
						),
					),
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
			'required'             => array( 'agent_id', 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['agent_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent ID is required.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['query'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Search query is required.', 'mcp-ai-wpoos' ),
			);
		}

		// Check if OpenAI is configured.
		$api_key = get_option( 'wp_mcp_ai_openai_api_key' );
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'OpenAI API key is required for semantic search. Please configure it in plugin settings or use retrieve_agent_memory for keyword-based search.', 'mcp-ai-wpoos' ),
				'fallback' => 'retrieve_agent_memory',
			);
		}

		// Sanitize inputs.
		$agent_id = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$query    = sanitize_text_field( $arguments['query'] );
		$filters  = isset( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();
		$limit    = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;

		// Validate limit bounds.
		$limit = max( 1, min( 50, $limit ) );

		// Get vector context service.
		$vector_service = WP_MCP_AI_Vector_Context_Service::get_instance();

		// Perform semantic search.
		$result = $vector_service->search_context( $query, $agent_id, $limit, $filters );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => isset( $result['error'] ) ? $result['error'] : __( 'Semantic search failed.', 'mcp-ai-wpoos' ),
				'fallback' => 'retrieve_agent_memory',
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: %d: number of contexts found */
				_n( 'Found %d semantically similar context.', 'Found %d semantically similar contexts.', $result['count'], 'mcp-ai-wpoos' ),
				$result['count']
			),
			'contexts' => $result['contexts'],
			'count'    => $result['count'],
			'query'    => $query,
			'method'   => 'semantic_similarity',
			'model'    => WP_MCP_AI_Vector_Context_Service::EMBEDDING_MODEL,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,  // Read-only operation.
			'local-only'        => false, // Uses external API (OpenAI).
			'read-only'         => true,  // Does not modify data.
			'idempotent'        => true,  // Same input = same output.
			'cacheable'         => true,  // Results can be cached.
			'requires-auth'     => true,  // Needs user authentication.
			'blocking'          => false, // API call may take time.
			'uses-network'      => true,  // Calls OpenAI API.
			'modifies-wp'       => false, // Does not modify data.
			'expensive'         => true,  // Uses paid API (embeddings).
			'requires-approval' => false, // Auto-approved.
		);
	}
}
