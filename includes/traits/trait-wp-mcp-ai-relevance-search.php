<?php
/**
 * Relevance Search Trait — TF-IDF and BM25 relevance scoring for free-text search.
 *
 * Provides PHP-native TF-IDF and BM25 scorers that can be applied to any post-type
 * records after WP_Query filtering. When a `search` keyword is supplied, the standard
 * query first narrows the candidate set, then this scorer ranks results by weighted
 * field relevance.
 *
 * Each consuming class overrides `$default_field_weights` and
 * `extract_searchable_text()` for its domain-specific fields.
 *
 * Algorithm selection:
 * - `tfidf` (default): fast, pragmatic for <5K records, token-length IDF proxy.
 * - `bm25`: industry-standard, handles TF saturation + document length normalisation,
 *   recommended for long-form content or >1K records.
 *
 * PHP 7.4+ compatible — no typed properties, no named arguments.
 *
 * @package WP_MCP_AI
 * @since 2.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TF-IDF and BM25 relevance scorer for content search tools.
 *
 * @since 2.4.0
 */
trait WP_MCP_AI_Relevance_Search {

	/**
	 * Default field weights for scoring.
	 *
	 * Higher weight = field contributes more to relevance score.
	 *
	 * @var array<string,float>
	 */
	protected $default_field_weights = array(
		'name'    => 3.0,
		'company' => 2.0,
		'email'   => 1.5,
	);

	/**
	 * BM25 k1 parameter — term frequency saturation.
	 *
	 * Higher values give more weight to term frequency.
	 * Range: 1.2–2.0 (default 1.5).
	 *
	 * @since 2.4.0
	 * @var float
	 */
	protected $bm25_k1 = 1.5;

	/**
	 * BM25 b parameter — document length normalisation.
	 *
	 * 0 = no normalisation, 1 = full normalisation.
	 * Range: 0.0–1.0 (default 0.75).
	 *
	 * @since 2.4.0
	 * @var float
	 */
	protected $bm25_b = 0.75;

	/**
	 * Common English stop words that add noise to search.
	 *
	 * @var string[]
	 */
	protected static $relevance_stop_words = array(
		'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
		'of', 'with', 'by', 'from', 'is', 'it', 'its', 'as', 'be', 'was',
		'are', 'been', 'has', 'have', 'had', 'do', 'does', 'did', 'will',
		'would', 'could', 'should', 'may', 'might', 'that', 'this', 'these',
		'those', 'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she',
		'they', 'them', 'their', 'what', 'which', 'who', 'whom', 'how',
		'where', 'when', 'not', 'no', 'nor', 'so', 'if', 'then', 'than',
		'too', 'very', 'can', 'just', 'about', 'also', 'some', 'any', 'all',
	);

