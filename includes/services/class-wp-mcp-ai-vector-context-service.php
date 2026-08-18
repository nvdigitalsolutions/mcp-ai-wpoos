<?php
/**
 * Vector Context Retrieval Service
 *
 * Provides semantic search capabilities for agent contexts using
 * OpenAI embeddings and cosine similarity.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 5.5).
 * Hybrid scoring boosters are inspired by the MemPalace project
 * (https://github.com/MemPalace/mempalace).
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
	 * @deprecated 1.1.0 Use the resolved embedding provider instead. Kept for
	 *             backward compatibility with code that referenced this property.
	 */
	private $openai_client = null;

	/**
	 * Resolved embedding provider for the current request.
	 *
	 * @var WP_MCP_AI_Embedding_Provider_Interface|null
	 */
	private $embedding_provider = null;

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

		// Resolve the active embedding provider before computing the cache key
		// so cached vectors are scoped to {provider_id}:{model}.
		$provider = $this->get_embedding_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$cache_key = self::CACHE_PREFIX . md5( $provider->get_id() . ':' . $provider->get_model() . ':' . $context_text );

		// Check cache first.
		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Generate embedding via the provider.
		$embedding = $provider->embed( $context_text );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		if ( ! is_array( $embedding ) || empty( $embedding ) ) {
			return new WP_Error( 'invalid_response', __( 'Embedding provider returned an empty vector.', 'mcp-ai-wpoos' ) );
		}

		// Cache the embedding (30 days).
		if ( $use_cache ) {
			set_transient( $cache_key, $embedding, 30 * DAY_IN_SECONDS );
		}

		return $embedding;
	}

	/**
	 * Search contexts using cosine similarity + MemPalace booster pipeline.
	 *
	 * **Backward-compat fallback.** This is the original retrieval path and
	 * remains the canonical entry point when the Phase 4 RRF fusion service is
	 * disabled via the `wp_mcp_ai_memory_rrf_enabled` master filter. The newer
	 * {@see self::search_context_rrf()} method is additive and returns the
	 * same response shape with an extra `rrf_breakdown` key per record.
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
			return new WP_Error( 'wp_mcp_ai_error', $query_embedding->get_error_message() );
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

		// Resolve the embedding provider for cache-key scoping.
		$provider    = $this->get_embedding_provider();
		$provider_id = is_wp_error( $provider ) ? 'openai' : $provider->get_id();
		$model       = is_wp_error( $provider ) ? self::EMBEDDING_MODEL : $provider->get_model();
		$use_store   = class_exists( 'WP_MCP_AI_Context_Embedding_Store' );

		// Calculate similarity scores for each context.
		$scored_contexts = array();
		foreach ( $contexts as $context ) {
			// Build the context text.
			$context_text = '';
			if ( isset( $context['data']['title'] ) ) {
				$context_text .= $context['data']['title'] . ' ';
			}
			if ( isset( $context['data']['content'] ) ) {
				$context_text .= $context['data']['content'];
			}

			// Prefer the persistent embedding store over per-request API calls.
			$context_embedding = null;
			$context_id        = isset( $context['context_id'] ) ? $context['context_id'] : '';
			$embedding_fresh   = false;

			if ( $use_store && '' !== $context_id ) {
				// Check if a fresh embedding exists in the store.
				if ( WP_MCP_AI_Context_Embedding_Store::is_fresh(
					$context_id,
					absint( $agent_id ),
					$provider_id,
					$model,
					$context_text
				) ) {
					$context_embedding = WP_MCP_AI_Context_Embedding_Store::get(
						$context_id,
						absint( $agent_id ),
						$provider_id,
						$model
					);
					$embedding_fresh   = null !== $context_embedding;
				}

				// Store a newly-generated embedding for future reuse.
				if ( ! $embedding_fresh ) {
					$context_embedding = $this->embed_context( $context_text );
					if ( ! is_wp_error( $context_embedding ) ) {
						WP_MCP_AI_Context_Embedding_Store::store(
							$context_id,
							absint( $agent_id ),
							$context_embedding,
							$provider_id,
							$model,
							$context_text
						);
					}
				}
			} else {
				// Fallback: generate embedding via API (legacy path).
				$context_embedding = $this->embed_context( $context_text );
			}

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
	 * RRF-fused hybrid retrieval — Phase 4 of the 2026 Memory Layer Enhancements.
	 *
	 * Additive thin wrapper around
	 * {@see WP_MCP_AI_Memory_RRF_Fusion_Service::search()}. The legacy
	 * {@see self::search_context()} method is intentionally untouched; this
	 * method exists alongside it so existing consumers keep working while new
	 * consumers (and the `semantic_context_search` / `recall_memory` tools
	 * when their `use_rrf` arg is on) opt-in to the fused pipeline.
	 *
	 * Response shape matches `search_context()` exactly and adds one
	 * additional `rrf_breakdown` key per record (see
	 * {@see WP_MCP_AI_Memory_RRF_Fusion_Service::search()} for the shape).
	 *
	 * Behaviour when the RRF master switch
	 * (`wp_mcp_ai_memory_rrf_enabled`) is OFF: this method falls through to
	 * {@see self::search_context()} unchanged so callers always get a valid
	 * envelope.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $query    Search query.
	 * @param int|string $agent_id Agent identifier.
	 * @param int        $limit    Maximum results.
	 * @param array      $filters  Optional filters.
	 * @return array Array of contexts with similarity scores.
	 */
	public function search_context_rrf( $query, $agent_id, $limit = 10, $filters = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Memory_RRF_Fusion_Service' )
			|| ! WP_MCP_AI_Memory_RRF_Fusion_Service::is_enabled() ) {
			return $this->search_context( $query, $agent_id, $limit, $filters );
		}
		return WP_MCP_AI_Memory_RRF_Fusion_Service::search( $query, $agent_id, $limit, $filters );
	}

	/**
	 * Return ranked candidate records by cosine similarity (no boosters).
	 *
	 * Exposed so the Phase 4 RRF fusion service can extract a pure vector
	 * ranking without paying the booster cost (the booster pipeline lives
	 * in {@see self::calculate_score_boosters()} and is intentionally
	 * private). This helper is the **only** read-side method exposed by the
	 * vector service that bypasses the legacy boost step.
	 *
	 * Each returned record is a transient-shape array (the same shape
	 * returned by {@see WP_MCP_AI_Agent_Context_Manager::search_contexts()})
	 * plus a `_vector_similarity` key carrying the raw cosine score for
	 * debugging.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $query    Search query.
	 * @param int|string $agent_id Agent identifier.
	 * @param int        $limit    Maximum candidates to return.
	 * @param array      $filters  Optional context-manager filters.
	 * @return array<int, array> Ordered candidate records, best match first.
	 */
	public function get_vector_candidates( $query, $agent_id, $limit = 20, $filters = array() ) {
		$query = (string) $query;
		if ( '' === trim( $query ) ) {
			return array();
		}

		$query_embedding = $this->embed_context( $query );
		if ( is_wp_error( $query_embedding ) ) {
			return array();
		}

		if ( ! class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			return array();
		}

		$mgr      = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$contexts = $mgr->search_contexts( $agent_id, is_array( $filters ) ? $filters : array(), 100, false );
		if ( empty( $contexts ) ) {
			return array();
		}

		// Resolve the embedding provider for cache-key scoping.
		$provider    = $this->get_embedding_provider();
		$provider_id = is_wp_error( $provider ) ? 'openai' : $provider->get_id();
		$model       = is_wp_error( $provider ) ? self::EMBEDDING_MODEL : $provider->get_model();
		$use_store   = class_exists( 'WP_MCP_AI_Context_Embedding_Store' );

		$scored = array();
		foreach ( $contexts as $context ) {
			$text = '';
			if ( isset( $context['data']['title'] ) ) {
				$text .= $context['data']['title'] . ' ';
			}
			if ( isset( $context['data']['content'] ) ) {
				$text .= $context['data']['content'];
			}
			$text = trim( $text );
			if ( '' === $text ) {
				continue;
			}

			// Prefer the persistent embedding store over per-request API calls.
			$ctx_embedding   = null;
			$context_id      = isset( $context['context_id'] ) ? $context['context_id'] : '';
			$embedding_fresh = false;

			if ( $use_store && '' !== $context_id ) {
				if ( WP_MCP_AI_Context_Embedding_Store::is_fresh(
					$context_id,
					absint( $agent_id ),
					$provider_id,
					$model,
					$text
				) ) {
					$ctx_embedding   = WP_MCP_AI_Context_Embedding_Store::get(
						$context_id,
						absint( $agent_id ),
						$provider_id,
						$model
					);
					$embedding_fresh = null !== $ctx_embedding;
				}

				if ( ! $embedding_fresh ) {
					$ctx_embedding = $this->embed_context( $text );
					if ( ! is_wp_error( $ctx_embedding ) ) {
						WP_MCP_AI_Context_Embedding_Store::store(
							$context_id,
							absint( $agent_id ),
							$ctx_embedding,
							$provider_id,
							$model,
							$text
						);
					}
				}
			} else {
				$ctx_embedding = $this->embed_context( $text );
			}

			if ( is_wp_error( $ctx_embedding ) ) {
				continue;
			}

			$similarity = $this->cosine_similarity( $query_embedding, $ctx_embedding );

			$context['_vector_similarity'] = (float) $similarity;
			$scored[]                      = array(
				'record'     => $context,
				'similarity' => (float) $similarity,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		$limit = max( 1, (int) $limit );
		$out   = array();
		foreach ( array_slice( $scored, 0, $limit ) as $entry ) {
			$out[] = $entry['record'];
		}
		return $out;
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
			return new WP_Error( 'wp_mcp_ai_error', __( 'Candidate contexts and task query are required.', 'mcp-ai-wpoos' ) );
		}

		// Generate query embedding.
		$query_embedding = $this->embed_context( $current_task['query'] );
		if ( is_wp_error( $query_embedding ) ) {
			return new WP_Error( 'wp_mcp_ai_error', $query_embedding->get_error_message() );
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
		$half_life      = (int) apply_filters( 'wp_mcp_ai_memory_score_boost_temporal_half_life', 30 * DAY_IN_SECONDS );
		$temporal_score = 0.0;
		$stored_at_iso  = isset( $context['stored_at'] ) ? (string) $context['stored_at'] : '';
		$stored_ts      = $stored_at_iso ? strtotime( $stored_at_iso ) : 0;
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
				$tags_lower        = array_map( 'strtolower', array_map( 'strval', $tags ) );
				$filter_tags_lower = array_map( 'strtolower', array_map( 'strval', $filters['tags'] ) );
				$tag_intersect     = array_intersect( $tags_lower, $filter_tags_lower );
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
	public function cosine_similarity( $vec_a, $vec_b ) {
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
	 * Resolve the active embedding provider for this request.
	 *
	 * Plugins can swap the provider via the
	 * {@see 'wp_mcp_ai_embedding_provider'} filter. The default chooses
	 * Ollama when an `ollama_endpoint_url` is configured but no OpenAI key is
	 * set (so a freshly-installed local-first deployment "just works"), and
	 * OpenAI in every other case (preserving previous behaviour for existing
	 * installs).
	 *
	 * @since 1.1.0
	 *
	 * @return WP_MCP_AI_Embedding_Provider_Interface|WP_Error Provider instance
	 *         on success, WP_Error when no provider can be resolved or
	 *         configured.
	 */
	public function get_embedding_provider() {
		if ( $this->embedding_provider instanceof WP_MCP_AI_Embedding_Provider_Interface ) {
			return $this->embedding_provider;
		}

		$this->ensure_provider_classes_loaded();

		$default_provider = $this->resolve_default_provider();

		/**
		 * Filter the embedding provider used by the vector context service.
		 *
		 * Return any object implementing
		 * {@see WP_MCP_AI_Embedding_Provider_Interface} to override the
		 * default. Returning a non-implementing value falls back to the
		 * default.
		 *
		 * @since 1.1.0
		 *
		 * @param WP_MCP_AI_Embedding_Provider_Interface|null $default_provider Default provider, or null when none is available.
		 */
		$provider = apply_filters( 'wp_mcp_ai_embedding_provider', $default_provider );

		if ( ! ( $provider instanceof WP_MCP_AI_Embedding_Provider_Interface ) ) {
			$provider = $default_provider;
		}

		if ( ! ( $provider instanceof WP_MCP_AI_Embedding_Provider_Interface ) ) {
			return new WP_Error( 'no_embedding_provider', __( 'No embedding provider is configured. Configure an OpenAI API key, a Gemini API key, an Ollama endpoint, or a DigitalOcean API key.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $provider->is_available() ) {
			return new WP_Error(
				'embedding_provider_unavailable',
				sprintf(
					/* translators: %s: provider id (e.g. "openai", "ollama"). */
					__( 'Embedding provider "%s" is not configured.', 'mcp-ai-wpoos' ),
					$provider->get_id()
				)
			);
		}

		$this->embedding_provider = $provider;
		return $provider;
	}

	/**
	 * Reset the cached embedding provider so the next call re-resolves it.
	 *
	 * Useful when settings change at runtime or in tests.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function reset_embedding_provider() {
		$this->embedding_provider = null;
		$this->openai_client      = null;
	}

	/**
	 * Pick the default provider based on plugin configuration.
	 *
	 * @return WP_MCP_AI_Embedding_Provider_Interface|null
	 */
	private function resolve_default_provider() {
		$settings         = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$has_openai       = ! empty( $settings['openai_api_key'] );
		$has_ollama       = ! empty( $settings['ollama_endpoint_url'] );
		$has_digitalocean = ! empty( $settings['digitalocean_api_key'] );
		$has_gemini       = ! empty( $settings['gemini_api_key'] );
		$preference       = isset( $settings['embedding_provider'] ) ? (string) $settings['embedding_provider'] : '';

		// Honour an explicit preference if its backend is available.
		if ( 'ollama' === $preference && $has_ollama ) {
			return new WP_MCP_AI_Embedding_Provider_Ollama();
		}
		if ( 'openai' === $preference && $has_openai ) {
			return new WP_MCP_AI_Embedding_Provider_OpenAI();
		}
		if ( 'digitalocean' === $preference && $has_digitalocean ) {
			return new WP_MCP_AI_Embedding_Provider_DigitalOcean();
		}
		if ( 'gemini' === $preference && $has_gemini ) {
			return new WP_MCP_AI_Embedding_Provider_Gemini();
		}

		// Auto-detect: prefer OpenAI when present (preserves prior behaviour
		// for existing installs); fall back to Ollama for local-first sites,
		// then Gemini and DigitalOcean.
		if ( $has_openai ) {
			return new WP_MCP_AI_Embedding_Provider_OpenAI();
		}
		if ( $has_ollama ) {
			return new WP_MCP_AI_Embedding_Provider_Ollama();
		}
		if ( $has_gemini ) {
			return new WP_MCP_AI_Embedding_Provider_Gemini();
		}
		if ( $has_digitalocean ) {
			return new WP_MCP_AI_Embedding_Provider_DigitalOcean();
		}

		return null;
	}

	/**
	 * Load the embedding-provider interface and built-in implementations
	 * on demand. The interface lives outside the autoload classmap because
	 * it is a sibling of `interface-wp-mcp-ai-tool.php` (also loaded on demand).
	 *
	 * @return void
	 */
	private function ensure_provider_classes_loaded() {
		if ( ! interface_exists( 'WP_MCP_AI_Embedding_Provider_Interface' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-embedding-provider.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Embedding_Provider_OpenAI' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/embedding/class-wp-mcp-ai-embedding-provider-openai.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Embedding_Provider_Ollama' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/embedding/class-wp-mcp-ai-embedding-provider-ollama.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Embedding_Provider_DigitalOcean' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/embedding/class-wp-mcp-ai-embedding-provider-digitalocean.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Embedding_Provider_Gemini' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/embedding/class-wp-mcp-ai-embedding-provider-gemini.php';
		}
	}

	/**
	 * Get OpenAI client instance.
	 *
	 * @deprecated 1.1.0 Use {@see self::get_embedding_provider()} instead.
	 *                   Retained as a thin wrapper so external code that
	 *                   reflectively reaches into this service keeps working.
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
