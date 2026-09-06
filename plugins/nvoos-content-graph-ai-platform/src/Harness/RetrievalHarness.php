<?php
/**
 * Retrieval Harness — Layer D façade over recall + semantic search + memory mining.
 *
 * Single entry point that fans an arbitrary query out to the existing
 * retrieval primitives — `recall_memory`, `semantic_context_search`,
 * `retrieve_agent_memory` — merges their results, attaches provenance
 * metadata (source slug, timestamp, content hash), de-duplicates by
 * `content_hash`, and ranks by a composite score that blends each
 * underlying tool's confidence with a freshness factor.
 *
 * The façade is deliberately defensive: any individual underlying tool may
 * be missing on a given install (e.g. JetEngine not active) and the harness
 * must still return a useful result. Each tool is wrapped in a try/error
 * boundary so a misbehaving downstream cannot break the whole call.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieval Harness façade.
 */
class RetrievalHarness {

	/**
	 * Maximum number of passages returned regardless of caller `k`.
	 */
	const HARD_K_CAP = 50;

	/**
	 * Source tools tried, in order of preference. Each entry: array(
	 *   'tool_slug', 'arg_factory_callable', 'extractor_callable'
	 * ).
	 *
	 * @return array
	 */
	private static function source_tools() {
		return array(
			'recall_memory',
			'semantic_context_search',
			'retrieve_agent_memory',
		);
	}

