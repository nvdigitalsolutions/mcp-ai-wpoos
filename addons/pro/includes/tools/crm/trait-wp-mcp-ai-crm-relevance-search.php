<?php
/**
 * CRM Relevance Search Trait — lightweight TF-IDF relevance scoring and free-text search.
 *
 * Provides a PHP-native TF-IDF scorer that can be applied to CRM contact records
 * after WP_Query filtering. When a `search` keyword is supplied, the standard
 * meta-filter query first narrows the candidate set, then this scorer ranks results
 * by weighted field relevance.
 *
 * Industry context:
 * - BM25 is the gold standard for production CRM search, but requires an external
 *   library (e.g. Meilisearch, Elasticsearch) or a FULLTEXT-indexed custom table.
 * - This trait provides a pragmatic TF-IDF implementation suitable for the typical
 *   CRM contact volume (hundreds to low thousands of records on a WordPress site).
 * - When the dataset grows past ~5 000 contacts, consider upgrading to MySQL
 *   FULLTEXT or a dedicated search engine.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight TF-IDF relevance scorer for CRM contact search.
 *
 * @since 2.4.0
 */
trait WP_MCP_AI_CRM_Relevance_Search {

	/**
	 * Default field weights for TF-IDF scoring.
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
	 * Extract searchable text for a CRM contact by post ID.
	 *
	 * Returns an associative array of field_name => text_value for scoring.
	 *
	 * @param int   $post_id        Contact post ID.
	 * @param array $field_weights  Map of field => weight (optional, uses defaults).
	 * @return array<string,string>
	 */
	protected function extract_searchable_text( $post_id, $field_weights = array() ) {
		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$text = array();

		if ( isset( $field_weights['name'] ) ) {
			$text['name'] = strtolower( get_the_title( $post_id ) );
		}
		if ( isset( $field_weights['company'] ) ) {
			$text['company'] = strtolower( (string) get_post_meta( $post_id, 'company', true ) );
		}
		if ( isset( $field_weights['email'] ) ) {
			$text['email'] = strtolower( (string) get_post_meta( $post_id, 'email', true ) );
		}

		return $text;
	}

	/**
	 * Compute a simple TF-IDF relevance score for a contact against a query.
	 *
	 * Scoring:
	 * - Term Frequency (TF): raw count of token occurrences in the contact's text fields.
	 * - Inverse Document Frequency (IDF): approximated as 1.0 since we don't have
	 *   a full corpus; longer tokens get a small length bonus (they're more specific).
	 * - Field weights: multiply the token score by the field's configured weight.
	 * - Final score: sum of weighted token scores, normalised to 0–100.
	 *
	 * @param string $query          User's search query.
	 * @param int    $post_id        Contact post ID.
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
			$token_len  = strlen( $token );
			// Longer tokens are more specific → higher IDF proxy.
			$idf_proxy  = 1.0 + log( max( 1, $token_len ) );

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
		// 30 is a reasonable ceiling for typical CRM contact text length.
		return min( 100.0, round( $total_score * ( 100.0 / 30.0 ), 1 ) );
	}

	/**
	 * Rank an array of CRM contact records by relevance to a search query.
	 *
	 * Adds a 'relevance_score' field to each record and sorts descending.
	 * Records with the same relevance score preserve their original order.
	 *
	 * @param array  $records       Array of contact records (each must have 'id').
	 * @param string $query         Search query.
	 * @param array  $field_weights Field weight map (optional).
	 * @return array Re-scored and sorted records.
	 */
	protected function rank_by_relevance( array $records, $query, $field_weights = array() ) {
		if ( empty( $query ) || empty( $records ) ) {
			return $records;
		}

		$scored = array();
		foreach ( $records as $i => $record ) {
			$post_id              = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
			$score                = $post_id ? $this->compute_relevance_score( $query, $post_id, $field_weights ) : 0.0;
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
