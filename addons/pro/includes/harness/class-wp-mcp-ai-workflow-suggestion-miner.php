<?php
/**
 * Workflow Suggestion Miner — ECC-style "instincts" for the Pro Workflow
 * Builder.
 *
 * Mines the Meta-Harness trace store (per-run `tool_calls.jsonl` written by
 * `WP_MCP_AI_Harness_Trace_Capture`) for recurring tool-chain n-grams and
 * scores them as candidate workflows: frequency × recency × success rate.
 * The result is the NV oOS analogue of ECC's continuous-learning instincts —
 * repeated wins surfaced as one-click reusable workflows instead of
 * re-instructed every session.
 *
 * Scoring (deterministic, no provider calls):
 *   - occurrences: how many runs contain the exact chain.
 *   - recency: newer runs weigh more (weight = 1 / (1 + run_index × 0.1)).
 *   - success rate: share of chains whose every tool call succeeded.
 *   - score = occurrences × recency_weight × (0.5 + 0.5 × success_rate).
 *
 * @credit  Pattern-extraction concept inspired by affaan-m/ECC
 *          continuous-learning instincts (MIT).
 * @package WP_MCP_AI_Pro
 * @since   1.1.97
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow suggestion miner.
 *
 * @since 1.1.97
 */
final class WP_MCP_AI_Workflow_Suggestion_Miner {

	/**
	 * Default maximum runs scanned per assistant.
	 *
	 * @var int
	 */
	const DEFAULT_RUNS = 20;

	/**
	 * Default minimum occurrences for a chain to be suggested.
	 *
	 * @var int
	 */
	const DEFAULT_MIN_OCCURRENCES = 2;

	/**
	 * Default maximum suggestions returned.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_RESULTS = 10;

	/**
	 * Mine recurring tool chains into workflow suggestions.
	 *
	 * @param int   $assistant_id Assistant post ID, or 0 to mine every
	 *                            assistant's traces.
	 * @param array $opts         Options: runs, min_occurrences, max_results,
	 *                            min_chain (default 2), max_chain (default 4).
	 * @return array{assistants_scanned: int, runs_scanned: int, suggestions: array}
	 */
	public static function mine( $assistant_id = 0, array $opts = array() ) {
		$assistant_id = absint( $assistant_id );

		$runs_limit      = isset( $opts['runs'] ) ? max( 1, min( 100, (int) $opts['runs'] ) ) : self::DEFAULT_RUNS;
		$min_occurrences = isset( $opts['min_occurrences'] ) ? max( 1, (int) $opts['min_occurrences'] ) : self::DEFAULT_MIN_OCCURRENCES;
		$max_results     = isset( $opts['max_results'] ) ? max( 1, min( 50, (int) $opts['max_results'] ) ) : self::DEFAULT_MAX_RESULTS;
		$min_chain       = isset( $opts['min_chain'] ) ? max( 2, (int) $opts['min_chain'] ) : 2;
		$max_chain       = isset( $opts['max_chain'] ) ? max( $min_chain, min( 5, (int) $opts['max_chain'] ) ) : 4;

		$assistant_ids = $assistant_id > 0 ? array( $assistant_id ) : self::discover_assistant_ids();
		if ( empty( $assistant_ids ) ) {
			return array(
				'assistants_scanned' => 0,
				'runs_scanned'       => 0,
				'suggestions'        => array(),
			);
		}

		$chains       = array();
		$runs_scanned = 0;
		$run_index    = 0;

		foreach ( $assistant_ids as $aid ) {
			$runs = WP_MCP_AI_Harness_Trace_Store::list_runs( $aid, $runs_limit );

			foreach ( $runs as $run ) {
				$run_id = isset( $run['run_id'] ) ? (string) $run['run_id'] : '';
				if ( '' === $run_id ) {
					continue;
				}

				$records = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'tool_calls.jsonl', $aid );
				if ( ! is_array( $records ) || count( $records ) < $min_chain ) {
					continue;
				}

				++$runs_scanned;

				$slugs          = self::extract_slugs( $records );
				$recency_weight = 1 / ( 1 + $run_index * 0.1 );
				$slug_count     = count( $slugs );
				$chain_cap      = min( $max_chain, $slug_count );

				for ( $n = $min_chain; $n <= $chain_cap; $n++ ) {
					for ( $start = 0; $start + $n <= $slug_count; $start++ ) {
						$window = array_slice( $slugs, $start, $n );
						$key    = implode( ' > ', $window );
						$all_ok = self::window_all_success( $records, $start, $n );

						if ( ! isset( $chains[ $key ] ) ) {
							$chains[ $key ] = array(
								'steps'          => $window,
								'occurrences'    => 0,
								'successes'      => 0,
								'recency_weight' => 0.0,
								'assistant_ids'  => array(),
							);
						}

						++$chains[ $key ]['occurrences'];
						$chains[ $key ]['successes']      += $all_ok ? 1 : 0;
						$chains[ $key ]['recency_weight'] += $recency_weight;
						if ( ! in_array( $aid, $chains[ $key ]['assistant_ids'], true ) ) {
							$chains[ $key ]['assistant_ids'][] = $aid;
						}
					}
				}

				++$run_index;
			}
		}

