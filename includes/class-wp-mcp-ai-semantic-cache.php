<?php
/**
 * Semantic Cache — Universal prompt caching layer to reduce API costs
 * and latency by serving cached responses for semantically similar prompts.
 *
 * Implements a two-tier cache: exact-match (fast, free) and semantic
 * similarity (slower, uses embedding comparison). Integrates with the
 * framework-agnostic SemanticCompressorInterface from lib/core.
 *
 * @package WP_MCP_AI
 * @since   1.1.51
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Semantic caching layer for LLM responses.
 *
 * @since 1.1.51
 */
class WP_MCP_AI_Semantic_Cache {

	/**
	 * Cache group for exact-match cache entries.
	 *
	 * @var string
	 */
	const CACHE_GROUP_EXACT = 'wp_mcp_ai_semcache_exact';

	/**
	 * Cache group for semantic-similarity cache entries.
	 *
	 * @var string
	 */
	const CACHE_GROUP_SEMANTIC = 'wp_mcp_ai_semcache_semantic';

	/**
	 * Default TTL for cached responses in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 3600;

	/**
	 * Maximum cache entries per group before pruning.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 1000;

	/**
	 * Whether the cache is enabled globally.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'wp_mcp_ai_semantic_cache_enabled', false );
	}

	/**
	 * Get a cached response for a prompt.
	 *
	 * Checks exact match first (fast), then semantic similarity (slower).
	 *
	 * @param string $prompt      The prompt text.
	 * @param string $model       Model identifier.
	 * @param array  $options     Cache options (ttl, similarity_threshold, etc.).
	 * @return array|null Cached response array or null on miss.
	 */
	public static function get( $prompt, $model = '', $options = array() ) {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$ttl         = isset( $options['ttl'] ) ? absint( $options['ttl'] ) : self::DEFAULT_TTL;
		$threshold   = isset( $options['similarity_threshold'] ) ? (float) $options['similarity_threshold'] : 0.85;
		$prompt_hash = md5( $prompt . $model );

		// Tier 1: Exact match.
		$cached = wp_cache_get( $prompt_hash, self::CACHE_GROUP_EXACT );
		if ( false !== $cached && is_array( $cached ) ) {
			if ( isset( $cached['expires'] ) && $cached['expires'] > time() ) {
				return $cached['response'];
			}
			// Expired — remove from exact cache.
			wp_cache_delete( $prompt_hash, self::CACHE_GROUP_EXACT );
		}

		// Tier 2: Semantic similarity (only if embeddings are available).
		if ( function_exists( 'wp_mcp_ai_get_embeddings' ) && ! empty( $model ) ) {
			$prompt_embedding = self::get_embedding( $prompt, $model );
			if ( null !== $prompt_embedding ) {
				$semantic_cache = self::get_semantic_cache_entries();
				$best_match     = null;
				$best_score     = 0.0;

				foreach ( $semantic_cache as $entry ) {
					if ( ! isset( $entry['embedding'] ) || ! isset( $entry['response'] ) ) {
						continue;
					}
					if ( isset( $entry['expires'] ) && $entry['expires'] <= time() ) {
						continue;
					}

					$similarity = self::cosine_similarity( $prompt_embedding, $entry['embedding'] );
					if ( $similarity >= $threshold && $similarity > $best_score ) {
						$best_match = $entry['response'];
						$best_score = $similarity;
					}
				}

				if ( null !== $best_match ) {
					return $best_match;
				}
			}
		}

		return null;
	}