	/**
	 * Retrieve passages with provenance.
	 *
	 * @param string $query   User query.
	 * @param array  $scope   Optional scoping (assistant_id, wing, room, task_class).
	 * @param int    $k       Desired number of passages (clamped to HARD_K_CAP).
	 * @param array  $context Tool execution context (forwarded to underlying tools).
	 * @return array{
	 *   passages:array<int,array{text:string,source:string,score:float,freshness:float,citation:array}>,
	 *   citations:array<int,array>,
	 *   freshness:float,
	 *   recall_confidence:float,
	 *   sources_tried:array<int,string>
	 * }
	 */
	public static function retrieve( $query, array $scope = array(), $k = 5, array $context = array() ) {
		$query = (string) $query;
		$k     = max( 1, min( self::HARD_K_CAP, (int) $k ) );

		$registry = self::registry();
		$results  = array();
		$tried    = array();

		foreach ( self::source_tools() as $tool_slug ) {
			$tried[] = $tool_slug;
			$tool    = $registry ? $registry->get_tool( $tool_slug ) : null;
			if ( ! $tool instanceof \WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$args = self::build_args_for( $tool_slug, $query, $scope, $k );
			if ( null === $args ) {
				continue;
			}

			try {
				$raw = $tool->execute( $args, $context );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( is_wp_error( $raw ) ) {
				continue;
			}

			$normalized = self::normalize_results( $raw, $tool_slug );
			foreach ( $normalized as $entry ) {
				$results[] = $entry;
			}
		}

		// Deduplicate by content hash, keeping the highest-scoring entry.
		$by_hash = array();
		foreach ( $results as $entry ) {
			$hash = $entry['citation']['content_hash'];
			if ( ! isset( $by_hash[ $hash ] ) || $by_hash[ $hash ]['score'] < $entry['score'] ) {
				$by_hash[ $hash ] = $entry;
			}
		}

		$deduped = array_values( $by_hash );

		// Apply freshness blending: 70% raw score + 30% freshness.
		foreach ( $deduped as &$entry ) {
			$entry['ranked_score'] = ( 0.7 * $entry['score'] ) + ( 0.3 * $entry['freshness'] );
		}
		unset( $entry );

		usort(
			$deduped,
			static function ( $a, $b ) {
				if ( $a['ranked_score'] === $b['ranked_score'] ) {
					return 0;
				}
				return $a['ranked_score'] < $b['ranked_score'] ? 1 : -1;
			}
		);

		$top = array_slice( $deduped, 0, $k );

		/**
		 * Filter the final ranked passages before they leave the harness.
		 *
		 * @param array  $top      Top-k passages.
		 * @param string $query    Query string.
		 * @param array  $scope    Scope hash.
		 * @param array  $context  Tool execution context.
		 */
		$top = (array) apply_filters( 'wp_mcp_ai_retrieval_passages', $top, $query, $scope, $context );

		$citations         = array();
		$freshness_total   = 0.0;
		$recall_confidence = 0.0;
		foreach ( $top as $entry ) {
			$citations[]        = $entry['citation'];
			$freshness_total   += (float) $entry['freshness'];
			$recall_confidence += (float) $entry['score'];
		}
		$count = max( 1, count( $top ) );

		return array(
			'passages'          => $top,
			'citations'         => $citations,
			'freshness'         => round( $freshness_total / $count, 4 ),
			'recall_confidence' => round( $recall_confidence / $count, 4 ),
			'sources_tried'     => $tried,
		);
	}

	/**
	 * Verify that every claim-like sentence in the answer can be backed by
	 * the supplied passages. Implementation is conservative: it requires at
	 * least one shared 5-gram between the sentence and a passage. Callers
	 * may filter the predicate via `wp_mcp_ai_retrieval_claim_supported` to
	 * substitute a smarter (e.g. semantic) check.
	 *
	 * @param string $answer   Answer text.
	 * @param array  $passages Passage list as returned by retrieve().
	 * @return array{
	 *   covered:bool,
	 *   coverage_ratio:float,
	 *   unsupported:array<int,string>
	 * }
	 */
	public static function verify_citations( $answer, array $passages ) {
		$answer = trim( (string) $answer );
		if ( '' === $answer ) {
			return array(
				'covered'        => true,
				'coverage_ratio' => 1.0,
				'unsupported'    => array(),
			);
		}

		$sentences = preg_split( '/(?<=[\.!\?])\s+/', $answer );
		if ( ! is_array( $sentences ) ) {
			$sentences = array( $answer );
		}

		$passage_ngrams = array();
		foreach ( $passages as $entry ) {
			$text             = isset( $entry['text'] ) ? (string) $entry['text'] : '';
			$passage_ngrams[] = self::ngrams( $text, 5 );
		}

		$total       = 0;
		$supported   = 0;
		$unsupported = array();
		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( '' === $sentence || strlen( $sentence ) < 12 ) {
				continue;
			}
			++$total;
			$sentence_ngrams = self::ngrams( $sentence, 5 );
			$is_supported    = false;
			foreach ( $passage_ngrams as $pngrams ) {
				if ( ! empty( array_intersect_key( $sentence_ngrams, $pngrams ) ) ) {
					$is_supported = true;
					break;
				}
			}

			/**
			 * Filter whether a sentence is considered supported by the retrieved passages.
			 *
			 * @param bool   $is_supported Default decision.
			 * @param string $sentence     Sentence under test.
			 * @param array  $passages     Passage list.
			 */
			$is_supported = (bool) apply_filters( 'wp_mcp_ai_retrieval_claim_supported', $is_supported, $sentence, $passages );

			if ( $is_supported ) {
				++$supported;
			} else {
				$unsupported[] = $sentence;
			}
		}

		if ( 0 === $total ) {
			return array(
				'covered'        => true,
				'coverage_ratio' => 1.0,
				'unsupported'    => array(),
			);
		}

		return array(
			'covered'        => empty( $unsupported ),
			'coverage_ratio' => round( $supported / $total, 4 ),
			'unsupported'    => $unsupported,
		);
	}

	/**
	 * Build the argument array for a given underlying tool.
	 *
	 * @param string $slug  Tool slug.
	 * @param string $query Query.
	 * @param array  $scope Scope hash.
	 * @param int    $k     Desired k.
	 * @return array|null
	 */
	private static function build_args_for( $slug, $query, array $scope, $k ) {
		switch ( $slug ) {
			case 'recall_memory':
				return array(
					'query' => $query,
					'wing'  => isset( $scope['wing'] ) ? (string) $scope['wing'] : '',
					'room'  => isset( $scope['room'] ) ? (string) $scope['room'] : '',
					'limit' => $k,
				);
			case 'semantic_context_search':
				return array(
					'query' => $query,
					'limit' => $k,
				);
			case 'retrieve_agent_memory':
				return array(
					'query'        => $query,
					'limit'        => $k,
					'assistant_id' => isset( $scope['assistant_id'] ) ? (int) $scope['assistant_id'] : 0,
				);
			default:
				return null;
		}
	}

	/**
	 * Normalize a tool result into the harness passage shape. Defensive — if
	 * the tool returns an unexpected shape we yield an empty list instead of
	 * throwing.
	 *
	 * @param mixed  $raw  Raw tool result.
	 * @param string $slug Tool slug (used for provenance).
	 * @return array<int,array>
	 */
	private static function normalize_results( $raw, $slug ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$candidates = array();
		if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
			$candidates = $raw['data'];
		} elseif ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) {
			$candidates = $raw['results'];
		} elseif ( isset( $raw['memories'] ) && is_array( $raw['memories'] ) ) {
			$candidates = $raw['memories'];
		} elseif ( self::looks_like_list( $raw ) ) {
			$candidates = $raw;
		}

