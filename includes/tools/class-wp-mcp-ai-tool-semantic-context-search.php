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
				'agent_id'             => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'query'                => array(
					'type'        => 'string',
					'description' => __( 'Natural language search query to find semantically similar contexts', 'mcp-ai-wpoos' ),
				),
				'filters'              => array(
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
				'limit'                => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'vector_store_id'      => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI Vector Store ID to include in the semantic search alongside local agent memory. When provided or when the assistant has a configured vector store, the search will also query the vector store file index.', 'mcp-ai-wpoos' ),
				),
				'include_vector_store' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to automatically include the assistant\'s configured vector store in the search. Defaults to true when a vector store is configured.', 'mcp-ai-wpoos' ),
					'default'     => true,
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
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$api_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		if ( empty( $api_key ) ) {
			return array(
				'success'  => false,
				'message'  => __( 'OpenAI API key is required for semantic search. Please configure it in plugin settings or use retrieve_agent_memory for keyword-based search.', 'mcp-ai-wpoos' ),
				'fallback' => 'retrieve_agent_memory',
			);
		}

		// Sanitize inputs.
		$agent_id             = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$query                = sanitize_text_field( $arguments['query'] );
		$filters              = isset( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();
		$limit                = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$include_vector_store = isset( $arguments['include_vector_store'] ) ? (bool) $arguments['include_vector_store'] : true;

		// Resolve vector store ID: explicit argument > assistant context.
		$vector_store_id = '';
		if ( ! empty( $arguments['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $arguments['vector_store_id'] );
		} elseif ( $include_vector_store && ! empty( $context['assistant_config']['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $context['assistant_config']['vector_store_id'] );
		}

		// Validate limit bounds.
		$limit = max( 1, min( 50, $limit ) );

		// Get vector context service.
		$vector_service = WP_MCP_AI_Vector_Context_Service::get_instance();

		// Perform local semantic search against stored agent contexts.
		$result = $vector_service->search_context( $query, $agent_id, $limit, $filters );

		if ( ! $result['success'] ) {
			return array(
				'success'  => false,
				'message'  => isset( $result['error'] ) ? $result['error'] : __( 'Semantic search failed.', 'mcp-ai-wpoos' ),
				'fallback' => 'retrieve_agent_memory',
			);
		}

		$contexts          = isset( $result['contexts'] ) ? $result['contexts'] : array();
		$vector_store_info = null;

		// Optionally enrich results with Vector Store file index metadata.
		if ( ! empty( $vector_store_id ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$vs_result = $this->search_vector_store_index( $vector_store_id );
			if ( is_array( $vs_result ) && ! empty( $vs_result['files'] ) ) {
				$vector_store_info = $vs_result;
			}
		}

		$total_count = count( $contexts );
		$response    = array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: %d: number of contexts found */
				_n( 'Found %d semantically similar context.', 'Found %d semantically similar contexts.', $total_count, 'mcp-ai-wpoos' ),
				$total_count
			),
			'contexts' => $contexts,
			'count'    => $total_count,
			'query'    => $query,
			'method'   => 'semantic_similarity',
			'model'    => WP_MCP_AI_Vector_Context_Service::EMBEDDING_MODEL,
		);

		if ( null !== $vector_store_info ) {
			$response['vector_store'] = $vector_store_info;
			$response['message']     .= ' ' . sprintf(
				/* translators: 1: vector store ID, 2: file count */
				_n(
					'Vector Store %1$s has %2$d indexed file.',
					'Vector Store %1$s has %2$d indexed files.',
					count( $vector_store_info['files'] ),
					'mcp-ai-wpoos'
				),
				$vector_store_id,
				count( $vector_store_info['files'] )
			);
		}

		return $response;
	}

	/**
	 * Retrieve Vector Store file index metadata to enrich semantic search context.
	 *
	 * Queries the OpenAI API to list files associated with the given vector store.
	 * This provides file-level metadata (IDs, status) that the assistant can use
	 * to understand what knowledge is indexed.
	 *
	 * @param string $vector_store_id OpenAI Vector Store ID.
	 * @return array|null Associative array with 'vector_store_id' and 'files', or null on failure.
	 */
	private function search_vector_store_index( $vector_store_id ) {
		$client     = new WP_MCP_AI_OpenAI_Client();
		$files_data = $client->list_vector_store_files( $vector_store_id, array( 'limit' => 20 ) );

		if ( is_wp_error( $files_data ) || ! isset( $files_data['data'] ) ) {
			return null;
		}

		$files = array();
		foreach ( $files_data['data'] as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$files[] = array(
				'id'     => isset( $file['id'] ) ? sanitize_text_field( $file['id'] ) : '',
				'status' => isset( $file['status'] ) ? sanitize_text_field( $file['status'] ) : 'unknown',
			);
		}

		return array(
			'vector_store_id' => $vector_store_id,
			'files'           => $files,
			'has_more'        => isset( $files_data['has_more'] ) ? (bool) $files_data['has_more'] : false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'data_analytics',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'data_scientist', 'researcher' ),

			'risk_level'            => 'info',

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