	/**
	 * Store a response in the cache.
	 *
	 * @param string $prompt    The prompt text.
	 * @param array  $response  The LLM response array.
	 * @param string $model     Model identifier.
	 * @param array  $options   Cache options.
	 * @return bool True on success.
	 */
	public static function set( $prompt, $response, $model = '', $options = array() ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$ttl         = isset( $options['ttl'] ) ? absint( $options['ttl'] ) : self::DEFAULT_TTL;
		$prompt_hash = md5( $prompt . $model );
		$entry       = array(
			'response' => $response,
			'expires'  => time() + $ttl,
			'stored'   => current_time( 'mysql' ),
			'model'    => $model,
			'prompt_hash' => $prompt_hash,
		);

		// Tier 1: Exact match cache.
		$result = wp_cache_set( $prompt_hash, $entry, self::CACHE_GROUP_EXACT, $ttl );

		// Tier 2: Semantic cache (if embeddings available).
		if ( function_exists( 'wp_mcp_ai_get_embeddings' ) && ! empty( $model ) ) {
			$prompt_embedding = self::get_embedding( $prompt, $model );
			if ( null !== $prompt_embedding ) {
				$entry['embedding'] = $prompt_embedding;
				self::add_semantic_cache_entry( $entry );
			}
		}

		// Prune if over limit.
		self::maybe_prune();

		return $result;
	}

	/**
	 * Invalidate all cache entries.
	 *
	 * @return bool True on success.
	 */
	public static function flush() {
		wp_cache_flush_group( self::CACHE_GROUP_EXACT );
		delete_option( 'wp_mcp_ai_semcache_semantic_store' );
		return true;
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Stats array with exact_count, semantic_count, hits, misses.
	 */
	public static function get_stats() {
		$semantic_entries = self::get_semantic_cache_entries();
		return array(
			'semantic_count' => count( $semantic_entries ),
			'exact_count'    => 0, // wp_cache doesn't expose count; track externally.
			'enabled'        => self::is_enabled(),
		);
	}

	/**
	 * Get an embedding vector for a text string.
	 *
	 * @param string $text  Text to embed.
	 * @param string $model Model to use for embeddings.
	 * @return array|null Embedding vector or null on failure.
	 */
	private static function get_embedding( $text, $model ) {
		// Use the plugin's embedding helper if available.
		if ( function_exists( 'wp_mcp_ai_get_embeddings' ) ) {
			$result = wp_mcp_ai_get_embeddings( $text, $model );
			if ( is_array( $result ) && ! empty( $result ) ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Get all semantic cache entries from persistent storage.
	 *
	 * @return array Array of cache entries.
	 */
	private static function get_semantic_cache_entries() {
		$entries = get_option( 'wp_mcp_ai_semcache_semantic_store', array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return $entries;
	}

	/**
	 * Add a semantic cache entry to persistent storage.
	 *
	 * @param array $entry Cache entry with embedding.
	 */
	private static function add_semantic_cache_entry( $entry ) {
		$entries   = self::get_semantic_cache_entries();
		$entries[] = $entry;

		// Keep only the most recent entries.
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( 'wp_mcp_ai_semcache_semantic_store', $entries, false );
	}

	/**
	 * Compute cosine similarity between two vectors.
	 *
	 * @param array $a First vector.
	 * @param array $b Second vector.
	 * @return float Similarity score (0-1).
	 */
	private static function cosine_similarity( $a, $b ) {
		$dot_product = 0.0;
		$norm_a      = 0.0;
		$norm_b      = 0.0;

		$count = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$dot_product += $a[ $i ] * $b[ $i ];
			$norm_a      += $a[ $i ] * $a[ $i ];
			$norm_b      += $b[ $i ] * $b[ $i ];
		}

		if ( 0.0 === $norm_a || 0.0 === $norm_b ) {
			return 0.0;
		}

		return $dot_product / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
	}

	/**
	 * Prune expired and excess cache entries.
	 */
	private static function maybe_prune() {
		$entries = self::get_semantic_cache_entries();
		$now     = time();
		$pruned  = array();

		foreach ( $entries as $entry ) {
			if ( isset( $entry['expires'] ) && $entry['expires'] > $now ) {
				$pruned[] = $entry;
			}
		}

		if ( count( $pruned ) > self::MAX_ENTRIES ) {
			$pruned = array_slice( $pruned, -self::MAX_ENTRIES );
		}

		update_option( 'wp_mcp_ai_semcache_semantic_store', $pruned, false );
	}
}