	/**
	 * Tokenize a search query string into meaningful lowercase tokens.
	 *
	 * Removes stop words, normalises case, and splits on whitespace/punctuation.
	 *
	 * @param string $query Raw search query.
	 * @return string[] Normalised tokens.
	 */
	protected function tokenize_query( $query ) {
		$normalized = strtolower( trim( $query ) );
		$raw_tokens = preg_split( '/[\s,;|\/\-]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );

		$tokens = array();
		foreach ( $raw_tokens as $token ) {
			$token = preg_replace( '/^[^a-z0-9]+|[^a-z0-9]+$/i', '', $token );
			if ( '' === $token ) {
				continue;
			}
			if ( in_array( $token, self::$relevance_stop_words, true ) ) {
				continue;
			}
			$tokens[] = $token;
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Extract searchable text for a post by ID.
	 *
	 * Override in consuming classes for domain-specific fields.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $field_weights  Map of field => weight (optional, uses defaults).
	 * @return array<string,string>
	 */
	protected function extract_searchable_text( $post_id, $field_weights = array() ) {
		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$text = array();

		if ( isset( $field_weights['title'] ) ) {
			$text['title'] = strtolower( get_the_title( $post_id ) );
		}
		if ( isset( $field_weights['content'] ) ) {
			$text['content'] = strtolower( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
		}
		if ( isset( $field_weights['excerpt'] ) ) {
			$text['excerpt'] = strtolower( wp_strip_all_tags( get_post_field( 'post_excerpt', $post_id ) ) );
		}

		return $text;
	}

	// -------------------------------------------------------------------------
	// TF-IDF scoring
	// -------------------------------------------------------------------------

	/**
	 * Compute a simple TF-IDF relevance score for a record against a query.
	 *
	 * Scoring:
	 * - Term Frequency (TF): raw count of token occurrences in the record's text fields.
	 * - Inverse Document Frequency (IDF): approximated via token length (longer tokens
	 *   are more specific — no corpus-level IDF available).
	 * - Field weights: multiply the token score by the field's configured weight.
	 * - Final score: sum of weighted token scores, normalised to 0–100.
	 *
	 * @param string $query          User's search query.
	 * @param int    $post_id        Post ID.
	 * @param array  $field_weights  Field weight map (optional).
	 * @return float Relevance score (0–100).
	 */
	protected function compute_relevance_score( $query, $post_id, $field_weights = array() ) {
		$tokens = $this->tokenize_query( $query );
		if ( empty( $tokens ) ) {
			return 0.0;
		}

		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$field_texts = $this->extract_searchable_text( $post_id, $field_weights );
		if ( empty( $field_texts ) ) {
			return 0.0;
		}

		$total_score = 0.0;

		foreach ( $tokens as $token ) {
			$token_len = strlen( $token );
			// Longer tokens are more specific → higher IDF proxy.
			$idf_proxy = 1.0 + log( max( 1, $token_len ) );

			foreach ( $field_texts as $field => $text ) {
				if ( '' === $text ) {
					continue;
				}

				// Count token occurrences in this field (simple TF).
				$tf = substr_count( $text, $token );
				if ( 0 === $tf ) {
					continue;
				}

				$weight       = isset( $field_weights[ $field ] ) ? (float) $field_weights[ $field ] : 1.0;
				$total_score += $tf * $idf_proxy * $weight;
			}
		}

		// Normalise to 0–100.  The maximum theoretical score depends on field weights;
		// 30 is a reasonable ceiling for typical record text length.
		return min( 100.0, round( $total_score * ( 100.0 / 30.0 ), 1 ) );
	}

	// -------------------------------------------------------------------------
	// BM25 scoring
	// -------------------------------------------------------------------------

	/**
	 * Compute document length (word count) for a post's searchable fields.
	 *
	 * Used by BM25 for document length normalisation.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $field_weights  Field weight map (optional).
	 * @return int Word count across all weighted fields.
	 */
	protected function compute_doc_length( $post_id, $field_weights = array() ) {
		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$field_texts = $this->extract_searchable_text( $post_id, $field_weights );
		$total_words = 0;

		foreach ( $field_texts as $text ) {
			if ( '' !== $text ) {
				$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
				$total_words += is_array( $words ) ? count( $words ) : 0;
			}
		}

		return max( 1, $total_words ); // Never return 0 to avoid division by zero.
	}

	/**
	 * Compute corpus-level statistics for BM25 from a candidate record set.
	 *
	 * Returns:
	 * - `N`: total number of documents in the corpus.
	 * - `avgdl`: average document length (words) across the corpus.
	 * - `idf`: map of token → Robertson-Spärck Jones IDF score.
	 * - `doc_lengths`: map of post_id → word count.
	 *
	 * @since 2.4.0
	 *
	 * @param array  $records       Candidate records (each must have 'ID').
	 * @param array  $tokens        Query tokens.
	 * @param array  $field_weights Field weight map (optional).
	 * @return array{ N: int, avgdl: float, idf: array<string,float>, doc_lengths: array<int,int> }
	 */
	protected function compute_corpus_stats( array $records, array $tokens, $field_weights = array() ) {
		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$N           = count( $records );
		$doc_lengths = array();
		$total_dl    = 0;
		$token_df    = array(); // Document frequency per token.

		foreach ( $tokens as $token ) {
			$token_df[ $token ] = 0;
		}

		foreach ( $records as $record ) {
			$post_id   = isset( $record['ID'] ) ? absint( $record['ID'] ) : 0;
			$dl        = $post_id ? $this->compute_doc_length( $post_id, $field_weights ) : 1;
			$doc_lengths[ $post_id ] = $dl;
			$total_dl += $dl;

			if ( 0 === $post_id ) {
				continue;
			}

			$field_texts = $this->extract_searchable_text( $post_id, $field_weights );
			$all_text    = implode( ' ', $field_texts );

			// Count documents containing each query token.
			foreach ( $tokens as $token ) {
				if ( false !== strpos( $all_text, $token ) ) {
					$token_df[ $token ]++;
				}
			}
		}

		// Robertson-Spärck Jones IDF: log((N - df + 0.5) / (df + 0.5) + 1).
		$idf = array();
		foreach ( $tokens as $token ) {
			$df = $token_df[ $token ];
			if ( 0 === $df ) {
				$idf[ $token ] = 0.0;
			} else {
				$idf[ $token ] = log( ( $N - $df + 0.5 ) / ( $df + 0.5 ) + 1.0 );
			}
		}

		return array(
			'N'           => $N,
			'avgdl'       => $N > 0 ? $total_dl / $N : 1.0,
			'idf'         => $idf,
			'doc_lengths' => $doc_lengths,
		);
	}

	/**
	 * Compute BM25 relevance score for a single post against a query.
	 *
	 * BM25 formula:
	 *   score = Σ IDF(qi) * (tf * (k1 + 1)) / (tf + k1 * (1 - b + b * dl / avgdl))
	 *
	 * Where:
	 * - tf = term frequency in this document
	 * - dl = document length (word count)
	 * - avgdl = average document length across corpus
	 * - k1 = TF saturation parameter (default 1.5)
	 * - b = length normalisation parameter (default 0.75)
	 * - IDF(qi) = Robertson-Spärck Jones IDF from corpus
	 *
	 * @since 2.4.0
	 *
	 * @param string $query          User's search query (pre-tokenised if tokens provided).
	 * @param int    $post_id        Post ID.
	 * @param array  $field_weights  Field weight map (optional).
	 * @param float  $avgdl          Average document length (from corpus stats).
	 * @param array  $idf_map        Map of token → IDF score (from corpus stats).
	 * @return float BM25 relevance score.
	 */
	protected function compute_bm25_score( $query, $post_id, $field_weights = array(), $avgdl = 1.0, $idf_map = array() ) {
		$tokens = $this->tokenize_query( $query );
		if ( empty( $tokens ) ) {
			return 0.0;
		}

		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$field_texts = $this->extract_searchable_text( $post_id, $field_weights );
		if ( empty( $field_texts ) ) {
			return 0.0;
		}

		$dl   = $this->compute_doc_length( $post_id, $field_weights );
		$k1   = $this->bm25_k1;
		$b    = $this->bm25_b;
		$score = 0.0;

		foreach ( $tokens as $token ) {
			$idf = isset( $idf_map[ $token ] ) ? $idf_map[ $token ] : 0.0;
			if ( $idf <= 0.0 ) {
				continue;
			}

			foreach ( $field_texts as $field => $text ) {
				if ( '' === $text ) {
					continue;
				}

				$tf = substr_count( $text, $token );
				if ( 0 === $tf ) {
					continue;
				}

				$weight = isset( $field_weights[ $field ] ) ? (float) $field_weights[ $field ] : 1.0;

				// BM25 TF component with length normalisation.
				$numerator   = $tf * ( $k1 + 1.0 );
				$denominator = $tf + $k1 * ( 1.0 - $b + $b * ( $dl / max( 1.0, $avgdl ) ) );
				$score      += $idf * ( $numerator / max( 0.001, $denominator ) ) * $weight;
			}
		}

		return round( $score, 2 );
	}

	/**
	 * Rank records using BM25 relevance scoring.
	 *
	 * Computes corpus-level statistics (IDF, average doc length) from the full
	 * candidate set, then scores each record individually.
	 *
	 * @since 2.4.0
	 *
	 * @param array  $records       Candidate records (each must have 'ID').
	 * @param string $query         Search query.
	 * @param array  $field_weights Field weight map (optional).
	 * @return array Re-scored and sorted records.
	 */
	protected function rank_by_bm25( array $records, $query, $field_weights = array() ) {
		if ( empty( $query ) || empty( $records ) ) {
			return $records;
		}

		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$tokens = $this->tokenize_query( $query );
		if ( empty( $tokens ) ) {
			return $records;
		}

		// Compute corpus-level statistics from the full candidate set.
		$corpus = $this->compute_corpus_stats( $records, $tokens, $field_weights );
		$avgdl  = $corpus['avgdl'];
		$idf    = $corpus['idf'];

		$scored = array();
		foreach ( $records as $i => $record ) {
			$post_id = isset( $record['ID'] ) ? absint( $record['ID'] ) : 0;
			$score   = $post_id
				? $this->compute_bm25_score( $query, $post_id, $field_weights, $avgdl, $idf )
				: 0.0;
			$record['relevance_score'] = $score;
			$record['_original_index'] = $i;
			$scored[] = $record;
		}

		// Sort descending by relevance score, preserve original order on ties.
		usort(
			$scored,
			function ( $a, $b ) {
				$diff = $b['relevance_score'] - $a['relevance_score'];
				if ( abs( $diff ) < 0.001 ) {
					return $a['_original_index'] - $b['_original_index'];
				}
				return $diff > 0 ? 1 : -1;
			}
		);

		// Remove internal index key.
		foreach ( $scored as &$record ) {
			unset( $record['_original_index'] );
		}
		unset( $record );

		return $scored;
	}

	// -------------------------------------------------------------------------
	// Unified ranking dispatcher
	// -------------------------------------------------------------------------

	/**
	 * Rank an array of records by relevance to a search query.
	 *
	 * Dispatches to TF-IDF or BM25 based on `$algorithm`.
	 * Adds a 'relevance_score' field to each record and sorts descending.
	 *
	 * @param array  $records       Array of records (each must have 'ID').
	 * @param string $query         Search query.
	 * @param array  $field_weights Field weight map (optional).
	 * @param string $algorithm     'tfidf' (default) or 'bm25'.
	 * @return array Re-scored and sorted records.
	 */
	protected function rank_by_relevance( array $records, $query, $field_weights = array(), $algorithm = 'tfidf' ) {
		if ( 'bm25' === $algorithm ) {
			return $this->rank_by_bm25( $records, $query, $field_weights );
		}

		// Default: TF-IDF.
		if ( empty( $query ) || empty( $records ) ) {
			return $records;
		}

		$scored = array();
		foreach ( $records as $i => $record ) {
			$post_id = isset( $record['ID'] ) ? absint( $record['ID'] ) : 0;
			$score   = $post_id ? $this->compute_relevance_score( $query, $post_id, $field_weights ) : 0.0;
			$record['relevance_score'] = $score;
			$record['_original_index'] = $i;
			$scored[] = $record;
		}

		// Sort descending by relevance score, preserve original order on ties.
		usort(
			$scored,
			function ( $a, $b ) {
				$diff = $b['relevance_score'] - $a['relevance_score'];
				if ( abs( $diff ) < 0.001 ) {
					return $a['_original_index'] - $b['_original_index'];
				}
				return $diff > 0 ? 1 : -1;
			}
		);

		// Remove internal index key.
		foreach ( $scored as &$record ) {
			unset( $record['_original_index'] );
		}
		unset( $record );

		return $scored;
	}

	/**
	 * Sanitise and validate the orderby parameter.
	 *
	 * Returns a safe value suitable for WP_Query or post-loop sorting.
	 *
	 * @param string $raw           Raw orderby value from arguments.
	 * @param string $default       Default value if invalid.
	 * @param array  $allowed_keys  List of allowed orderby keys.
	 * @return string Sanitised orderby value.
	 */
	protected function sanitise_orderby( $raw, $default, array $allowed_keys ) {
		$value = sanitize_key( $raw );
		if ( ! in_array( $value, $allowed_keys, true ) ) {
			return $default;
		}
		return $value;
	}
}