		$suggestions = array();

		foreach ( $chains as $key => $chain ) {
			if ( $chain['occurrences'] < $min_occurrences ) {
				continue;
			}

			$success_rate = $chain['successes'] / $chain['occurrences'];
			$score        = $chain['occurrences'] * $chain['recency_weight'] * ( 0.5 + 0.5 * $success_rate );
			$confidence   = min( 0.95, 0.4 + 0.15 * $chain['occurrences'] );

			$suggestions[] = array(
				'chain'          => $key,
				'steps'          => $chain['steps'],
				'occurrences'    => $chain['occurrences'],
				'success_rate'   => round( $success_rate, 4 ),
				'score'          => round( $score, 4 ),
				'confidence'     => round( $confidence, 4 ),
				'assistant_ids'  => $chain['assistant_ids'],
				'suggested_name' => self::suggest_name( $chain['steps'] ),
			);
		}

		usort(
			$suggestions,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $b['occurrences'] <=> $a['occurrences'];
				}
				return $b['score'] <=> $a['score'];
			}
		);

		return array(
			'assistants_scanned' => count( $assistant_ids ),
			'runs_scanned'       => $runs_scanned,
			'suggestions'        => array_slice( $suggestions, 0, $max_results ),
		);
	}

	/**
	 * Discover assistant directories in the trace-store base directory.
	 *
	 * @return int[] Assistant IDs found on disk.
	 */
	private static function discover_assistant_ids() {
		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			return array();
		}

		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . WP_MCP_AI_Harness_Trace_Store::BASE_DIR;

		if ( ! is_dir( $base ) ) {
			return array();
		}

		$ids     = array();
		$entries = scandir( $base );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! is_dir( $base . '/' . $entry ) ) {
				continue;
			}
			$id = absint( $entry );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Extract ordered tool slugs from JSONL records.
	 *
	 * @param array $records Decoded `tool_calls.jsonl` records.
	 * @return string[] Tool slugs in execution order.
	 */
	public static function extract_slugs( array $records ) {
		$slugs = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['slug'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $record['slug'] );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Whether every record in a window reported success.
	 *
	 * @param array $records Decoded `tool_calls.jsonl` records.
	 * @param int   $start   Window start index (record-based).
	 * @param int   $length  Window length.
	 * @return bool
	 */
	public static function window_all_success( array $records, $start, $length ) {
		// Map records to slugs first: windows are computed over the slug
		// sequence, which skips malformed records.
		$window = array_slice( self::extract_slugs( $records ), $start, $length );

		$matched = 0;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['slug'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $record['slug'] );
			if ( isset( $window[ $matched ] ) && $slug === $window[ $matched ] ) {
				if ( empty( $record['result_success'] ) ) {
					return false;
				}
				++$matched;
				if ( $matched >= count( $window ) ) {
					return true;
				}
			}
		}

		// Window not fully present in the records — treat as failure.
		return false;
	}

	/**
	 * Derive a human-readable workflow name from a tool chain.
	 *
	 * @param string[] $steps Tool slugs.
	 * @return string
	 */
	public static function suggest_name( array $steps ) {
		$names = array();

		foreach ( $steps as $step ) {
			$names[] = ucwords( str_replace( array( '_', '-' ), ' ', (string) $step ) );
		}

		return implode( ' → ', $names );
	}
}
