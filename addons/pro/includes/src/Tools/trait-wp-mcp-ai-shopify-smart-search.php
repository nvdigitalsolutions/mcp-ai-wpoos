<?php
/**
 * Shopify Smart Search Trait — progressive query relaxation for better product search recall.
 *
 * Implements industry-standard e-commerce search patterns:
 * 1. Try the full original query first
 * 2. If zero results and query has 3+ meaningful tokens, progressively relax:
 *    a. Remove stop words and retry
 *    b. Generate overlapping keyword windows (trigrams, then bigrams)
 *    c. Run multiple sub-queries and merge/deduplicate results
 * 3. Score merged results by keyword match count for relevance ranking
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides progressive query relaxation for Shopify product searches.
 *
 * @since 1.0.0
 */
trait WP_MCP_AI_Shopify_Smart_Search {

	/**
	 * Minimum number of meaningful tokens needed to trigger decomposition.
	 *
	 * @var int
	 */
	protected static $min_tokens_for_decomposition = 3;

	/**
	 * Maximum number of sub-query API calls allowed during progressive relaxation.
	 *
	 * @var int
	 */
	protected static $max_sub_queries = 4;

	/**
	 * Common English stop words irrelevant to e-commerce product search.
	 *
	 * @var array
	 */
	protected static $stop_words = array(
		'a',
		'an',
		'the',
		'and',
		'or',
		'but',
		'in',
		'on',
		'at',
		'to',
		'for',
		'of',
		'with',
		'by',
		'from',
		'is',
		'it',
		'its',
		'as',
		'be',
		'was',
		'are',
		'been',
		'has',
		'have',
		'had',
		'do',
		'does',
		'did',
		'will',
		'would',
		'could',
		'should',
		'may',
		'might',
		'that',
		'this',
		'these',
		'those',
		'i',
		'me',
		'my',
		'we',
		'our',
		'you',
		'your',
		'he',
		'she',
		'they',
		'them',
		'their',
		'what',
		'which',
		'who',
		'whom',
		'how',
		'where',
		'when',
		'not',
		'no',
		'nor',
		'so',
		'if',
		'then',
		'than',
		'too',
		'very',
		'can',
		'just',
		'about',
		'also',
		'some',
		'any',
		'all',
	);

