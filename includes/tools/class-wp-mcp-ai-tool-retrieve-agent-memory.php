<?php
/**
 * Tool for retrieving agent memory/context.
 *
 * Allows AI assistants to retrieve previously stored context.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 4/5).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves previously stored agent context/memory.
 *
 * This tool enables AI models to retrieve context that was previously stored
 * using store_agent_context. Supports searching by context ID, agent ID,
 * context type, tags, or semantic search query.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Retrieve_Agent_Memory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'retrieve_agent_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Retrieve Agent Memory', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves previously stored agent context and memory. Search by context ID for specific retrieval, or by agent ID, type, tags, and query for semantic search. Returns relevant contexts ranked by relevance and importance.', 'mcp-ai-wpoos' );
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
				'context_id'           => array(
					'type'        => 'string',
					'description' => __( 'Specific context ID to retrieve (if known)', 'mcp-ai-wpoos' ),
				),
				'query'                => array(
					'type'        => 'string',
					'description' => __( 'Search query for semantic matching against stored contexts', 'mcp-ai-wpoos' ),
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
						'tags'          => array(
							'type'        => 'array',
							'description' => __( 'Filter by tags (any match)', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'importance'    => array(
							'type'        => 'array',
							'description' => __( 'Filter by importance levels', 'mcp-ai-wpoos' ),
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'low', 'medium', 'high', 'critical' ),
							),
						),
						'after_date'    => array(
							'type'        => 'string',
							'description' => __( 'Only contexts stored after this date (YYYY-MM-DD)', 'mcp-ai-wpoos' ),
						),
						'before_date'   => array(
							'type'        => 'string',
							'description' => __( 'Only contexts stored before this date (YYYY-MM-DD)', 'mcp-ai-wpoos' ),
						),
						'wing'          => array(
							'type'        => 'string',
							'description' => __( 'Only contexts stored under this wing (project/person scope). Applied before semantic ranking.', 'mcp-ai-wpoos' ),
						),
						'room'          => array(
							'type'        => 'string',
							'description' => __( 'Only contexts stored under this room (topic cluster within a wing). Applied before semantic ranking.', 'mcp-ai-wpoos' ),
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
				'include_expired'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include expired contexts', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_vector_store' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include Vector Store file metadata in the response when the assistant has a vector store configured. Defaults to true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'agent_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			return new WP_Error( 'wp_mcp_ai_error', __( 'Agent ID is required.', 'mcp-ai-wpoos' ) );
		}

		// Sanitize inputs.
		$agent_id             = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$context_id           = isset( $arguments['context_id'] ) ? sanitize_text_field( $arguments['context_id'] ) : null;
		$query                = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$filters              = isset( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();
		$limit                = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$include_expired      = isset( $arguments['include_expired'] ) ? (bool) $arguments['include_expired'] : false;
		$include_vector_store = isset( $arguments['include_vector_store'] ) ? (bool) $arguments['include_vector_store'] : true;

		// Validate limit bounds.
		$limit = max( 1, min( 50, $limit ) );

		// Resolve vector store ID from assistant context when requested.
		$vector_store_id = '';
		if ( $include_vector_store && ! empty( $context['assistant_config']['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $context['assistant_config']['vector_store_id'] );
		}

		// If context_id is provided, retrieve specific context.
		if ( $context_id ) {
			$specific_result = $this->retrieve_specific_context( $agent_id, $context_id, $include_expired );
			// retrieve_specific_context() returns WP_Error when the context is
			// missing or expired — return it untouched instead of indexing it.
			if ( is_wp_error( $specific_result ) ) {
				return $specific_result;
			}
			if ( ! empty( $vector_store_id ) ) {
				$specific_result['vector_store_id'] = $vector_store_id;
			}
			return $specific_result;
		}

		// Otherwise, search all contexts for this agent.
		$search_result = $this->search_contexts( $agent_id, $query, $filters, $limit, $include_expired );
		if ( is_wp_error( $search_result ) ) {
			return $search_result;
		}
		if ( ! empty( $vector_store_id ) ) {
			$search_result['vector_store_id'] = $vector_store_id;
		}
		return $search_result;
	}

	/**
	 * Retrieve a specific context by ID.
	 *
	 * @param int|string $agent_id Agent ID.
	 * @param string     $context_id Context ID.
	 * @param bool       $include_expired Whether to include expired contexts.
	 * @return array Tool results.
	 */
	private function retrieve_specific_context( $agent_id, $context_id, $include_expired ) {
		// Use enhanced retrieval with compression.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$context_record  = $context_manager->retrieve_context_compressed( $agent_id, $context_id, true );

		if ( ! $context_record ) {
			return new WP_Error( 'wp_mcp_ai_error', __( 'Context not found or has expired.', 'mcp-ai-wpoos' ) );
		}

		// Check expiration.
		if ( ! $include_expired && isset( $context_record['expires_at'] ) ) {
			$expires_timestamp = strtotime( $context_record['expires_at'] );
			if ( $expires_timestamp && time() > $expires_timestamp ) {
				return new WP_Error( 'wp_mcp_ai_error', __( 'Context has expired.', 'mcp-ai-wpoos' ) );
			}
		}

		// Calculate enhanced score.
		$enhanced_score = $context_manager->calculate_enhanced_score( $context_record );

		$result                   = $this->format_context_result( $context_record );
		$result['enhanced_score'] = round( $enhanced_score, 3 );
		$result['access_count']   = isset( $context_record['access_count'] ) ? $context_record['access_count'] : 0;

		return array(
			'success'  => true,
			'message'  => __( 'Context retrieved successfully.', 'mcp-ai-wpoos' ),
			'contexts' => array( $result ),
			'count'    => 1,
		);
	}

	/**
	 * Search contexts for an agent.
	 *
	 * @param int|string $agent_id Agent ID.
	 * @param string     $query Search query.
	 * @param array      $filters Filters to apply.
	 * @param int        $limit Maximum results.
	 * @param bool       $include_expired Whether to include expired contexts.
	 * @return array Tool results.
	 */
	private function search_contexts( $agent_id, $query, $filters, $limit, $include_expired ) {
		// Get context index for this agent.
		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) || empty( $context_index ) ) {
			// Transient index missing (Redis flush, fresh process, object-
			// cache eviction). Fall back to the durable CCT mirror so the
			// chat-memory drawer doesn't black-hole memories that survived
			// in the durable store. Reader silently no-ops when JetEngine /
			// the CCT table is unavailable, so the original empty response
			// remains the worst case.
			if ( class_exists( 'WP_MCP_AI_Agent_Memory_CCT_Reader' ) ) {
				$cct_records = WP_MCP_AI_Agent_Memory_CCT_Reader::get_transient_shaped_records_for_agent( $agent_id, $limit );
				if ( ! empty( $cct_records ) ) {
					return $this->build_search_result_from_records( $cct_records, $query, $filters, $limit, $include_expired );
				}
			}

			return array(
				'success'  => true,
				'message'  => __( 'No contexts found for this agent.', 'mcp-ai-wpoos' ),
				'contexts' => array(),
				'count'    => 0,
			);
		}

		// Retrieve and filter contexts.
		$records = array();
		foreach ( $context_index as $ctx_id => $index_entry ) {
			// Check expiration via the index entry before bothering to load
			// the heavier per-context transient.
			if ( ! $include_expired && isset( $index_entry['expires_at'] ) ) {
				$expires_timestamp = strtotime( $index_entry['expires_at'] );
				if ( $expires_timestamp && time() > $expires_timestamp ) {
					continue;
				}
			}

			$transient_key  = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $ctx_id );
			$context_record = get_transient( $transient_key );
			if ( ! $context_record ) {
				continue;
			}

			$records[] = $context_record;
		}

		return $this->build_search_result_from_records( $records, $query, $filters, $limit, $include_expired );
	}

	/**
	 * Build a search result envelope from a list of pre-fetched records.
	 *
	 * Used by both the transient-index path (after expanding each context
	 * id) and the CCT fallback. Centralising the filter/relevance/sort/
	 * format pipeline guarantees both paths return identically-shaped
	 * responses.
	 *
	 * @param array  $records         Records in the transient shape (with
	 *                                a `data` sub-array).
	 * @param string $query           Search query.
	 * @param array  $filters         Filters to apply.
	 * @param int    $limit           Maximum results.
	 * @param bool   $include_expired Whether to include expired contexts.
	 * @return array
	 */
	private function build_search_result_from_records( array $records, $query, $filters, $limit, $include_expired ) {
		$results = array();
		foreach ( $records as $context_record ) {
			if ( ! is_array( $context_record ) ) {
				continue;
			}
			if ( ! $include_expired && ! empty( $context_record['expires_at'] ) ) {
				$expires_timestamp = strtotime( (string) $context_record['expires_at'] );
				if ( $expires_timestamp && time() > $expires_timestamp ) {
					continue;
				}
			}
			if ( ! $this->matches_filters( $context_record, $filters ) ) {
				continue;
			}
			$results[] = array(
				'record'    => $context_record,
				'relevance' => $this->calculate_relevance( $context_record, $query ),
			);
		}

		usort( $results, array( $this, 'sort_results' ) );
		$results = array_slice( $results, 0, $limit );

		$formatted_results = array();
		foreach ( $results as $result ) {
			$formatted_results[] = $this->format_context_result( $result['record'], $result['relevance'] );
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: %d: number of contexts found */
				_n( 'Found %d context.', 'Found %d contexts.', count( $formatted_results ), 'mcp-ai-wpoos' ),
				count( $formatted_results )
			),
			'contexts' => $formatted_results,
			'count'    => count( $formatted_results ),
			'query'    => $query,
		);
	}

	/**
	 * Check if context matches filters.
	 *
	 * @param array $context_record Context record.
	 * @param array $filters Filters to apply.
	 * @return bool True if matches.
	 */
	private function matches_filters( $context_record, $filters ) {
		// Context type filter.
		if ( ! empty( $filters['context_types'] ) && is_array( $filters['context_types'] ) ) {
			if ( ! in_array( $context_record['context_type'], $filters['context_types'], true ) ) {
				return false;
			}
		}

		// Tags filter (any match).
		if ( ! empty( $filters['tags'] ) && is_array( $filters['tags'] ) ) {
			$context_tags = isset( $context_record['data']['tags'] ) ? $context_record['data']['tags'] : array();
			$has_match    = false;
			foreach ( $filters['tags'] as $filter_tag ) {
				if ( in_array( $filter_tag, $context_tags, true ) ) {
					$has_match = true;
					break;
				}
			}
			if ( ! $has_match ) {
				return false;
			}
		}

		// Importance filter.
		if ( ! empty( $filters['importance'] ) && is_array( $filters['importance'] ) ) {
			$context_importance = isset( $context_record['data']['importance'] ) ? $context_record['data']['importance'] : 'medium';
			if ( ! in_array( $context_importance, $filters['importance'], true ) ) {
				return false;
			}
		}

		// Date filters.
		if ( ! empty( $filters['after_date'] ) ) {
			$after_timestamp  = strtotime( $filters['after_date'] );
			$stored_timestamp = strtotime( $context_record['stored_at'] );
			if ( $stored_timestamp < $after_timestamp ) {
				return false;
			}
		}

		if ( ! empty( $filters['before_date'] ) ) {
			$before_timestamp = strtotime( $filters['before_date'] );
			$stored_timestamp = strtotime( $context_record['stored_at'] );
			if ( $stored_timestamp > $before_timestamp ) {
				return false;
			}
		}

		// Wing filter (exact match, case-insensitive). Empty record wing never matches a non-empty filter.
		if ( ! empty( $filters['wing'] ) ) {
			$record_wing = isset( $context_record['wing'] ) ? (string) $context_record['wing'] : '';
			if ( '' === $record_wing || strcasecmp( $record_wing, (string) $filters['wing'] ) !== 0 ) {
				return false;
			}
		}

		// Room filter (exact match, case-insensitive).
		if ( ! empty( $filters['room'] ) ) {
			$record_room = isset( $context_record['room'] ) ? (string) $context_record['room'] : '';
			if ( '' === $record_room || strcasecmp( $record_room, (string) $filters['room'] ) !== 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Calculate relevance score for a context.
	 *
	 * @param array  $context_record Context record.
	 * @param string $query Search query.
	 * @return float Relevance score (0-1).
	 */
	private function calculate_relevance( $context_record, $query ) {
		if ( empty( $query ) ) {
			return 0.5; // Neutral relevance.
		}

		$score       = 0.0;
		$query_lower = strtolower( $query );
		$query_words = explode( ' ', $query_lower );
		$title       = isset( $context_record['data']['title'] ) ? strtolower( $context_record['data']['title'] ) : '';
		$content     = isset( $context_record['data']['content'] ) ? strtolower( $context_record['data']['content'] ) : '';
		$tags        = isset( $context_record['data']['tags'] ) ? array_map( 'strtolower', $context_record['data']['tags'] ) : array();

		// Exact title match (high score).
		if ( strpos( $title, $query_lower ) !== false ) {
			$score += 0.5;
		}

		// Word matches in title (medium score).
		foreach ( $query_words as $word ) {
			if ( strpos( $title, $word ) !== false ) {
				$score += 0.1;
			}
		}

		// Word matches in content (low score).
		foreach ( $query_words as $word ) {
			if ( strpos( $content, $word ) !== false ) {
				$score += 0.05;
			}
		}

		// Tag matches (medium score).
		foreach ( $tags as $tag ) {
			if ( strpos( $tag, $query_lower ) !== false || strpos( $query_lower, $tag ) !== false ) {
				$score += 0.15;
			}
		}

		// Cap at 1.0.
		return min( 1.0, $score );
	}

	/**
	 * Sort results by relevance and importance.
	 *
	 * @param array $a First result.
	 * @param array $b Second result.
	 * @return int Comparison result.
	 */
	private function sort_results( $a, $b ) {
		// First, sort by importance.
		$importance_order = array(
			'critical' => 4,
			'high'     => 3,
			'medium'   => 2,
			'low'      => 1,
		);

		$importance_a = isset( $a['record']['data']['importance'] ) ? $a['record']['data']['importance'] : 'medium';
		$importance_b = isset( $b['record']['data']['importance'] ) ? $b['record']['data']['importance'] : 'medium';

		$score_a = isset( $importance_order[ $importance_a ] ) ? $importance_order[ $importance_a ] : 2;
		$score_b = isset( $importance_order[ $importance_b ] ) ? $importance_order[ $importance_b ] : 2;

		if ( $score_a !== $score_b ) {
			return $score_b - $score_a; // Higher importance first.
		}

		// Then, sort by relevance.
		$relevance_diff = $b['relevance'] - $a['relevance'];
		if ( abs( $relevance_diff ) > 0.01 ) {
			return $relevance_diff > 0 ? 1 : -1;
		}

		// Finally, sort by date (newer first).
		$time_a = strtotime( $a['record']['stored_at'] );
		$time_b = strtotime( $b['record']['stored_at'] );

		return $time_b - $time_a;
	}

	/**
	 * Format context result for output.
	 *
	 * @param array $context_record Context record.
	 * @param float $relevance_score Relevance score (optional).
	 * @return array Formatted result.
	 */
	private function format_context_result( $context_record, $relevance_score = null ) {
		$result = array(
			'context_id'   => $context_record['context_id'],
			'context_type' => $context_record['context_type'],
			'title'        => isset( $context_record['data']['title'] ) ? $context_record['data']['title'] : '',
			'content'      => isset( $context_record['data']['content'] ) ? $context_record['data']['content'] : '',
			'metadata'     => isset( $context_record['data']['metadata'] ) ? $context_record['data']['metadata'] : array(),
			'tags'         => isset( $context_record['data']['tags'] ) ? $context_record['data']['tags'] : array(),
			'importance'   => isset( $context_record['data']['importance'] ) ? $context_record['data']['importance'] : 'medium',
			'wing'         => isset( $context_record['wing'] ) ? $context_record['wing'] : '',
			'room'         => isset( $context_record['room'] ) ? $context_record['room'] : '',
			'verbatim'     => ! empty( $context_record['verbatim'] ),
			'stored_at'    => $context_record['stored_at'],
			'expires_at'   => $context_record['expires_at'],
		);

		if ( null !== $relevance_score ) {
			$result['relevance_score'] = round( $relevance_score, 2 );
		}

		// Add source task if present.
		if ( isset( $context_record['data']['source_task'] ) ) {
			$result['source_task'] = $context_record['data']['source_task'];
		}

		return $result;
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

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'orchestrator', 'hierarchical' ),

			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Does not modify data.
			'local-only',           // No external API calls.
			'idempotent',           // Same input = same output.
			'cacheable',            // Results can be cached.
			'requires-capability',  // Needs user authentication.
		);
	}
}
