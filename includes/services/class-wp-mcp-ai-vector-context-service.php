<?php
/**
 * Vector Context Retrieval Service
 *
 * Provides semantic search capabilities for agent contexts using
 * OpenAI embeddings and cosine similarity.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 5.5).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vector-based context retrieval with semantic search.
 *
 * This service uses OpenAI embeddings to enable semantic understanding
 * of context relevance beyond simple keyword matching.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Vector_Context_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Vector_Context_Service|null
	 */
	private static $instance = null;

	/**
	 * OpenAI client instance.
	 *
	 * @var WP_MCP_AI_OpenAI_Client|null
	 */
	private $openai_client = null;

	/**
	 * Embedding model to use.
	 *
	 * @var string
	 */
	const EMBEDDING_MODEL = 'text-embedding-3-small';

	/**
	 * Embedding cache prefix.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'mcp_ai_embed_';

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Vector_Context_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		// Initialize OpenAI client when needed.
	}

	/**
	 * Generate embedding for context text.
	 *
	 * @param string $context_text Text to embed.
	 * @param bool   $use_cache    Whether to use cached embeddings.
	 * @return array|WP_Error Embedding vector or error.
	 */
	public function embed_context( $context_text, $use_cache = true ) {
		if ( empty( $context_text ) ) {
			return new WP_Error( 'empty_text', __( 'Context text cannot be empty.', 'mcp-ai-wpoos' ) );
		}

		// Check cache first.
		if ( $use_cache ) {
			$cache_key = self::CACHE_PREFIX . md5( $context_text );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Get OpenAI client.
		$client = $this->get_openai_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Generate embedding.
		try {
			$response = $client->create_embedding(
				array(
					'model' => self::EMBEDDING_MODEL,
					'input' => $context_text,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract embedding vector.
			if ( isset( $response['data'][0]['embedding'] ) ) {
				$embedding = $response['data'][0]['embedding'];

				// Cache the embedding (30 days).
				if ( $use_cache ) {
					$cache_key = self::CACHE_PREFIX . md5( $context_text );
					set_transient( $cache_key, $embedding, 30 * DAY_IN_SECONDS );
				}

				return $embedding;
			}

			return new WP_Error( 'invalid_response', __( 'Invalid embedding response from OpenAI.', 'mcp-ai-wpoos' ) );

		} catch ( Exception $e ) {
			return new WP_Error( 'embedding_error', $e->getMessage() );
		}
	}

	/**
	 * Search contexts using semantic similarity.
	 *
	 * @param string     $query    Search query.
	 * @param int|string $agent_id Agent identifier.
	 * @param int        $limit    Maximum results.
	 * @param array      $filters  Optional filters.
	 * @return array Array of contexts with similarity scores.
	 */
	public function search_context( $query, $agent_id, $limit = 10, $filters = array() ) {
		// Generate query embedding.
		$query_embedding = $this->embed_context( $query );
		if ( is_wp_error( $query_embedding ) ) {
			return array(
				'success' => false,
				'error'   => $query_embedding->get_error_message(),
			);
		}

		// Get all contexts for agent.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$contexts        = $context_manager->search_contexts( $agent_id, $filters, 100, false );

		if ( empty( $contexts ) ) {
			return array(
				'success'  => true,
				'contexts' => array(),
				'count'    => 0,
			);
		}

		// Calculate similarity scores for each context.
		$scored_contexts = array();
		foreach ( $contexts as $context ) {
			// Generate embedding for context content.
			$context_text = '';
			if ( isset( $context['data']['title'] ) ) {
				$context_text .= $context['data']['title'] . ' ';
			}
			if ( isset( $context['data']['content'] ) ) {
				$context_text .= $context['data']['content'];
			}

			$context_embedding = $this->embed_context( $context_text );
			if ( is_wp_error( $context_embedding ) ) {
				continue; // Skip contexts that fail to embed.
			}

			// Calculate cosine similarity.
			$similarity = $this->cosine_similarity( $query_embedding, $context_embedding );

			$scored_contexts[] = array(
				'context'    => $context,
				'similarity' => $similarity,
			);
		}

		// Sort by similarity (highest first).
		usort(
			$scored_contexts,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		// Limit results.
		$scored_contexts = array_slice( $scored_contexts, 0, $limit );

		// Format results.
		$results = array();
		foreach ( $scored_contexts as $scored ) {
			$context   = $scored['context'];
			$results[] = array(
				'context_id'       => $context['context_id'],
				'context_type'     => $context['context_type'],
				'title'            => isset( $context['data']['title'] ) ? $context['data']['title'] : '',
				'content'          => isset( $context['data']['content'] ) ? $context['data']['content'] : '',
				'importance'       => isset( $context['data']['importance'] ) ? $context['data']['importance'] : 'medium',
				'tags'             => isset( $context['data']['tags'] ) ? $context['data']['tags'] : array(),
				'stored_at'        => $context['stored_at'],
				'similarity_score' => round( $scored['similarity'], 4 ),
			);
		}

		return array(
			'success'  => true,
			'contexts' => $results,
			'count'    => count( $results ),
			'query'    => $query,
		);
	}

	/**
	 * Optimize context window using embeddings.
	 *
	 * Selects the most semantically relevant contexts within token budget.
	 *
	 * @param array $candidate_contexts Array of context items.
	 * @param int   $token_budget       Token budget.
	 * @param array $current_task       Current task description.
	 * @return array Optimized context selection.
	 */
	public function optimize_context_window( $candidate_contexts, $token_budget, $current_task ) {
		if ( empty( $candidate_contexts ) || empty( $current_task['query'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Candidate contexts and task query are required.', 'mcp-ai-wpoos' ),
			);
		}

		// Generate query embedding.
		$query_embedding = $this->embed_context( $current_task['query'] );
		if ( is_wp_error( $query_embedding ) ) {
			return array(
				'success' => false,
				'error'   => $query_embedding->get_error_message(),
			);
		}

		// Score contexts by semantic similarity.
		$scored_contexts = array();
		foreach ( $candidate_contexts as $context ) {
			$context_text = '';
			if ( isset( $context['title'] ) ) {
				$context_text .= $context['title'] . ' ';
			}
			if ( isset( $context['content'] ) ) {
				$context_text .= $context['content'];
			}

			$context_embedding = $this->embed_context( $context_text );
			if ( is_wp_error( $context_embedding ) ) {
				continue;
			}

			$similarity = $this->cosine_similarity( $query_embedding, $context_embedding );
			$tokens     = $this->estimate_tokens( $context['content'] );

			$scored_contexts[] = array(
				'context'    => $context,
				'similarity' => $similarity,
				'tokens'     => $tokens,
			);
		}

		// Sort by similarity (highest first).
		usort(
			$scored_contexts,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		// Select contexts within budget.
		$selected    = array();
		$used_tokens = 0;

		foreach ( $scored_contexts as $scored ) {
			if ( $used_tokens + $scored['tokens'] > $token_budget ) {
				continue;
			}

			$selected[] = array_merge(
				$scored['context'],
				array(
					'similarity_score' => round( $scored['similarity'], 4 ),
					'tokens'           => $scored['tokens'],
				)
			);

			$used_tokens += $scored['tokens'];
		}

		return array(
			'success'         => true,
			'optimized'       => $selected,
			'count'           => count( $selected ),
			'total_tokens'    => $used_tokens,
			'budget'          => $token_budget,
			'budget_used_pct' => round( ( $used_tokens / $token_budget ) * 100, 1 ),
			'method'          => 'semantic_similarity',
		);
	}

	/**
	 * Calculate cosine similarity between two vectors.
	 *
	 * @param array $vec_a First vector.
	 * @param array $vec_b Second vector.
	 * @return float Similarity score (0-1).
	 */
	private function cosine_similarity( $vec_a, $vec_b ) {
		if ( count( $vec_a ) !== count( $vec_b ) ) {
			return 0.0;
		}

		$dot_product = 0.0;
		$magnitude_a = 0.0;
		$magnitude_b = 0.0;

		$vec_a_count = count( $vec_a );
		for ( $i = 0; $i < $vec_a_count; $i++ ) {
			$dot_product += $vec_a[ $i ] * $vec_b[ $i ];
			$magnitude_a += $vec_a[ $i ] * $vec_a[ $i ];
			$magnitude_b += $vec_b[ $i ] * $vec_b[ $i ];
		}

		$magnitude_a = sqrt( $magnitude_a );
		$magnitude_b = sqrt( $magnitude_b );

		if ( 0 === $magnitude_a || 0 === $magnitude_b ) {
			return 0.0;
		}

		return $dot_product / ( $magnitude_a * $magnitude_b );
	}

	/**
	 * Estimate token count for text.
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	private function estimate_tokens( $text ) {
		if ( empty( $text ) ) {
			return 0;
		}
		return (int) ceil( strlen( $text ) / 4 );
	}

	/**
	 * Get OpenAI client instance.
	 *
	 * @return WP_MCP_AI_OpenAI_Client|WP_Error
	 */
	private function get_openai_client() {
		if ( null !== $this->openai_client ) {
			return $this->openai_client;
		}

		// Check if OpenAI is configured.
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$api_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' ) );
		}

		// Create client instance.
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error( 'client_unavailable', __( 'OpenAI client is not available.', 'mcp-ai-wpoos' ) );
		}

		$this->openai_client = new WP_MCP_AI_OpenAI_Client();
		return $this->openai_client;
	}

	/**
	 * Clear embedding cache for an agent.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @return int Number of cache entries cleared.
	 */
	public function clear_embedding_cache( $agent_id = null ) {
		global $wpdb;

		$cleared = 0;

		// Clear all embedding cache if no agent specified.
		if ( null === $agent_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$transients = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
				)
			);

			foreach ( $transients as $transient ) {
				$key = str_replace( '_transient_', '', $transient->option_name );
				delete_transient( $key );
				++$cleared;
			}
		}

		return $cleared;
	}
}