		$out = array();
		foreach ( $candidates as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$text = '';
			foreach ( array( 'content', 'text', 'passage', 'body', 'snippet' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) ) {
					$text = $entry[ $key ];
					break;
				}
			}
			if ( '' === trim( $text ) ) {
				continue;
			}

			$score = 0.5;
			foreach ( array( 'score', 'similarity', 'relevance', 'confidence' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_numeric( $entry[ $key ] ) ) {
					$score = (float) $entry[ $key ];
					break;
				}
			}
			if ( $score > 1.0 ) {
				// Some tools express score on 0-100 scale.
				$score = $score / 100.0;
			}

			$timestamp = 0;
			foreach ( array( 'timestamp', 'created_at', 'modified_at', 'date' ) as $key ) {
				if ( isset( $entry[ $key ] ) ) {
					$ts = is_numeric( $entry[ $key ] ) ? (int) $entry[ $key ] : strtotime( (string) $entry[ $key ] );
					if ( $ts > 0 ) {
						$timestamp = $ts;
						break;
					}
				}
			}

			$source_id = '';
			foreach ( array( 'id', 'post_id', 'memory_id', 'session_key', 'content_hash' ) as $key ) {
				if ( isset( $entry[ $key ] ) ) {
					$source_id = (string) $entry[ $key ];
					break;
				}
			}

			$content_hash = isset( $entry['content_hash'] ) && is_string( $entry['content_hash'] )
				? $entry['content_hash']
				: md5( $slug . '|' . $text );

			$out[] = array(
				'text'      => $text,
				'source'    => $slug,
				'score'     => max( 0.0, min( 1.0, $score ) ),
				'freshness' => self::freshness_score( $timestamp ),
				'citation'  => array(
					'source'       => $slug,
					'source_id'    => $source_id,
					'timestamp'    => $timestamp,
					'content_hash' => $content_hash,
				),
			);
		}

		return $out;
	}

	/**
	 * Heuristic: does an array look like a numeric list?
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function looks_like_list( $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		$keys = array_keys( $arr );
		return range( 0, count( $keys ) - 1 ) === $keys;
	}

	/**
	 * Build a simple ngram set for a string, lowercased and whitespace-collapsed.
	 *
	 * @param string $text Input text.
	 * @param int    $n    Token count per ngram.
	 * @return array<string,bool>
	 */
	private static function ngrams( $text, $n ) {
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $text ) ) );
		if ( '' === $text ) {
			return array();
		}
		$tokens = preg_split( '/\s+/', $text );
		if ( ! is_array( $tokens ) ) {
			return array();
		}
		$count = count( $tokens );
		if ( $count < $n ) {
			return array( implode( ' ', $tokens ) => true );
		}
		$grams = array();
		for ( $i = 0; $i <= $count - $n; ++$i ) {
			$gram           = implode( ' ', array_slice( $tokens, $i, $n ) );
			$grams[ $gram ] = true;
		}
		return $grams;
	}

	/**
	 * Compute a freshness score on [0,1] from a unix timestamp. Items
	 * younger than 1 day score 1.0; older than 365 days score 0.0; linear
	 * decay between.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return float
	 */
	private static function freshness_score( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return 0.5; // unknown -> neutral.
		}
		$age = max( 0, time() - $timestamp );
		if ( $age <= DAY_IN_SECONDS ) {
			return 1.0;
		}
		$max_age = 365 * DAY_IN_SECONDS;
		if ( $age >= $max_age ) {
			return 0.0;
		}
		return round( 1.0 - ( ( $age - DAY_IN_SECONDS ) / ( $max_age - DAY_IN_SECONDS ) ), 4 );
	}

	/**
	 * Resolve the tool registry, defensively.
	 *
	 * @return WP_MCP_AI_Tool_Registry|null
	 */
	private static function registry() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return null;
		}
		return \WP_MCP_AI_Tool_Registry::get_instance();
	}
}