	/**
	 * Extract meaningful search tokens from a query string.
	 *
	 * Removes stop words and normalizes case. Preserves numeric values since
	 * they may represent product attributes (e.g. "2 carat", "14k").
	 *
	 * @param string $query The original search query.
	 * @return array Array of meaningful lowercase tokens.
	 */
	protected function extract_search_tokens( $query ) {
		// Normalize to lowercase and split on whitespace / common separators.
		$normalized = strtolower( trim( $query ) );
		$raw_tokens = preg_split( '/[\s,;|\/\-]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );

		$tokens = array();
		foreach ( $raw_tokens as $token ) {
			// Strip non-alphanumeric edges (quotes, brackets, etc.).
			$token = preg_replace( '/^[^a-z0-9]+|[^a-z0-9]+$/i', '', $token );
			if ( '' === $token ) {
				continue;
			}
			if ( in_array( $token, self::$stop_words, true ) ) {
				continue;
			}
			$tokens[] = $token;
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Generate progressively shorter sub-queries from meaningful tokens.
	 *
	 * Uses a sliding-window approach to produce overlapping keyword groups
	 * ordered from broadest (largest window) to narrowest. Numeric-only tokens
	 * are attached to their neighbours rather than appearing alone.
	 *
	 * Strategy:
	 * 1. Full token string with stop words removed (if different from original).
	 * 2. Sliding trigrams (windows of 3 tokens).
	 * 3. Sliding bigrams (windows of 2 tokens).
	 *
	 * The number of generated sub-queries is capped at $max_sub_queries.
	 *
	 * @param array  $tokens  Meaningful tokens (from extract_search_tokens).
	 * @param string $original_query The original raw query for comparison.
	 * @return array Ordered array of sub-query strings (most broad first).
	 */
	protected function generate_sub_queries( array $tokens, $original_query = '' ) {
		$sub_queries = array();
		$token_count = count( $tokens );

		// 1. Full token string (stop-words removed). Only include if it differs
		// meaningfully from the original query.
		$full_cleaned        = implode( ' ', $tokens );
		$original_normalized = strtolower( trim( $original_query ) );
		if ( $full_cleaned !== $original_normalized && '' !== $full_cleaned ) {
			$sub_queries[] = $full_cleaned;
		}

		// 2. Try dropping purely numeric tokens (measurements/quantities) to
		// broaden the search, e.g. "solitaire round diamond engagement ring"
		// from "solitaire round diamond engagement ring 2 carat".
		$non_numeric_tokens = array_values(
			array_filter(
				$tokens,
				function ( $t ) {
					return ! preg_match( '/^\d+(\.\d+)?$/', $t );
				}
			)
		);
		if ( count( $non_numeric_tokens ) < $token_count && count( $non_numeric_tokens ) >= 2 ) {
			$without_numbers = implode( ' ', $non_numeric_tokens );
			if ( ! in_array( $without_numbers, $sub_queries, true ) ) {
				$sub_queries[] = $without_numbers;
			}
		}

		// 3. Sliding trigrams (windows of 3 tokens).
		if ( $token_count > 3 ) {
			for ( $i = 0; $i <= $token_count - 3; $i++ ) {
				$trigram = implode( ' ', array_slice( $tokens, $i, 3 ) );
				if ( ! in_array( $trigram, $sub_queries, true ) ) {
					$sub_queries[] = $trigram;
				}
			}
		}

		// 4. Sliding bigrams (windows of 2 tokens) — only if we still need more.
		if ( count( $sub_queries ) < self::$max_sub_queries && $token_count > 2 ) {
			for ( $i = 0; $i <= $token_count - 2; $i++ ) {
				$bigram = implode( ' ', array_slice( $tokens, $i, 2 ) );
				if ( ! in_array( $bigram, $sub_queries, true ) ) {
					$sub_queries[] = $bigram;
				}
			}
		}

		return array_slice( $sub_queries, 0, self::$max_sub_queries );
	}

	/**
	 * Check whether a query is a candidate for smart search decomposition.
	 *
	 * A query qualifies when it has at least $min_tokens_for_decomposition
	 * meaningful tokens after stop-word removal.
	 *
	 * @param string $query The search query.
	 * @return bool True if the query should be decomposed on zero results.
	 */
	protected function should_decompose_query( $query ) {
		$tokens = $this->extract_search_tokens( $query );
		return count( $tokens ) >= self::$min_tokens_for_decomposition;
	}

	/**
	 * Deduplicate and rank products from multiple sub-query result sets.
	 *
	 * Each product is identified by a unique key (callback-provided). Products
	 * appearing in more sub-query results are ranked higher. Within the same
	 * match count the original encounter order is preserved.
	 *
	 * @param array    $result_sets Array of arrays, each containing product arrays.
	 * @param callable $get_id_callback Callback that receives a product array and
	 *                                  returns a unique string identifier.
	 * @param int      $limit Maximum number of products to return.
	 * @return array Deduplicated, relevance-sorted product array.
	 */
	protected function merge_and_rank_products( array $result_sets, callable $get_id_callback, $limit = 50 ) {
		$seen     = array();  // id => index in $products.
		$products = array();
		$scores   = array();  // id => match count.

		foreach ( $result_sets as $set ) {
			foreach ( $set as $product ) {
				$id = call_user_func( $get_id_callback, $product );
				if ( '' === $id || null === $id ) {
					continue;
				}

				if ( isset( $seen[ $id ] ) ) {
					// Already seen — increment relevance score.
					++$scores[ $id ];
				} else {
					$index         = count( $products );
					$seen[ $id ]   = $index;
					$products[]    = $product;
					$scores[ $id ] = 1;
				}
			}
		}

		// Sort by score descending, preserving original order for ties.
		$scored_products = array();
		foreach ( $products as $i => $product ) {
			$id                = call_user_func( $get_id_callback, $product );
			$scored_products[] = array(
				'product' => $product,
				'score'   => isset( $scores[ $id ] ) ? $scores[ $id ] : 0,
				'order'   => $i,
			);
		}

		usort(
			$scored_products,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['order'] - $b['order'];
				}
				return $b['score'] - $a['score'];
			}
		);

		$result = array();
		foreach ( $scored_products as $entry ) {
			$result[] = $entry['product'];
			if ( count( $result ) >= $limit ) {
				break;
			}
		}

		return $result;
	}
}
