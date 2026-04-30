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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

			// Apply MemPalace-inspired hybrid scoring boosters.
			$boost_breakdown = $this->calculate_score_boosters( $context, $query, $filters );
			$boosted_score   = max( 0.0, min( 1.0, $similarity + $boost_breakdown['total'] ) );

			$scored_contexts[] = array(
				'context'    => $context,
				'similarity' => $similarity,
				'boosters'   => $boost_breakdown,
				'final'      => $boosted_score,
			);
		}

		// Sort by hybrid score (highest first).
		usort(
			$scored_contexts,
			function ( $a, $b ) {
				return $b['final'] <=> $a['final'];
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
				'wing'             => isset( $context['wing'] ) ? $context['wing'] : '',
				'room'             => isset( $context['room'] ) ? $context['room'] : '',
				'stored_at'        => $context['stored_at'],
				'similarity_score' => round( $scored['similarity'], 4 ),
				'boost_score'      => round( $scored['boosters']['total'], 4 ),
				'final_score'      => round( $scored['final'], 4 ),
				'boost_breakdown'  => array(
					'keyword'     => round( $scored['boosters']['keyword'], 4 ),
					'temporal'    => round( $scored['boosters']['temporal'], 4 ),
					'exact_match' => round( $scored['boosters']['exact_match'], 4 ),
				),
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
	 * Calculate MemPalace-inspired hybrid scoring boosters for a context.
	 *
	 * Layers three optional, additive signals on top of the cosine-similarity
	 * baseline so retrieval can match the keyword + temporal + exact-match
	 * heuristics described in MemPalace's hybrid pipeline. Each booster has
	 * a default weight (held to a small magnitude relative to similarity) and
	 * a dedicated filter so users can disable, tune, or replace it.
	 *
	 * Defaults are conservative: keyword 0.10 max, temporal 0.05 max,
	 * exact_match 0.10 max, total capped at 0.25. Setting any of the
	 * `*_weight` filters to 0 disables that booster entirely; the pure
	 * cosine-similarity ranking is recovered by setting all three to 0.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $context Context record (with 'data', 'stored_at', 'wing', 'room').
	 * @param string $query   The user query.
	 * @param array  $filters Optional retrieval filters (wing, room, tags) used by the exact-match booster.
	 * @return array {
	 *     Score breakdown.
	 *
	 *     @type float $keyword     Keyword overlap booster (post-weight).
	 *     @type float $temporal    Temporal-proximity booster (post-weight).
	 *     @type float $exact_match Tag/wing/room exact-match booster (post-weight).
	 *     @type float $total       Sum, clipped to a configurable cap.
	 * }
	 */
	private function calculate_score_boosters( $context, $query, $filters = array() ) {
		$title   = isset( $context['data']['title'] ) ? (string) $context['data']['title'] : '';
		$content = isset( $context['data']['content'] ) ? (string) $context['data']['content'] : '';
		$tags    = isset( $context['data']['tags'] ) && is_array( $context['data']['tags'] ) ? $context['data']['tags'] : array();
		$wing    = isset( $context['wing'] ) ? (string) $context['wing'] : '';
		$room    = isset( $context['room'] ) ? (string) $context['room'] : '';

		// --- Keyword booster: term-overlap ratio between query and title+content. ---
		/**
		 * Filter the maximum weight of the keyword-overlap booster.
		 *
		 * @since 1.1.0
		 *
		 * @param float  $weight  Max contribution to the final score (0..1).
		 * @param array  $context Context record being scored.
		 * @param string $query   Query string.
		 */
		$keyword_weight = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_keyword_weight', 0.10, $context, $query );
		$keyword_score  = 0.0;
		if ( $keyword_weight > 0 && '' !== $query ) {
			$query_lower = strtolower( $query );
			$query_terms = array_filter(
				array_unique( preg_split( '/\s+/', $query_lower ) ),
				static function ( $term ) {
					return strlen( $term ) >= 3;
				}
			);
			$total_terms = count( $query_terms );
			if ( $total_terms > 0 ) {
				$haystack = strtolower( $title . ' ' . $content );
				$matches  = 0;
				foreach ( $query_terms as $term ) {
					if ( false !== strpos( $haystack, $term ) ) {
						++$matches;
					}
				}
				$keyword_score = ( $matches / $total_terms ) * $keyword_weight;
			}
		}
		/**
		 * Filter the final keyword-overlap booster value for a context.
		 *
		 * @since 1.1.0
		 *
		 * @param float  $score   Computed booster value (already weighted).
		 * @param array  $context Context record being scored.
		 * @param string $query   Query string.
		 * @param float  $weight  The active weight.
		 */
		$keyword_score = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_keyword', $keyword_score, $context, $query, $keyword_weight );
		// Clamp to [0, weight] so a misbehaving filter cannot dominate cosine similarity.
		$keyword_score = max( 0.0, min( $keyword_weight, $keyword_score ) );

		// --- Temporal booster: exponential decay favoring recent memories. ---
		/**
		 * Filter the maximum weight of the temporal-proximity booster.
		 *
		 * @since 1.1.0
		 *
		 * @param float $weight Max contribution to the final score (0..1).
		 */
		$temporal_weight = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_temporal_weight', 0.05, $context, $query );

		/**
		 * Filter the half-life (in seconds) used by the temporal booster.
		 *
		 * Defaults to 30 days: a memory stored 30 days ago contributes half its
		 * temporal weight; one stored 60 days ago contributes a quarter; and so on.
		 *
		 * @since 1.1.0
		 *
		 * @param int $half_life Half-life in seconds.
		 */
		$half_life       = (int) apply_filters( 'wp_mcp_ai_memory_score_boost_temporal_half_life', 30 * DAY_IN_SECONDS );
		$temporal_score  = 0.0;
		$stored_at_iso   = isset( $context['stored_at'] ) ? (string) $context['stored_at'] : '';
		$stored_ts       = $stored_at_iso ? strtotime( $stored_at_iso ) : 0;
		if ( $temporal_weight > 0 && $stored_ts > 0 && $half_life > 0 ) {
			$age_seconds    = max( 0, time() - $stored_ts );
			$decay          = pow( 0.5, $age_seconds / $half_life );
			$temporal_score = $temporal_weight * $decay;
		}
		/**
		 * Filter the final temporal-proximity booster value for a context.
		 *
		 * @since 1.1.0
		 *
		 * @param float $score   Computed booster value (already weighted).
		 * @param array $context Context record being scored.
		 * @param int   $half_life Half-life used.
		 * @param float $weight  The active weight.
		 */
		$temporal_score = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_temporal', $temporal_score, $context, $half_life, $temporal_weight );
		// Clamp to [0, weight].
		$temporal_score = max( 0.0, min( $temporal_weight, $temporal_score ) );

		// --- Exact-match booster: tag, wing, room matches between filters and record. ---
		/**
		 * Filter the maximum weight of the exact-match (tag/wing/room) booster.
		 *
		 * @since 1.1.0
		 *
		 * @param float $weight Max contribution to the final score (0..1).
		 */
		$exact_weight = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_exact_match_weight', 0.10, $context, $query );
		$exact_score  = 0.0;
		if ( $exact_weight > 0 ) {
			$signals = 0;
			$matched = 0;

			if ( ! empty( $filters['wing'] ) ) {
				++$signals;
				if ( '' !== $wing && 0 === strcasecmp( $wing, (string) $filters['wing'] ) ) {
					++$matched;
				}
			}
			if ( ! empty( $filters['room'] ) ) {
				++$signals;
				if ( '' !== $room && 0 === strcasecmp( $room, (string) $filters['room'] ) ) {
					++$matched;
				}
			}
			if ( ! empty( $filters['tags'] ) && is_array( $filters['tags'] ) ) {
				++$signals;
				$tags_lower         = array_map( 'strtolower', array_map( 'strval', $tags ) );
				$filter_tags_lower  = array_map( 'strtolower', array_map( 'strval', $filters['tags'] ) );
				$tag_intersect      = array_intersect( $tags_lower, $filter_tags_lower );
				if ( ! empty( $tag_intersect ) ) {
					++$matched;
				}
			}

			if ( $signals > 0 ) {
				$exact_score = ( $matched / $signals ) * $exact_weight;
			}
		}
		/**
		 * Filter the final exact-match booster value for a context.
		 *
		 * @since 1.1.0
		 *
		 * @param float $score   Computed booster value (already weighted).
		 * @param array $context Context record being scored.
		 * @param array $filters Retrieval filters used.
		 * @param float $weight  The active weight.
		 */
		$exact_score = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_exact_match', $exact_score, $context, $filters, $exact_weight );
		// Clamp to [0, weight].
		$exact_score = max( 0.0, min( $exact_weight, $exact_score ) );

		// Cap total booster contribution to avoid overwhelming the cosine baseline.
		/**
		 * Filter the maximum total contribution of all boosters combined.
		 *
		 * @since 1.1.0
		 *
		 * @param float $cap     Upper bound for the summed booster contribution.
		 * @param array $context Context record being scored.
		 */
		$total_cap = (float) apply_filters( 'wp_mcp_ai_memory_score_boost_total_cap', 0.25, $context );
		$total     = max( 0.0, min( $total_cap, $keyword_score + $temporal_score + $exact_score ) );

		return array(
			'keyword'     => $keyword_score,
			'temporal'    => $temporal_score,
			'exact_match' => $exact_score,
			'total'       => $total,
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
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
