<?php
/**
 * Citation Verifier — Validates LLM claims against retrieved sources
 * to detect hallucinations and unsupported assertions.
 *
 * OWASP LLM09 (Misinformation / Overreliance) requires systems to help
 * users identify when AI-generated content may be inaccurate. This verifier
 * cross-references factual claims in LLM responses against the sources
 * retrieved by the Retrieval Harness (Layer D).
 *
 * The verifier is opt-in per assistant via the harness profile.
 *
 * @package WP_MCP_AI
 * @since   1.1.51
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citation verifier for hallucination detection.
 *
 * @since 1.1.51
 */
class CitationVerifier {

	/**
	 * Minimum similarity threshold for a claim to be considered verified.
	 *
	 * @var float
	 */
	const MIN_SIMILARITY = 0.3;

	/**
	 * Whether the subscriber has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook the citation verifier subscriber.
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'wp_mcp_ai_pre_response_render', array( __CLASS__, 'verify_citations' ), 5, 3 );
	}

	/**
	 * Verify that claims in the response are supported by retrieved sources.
	 *
	 * @param string $content      The LLM response content.
	 * @param int    $assistant_id Assistant post ID.
	 * @param array  $context      Additional context (retrieved_sources, etc.).
	 * @return string Content with verification annotations.
	 */
	public static function verify_citations( $content, $assistant_id, $context = array() ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// Check if citation verification is enabled for this assistant.
		$profile = self::get_profile( $assistant_id );
		if ( empty( $profile['retrieval']['require_citations'] ) ) {
			return $content;
		}

		// Check if we have retrieved sources to verify against.
		$sources = isset( $context['retrieved_sources'] ) ? $context['retrieved_sources'] : array();
		if ( empty( $sources ) ) {
			return $content;
		}

		// Extract factual claims from the response.
		$claims = self::extract_claims( $content );
		if ( empty( $claims ) ) {
			return $content;
		}

		// Verify each claim against retrieved sources.
		$unverified_count = 0;
		$verified_claims  = array();
		$annotations      = array();

		foreach ( $claims as $claim ) {
			$verified    = false;
			$best_source = '';

			foreach ( $sources as $source ) {
				$similarity = self::compute_similarity( $claim, $source );
				if ( $similarity >= self::MIN_SIMILARITY ) {
					$verified    = true;
					$best_source = $source;
					break;
				}
			}

			if ( $verified ) {
				$verified_claims[] = $claim;
			} else {
				++$unverified_count;
				$annotations[] = sprintf(
					/* translators: %s: the unverified claim text */
					__( '[⚠️ Unverified claim: "%s"]', 'nvoos-content-graph-ai-platform' ),
					wp_trim_words( $claim, 15 )
				);
			}
		}

		// If there are unverified claims, prepend a warning.
		if ( $unverified_count > 0 ) {
			$total_claims = count( $claims );
			$warning      = sprintf(
				"\n\n---\n🔍 *%d of %d claims in this response could not be verified against retrieved sources. Please verify independently.*\n",
				$unverified_count,
				$total_claims
			);

			if ( ! empty( $annotations ) ) {
				$warning .= implode( "\n", $annotations ) . "\n";
			}

			$warning .= "---\n";

			// Append warning at the end of the response.
			$content .= $warning;
		}

		/**
		 * Fires after citation verification completes.
		 *
		 * @since 1.1.51
		 *
		 * @param string $content          The annotated content.
		 * @param int    $assistant_id     The assistant ID.
		 * @param int    $verified_count   Number of verified claims.
		 * @param int    $unverified_count Number of unverified claims.
		 * @param int    $total_claims     Total claims extracted.
		 */
		do_action( 'wp_mcp_ai_citation_verification_complete', $content, $assistant_id, count( $verified_claims ), $unverified_count, count( $claims ) );

		return $content;
	}

	/**
	 * Extract factual claims from a text response.
	 *
	 * Uses sentence-level extraction: each sentence that contains a named
	 * entity or numeric value is treated as a potential factual claim.
	 *
	 * @param string $text Response text.
	 * @return array Array of claim strings.
	 */
	private static function extract_claims( $text ) {
		// Split into sentences.
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$claims    = array();

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( strlen( $sentence ) < 20 ) {
				continue; // Skip very short sentences.
			}

			// Heuristic: sentences containing numbers, percentages, dates, or
			// proper nouns are likely factual claims.
			if ( preg_match( '/\d+%|\d{4}|\b(?:is|are|was|were|has|have|had|will|can|must|should)\b/i', $sentence ) ) {
				$claims[] = $sentence;
			}
		}

		// Limit to prevent performance issues on long responses.
		return array_slice( $claims, 0, 20 );
	}

	/**
	 * Compute a simple Jaccard-like similarity between a claim and a source.
	 *
	 * Uses word-level intersection over union for efficiency. For production
	 * use, replace with an embedding-based similarity.
	 *
	 * @param string $claim  The claim text.
	 * @param string $source The source text.
	 * @return float Similarity score (0-1).
	 */
	private static function compute_similarity( $claim, $source ) {
		// Tokenize both texts to lowercase word sets.
		$claim_words  = self::tokenize( $claim );
		$source_words = self::tokenize( $source );

		if ( empty( $claim_words ) || empty( $source_words ) ) {
			return 0.0;
		}

		$intersection = array_intersect( $claim_words, $source_words );
		$union        = array_merge( $claim_words, $source_words );

		if ( empty( $union ) ) {
			return 0.0;
		}

		$similarity = count( $intersection ) / count( $union );

		// Boost score if key named entities appear in the source.
		$entities = self::extract_entities( $claim );
		if ( ! empty( $entities ) ) {
			$entity_hits = 0;
			foreach ( $entities as $entity ) {
				if ( false !== stripos( $source, $entity ) ) {
					++$entity_hits;
				}
			}
			if ( $entity_hits > 0 ) {
				$entity_boost = ( $entity_hits / count( $entities ) ) * 0.2;
				$similarity   = min( 1.0, $similarity + $entity_boost );
			}
		}

		return $similarity;
	}

	/**
	 * Tokenize text into lowercase word array, removing stop words.
	 *
	 * @param string $text Input text.
	 * @return array Array of unique lowercase words.
	 */
	private static function tokenize( $text ) {
		$words = preg_split( '/\W+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );

		// Remove common English stop words.
		$stop_words = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can', 'shall', 'this', 'that', 'these', 'those', 'it', 'its' );

		$words = array_diff( $words, $stop_words );

		return array_unique(
			array_filter(
				$words,
				function ( $w ) {
					return strlen( $w ) > 1;
				}
			)
		);
	}

	/**
	 * Extract named entities (capitalized multi-word phrases) from a claim.
	 *
	 * @param string $text Claim text.
	 * @return array Array of entity strings.
	 */
	private static function extract_entities( $text ) {
		$entities = array();

		// Simple heuristic: sequences of capitalized words.
		if ( preg_match_all( '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $text, $matches ) ) {
			$entities = $matches[1];
		}

		// Also capture numbers and dates.
		if ( preg_match_all( '/\b\d{4}\b/', $text, $matches ) ) {
			$entities = array_merge( $entities, $matches[0] );
		}

		return array_unique( $entities );
	}

	/**
	 * Get the harness profile for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Harness profile array.
	 */
	private static function get_profile( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return array();
		}

		$profile = get_post_meta( $assistant_id, '_wp_mcp_ai_harness_profile', true );
		if ( ! is_array( $profile ) ) {
			return array();
		}

		return $profile;
	}
}
