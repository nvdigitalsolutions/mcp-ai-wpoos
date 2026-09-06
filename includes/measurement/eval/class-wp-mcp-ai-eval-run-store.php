<?php
/**
 * Eval Run Store
 *
 * Persists the *summary* of each eval-suite run so the CLI alert-check
 * subcommand and the dashboard can compare across runs without
 * re-executing the suite. Storage is keyed per-suite so reading the
 * baseline for one suite does not deserialize unrelated histories.
 *
 * Storage backend: WordPress options table (`wp_mcp_ai_eval_runs__<slug>`).
 * Each option holds a JSON-encoded array of summary records ordered
 * oldest → newest. Records are capped at `MAX_RUNS` per suite; the
 * cap keeps option-row size bounded (~30 KB worst-case) and matches
 * the cardinality the regression detector actually reads.
 *
 * Why options vs. a custom table: the persistent metric event store
 * (PR 9) already owns the high-cardinality time-series schema. Run
 * summaries are coarse, infrequent, per-suite — using options keeps
 * uninstall hygiene trivial (`delete_option` per suite) and avoids
 * a second `dbDelta` migration just to hold ~100 rows per suite.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-suite eval run history.
 */
class WP_MCP_AI_Eval_Run_Store {

	/**
	 * Maximum number of runs retained per suite. Older entries are
	 * dropped FIFO. Filterable via `wp_mcp_ai_eval_run_store_max_runs`.
	 */
	const MAX_RUNS = 100;

	/**
	 * Option-name prefix. Suite slug is appended after sanitization.
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_eval_runs__';

	/**
	 * Option key for the bounded artifact→suite-slug index.
	 *
	 * Maps `{artifact_type}:{artifact_id}` keys to arrays of suite slugs
	 * that recorded runs for that artifact, so per-artifact regression
	 * lookups do not have to scan the whole options table.
	 *
	 * @since 1.9.0
	 */
	const OPTION_ARTIFACT_INDEX = 'wp_mcp_ai_eval_runs_artifact_index';

	/**
	 * Maximum number of artifact-index entries retained (FIFO).
	 *
	 * @since 1.9.0
	 */
	const MAX_ARTIFACT_INDEX = 500;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (tests only).
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Build the option name for a suite slug.
	 *
	 * @param string $slug Suite slug.
	 * @return string
	 */
	public static function option_name( $slug ) {
		return self::OPTION_PREFIX . sanitize_key( (string) $slug );
	}

	/**
	 * Resolve the active retention cap.
	 *
	 * @return int
	 */
	private function max_runs() {
		/**
		 * Filter the maximum number of run summaries retained per suite.
		 *
		 * @since 1.3.0
		 *
		 * @param int $max Maximum runs to retain (default `MAX_RUNS`).
		 */
		$max = (int) apply_filters( 'wp_mcp_ai_eval_run_store_max_runs', self::MAX_RUNS );
		return max( 1, $max );
	}

	/**
	 * Append a run summary for `$slug`. Trims old records to honour
	 * the retention cap. Idempotent within a request.
	 *
	 * @param string              $slug    Suite slug.
	 * @param array<string,mixed> $summary Summary in the shape produced by
	 *                                    `WP_MCP_AI_Eval_Runner::run()['summary']`.
	 * @param int|null            $started_at Optional UTC timestamp. Defaults to `time()`.
	 * @param array               $artifact Optional artifact scoping: may carry
	 *                                    `artifact_type` and `artifact_id` keys.
	 * @return array<string,mixed>                                  The recorded run envelope (slug, started_at, summary).
	 */
	public function record( $slug, array $summary, $started_at = null, array $artifact = array() ) {
		$slug       = sanitize_key( (string) $slug );
		$started_at = null === $started_at ? time() : (int) $started_at;
		$record     = array(
			'slug'       => $slug,
			'started_at' => $started_at,
			'summary'    => $summary,
		);

		$artifact_type = isset( $artifact['artifact_type'] ) ? sanitize_key( (string) $artifact['artifact_type'] ) : '';
		$artifact_id   = isset( $artifact['artifact_id'] ) ? sanitize_key( (string) $artifact['artifact_id'] ) : '';
		if ( '' !== $artifact_type ) {
			$record['artifact_type'] = $artifact_type;
			$record['artifact_id']   = $artifact_id;
			$this->update_artifact_index( $artifact_type, $artifact_id, $slug );
		}

		$runs   = $this->get_all( $slug );
		$runs[] = $record;
		$max    = $this->max_runs();
		if ( count( $runs ) > $max ) {
			$runs = array_slice( $runs, -1 * $max );
		}

		update_option( self::option_name( $slug ), wp_json_encode( $runs ), false );
		return $record;
	}

	/**
	 * Maintain the bounded artifact→suite-slug index.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param string $artifact_id   Artifact identifier.
	 * @param string $slug          Suite slug that recorded a run.
	 * @return void
	 */
	private function update_artifact_index( $artifact_type, $artifact_id, $slug ) {
		$index = get_option( self::OPTION_ARTIFACT_INDEX, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$key = $artifact_type . ':' . $artifact_id;
		if ( ! isset( $index[ $key ] ) || ! is_array( $index[ $key ] ) ) {
			$index[ $key ] = array();
		}
		if ( ! in_array( $slug, $index[ $key ], true ) ) {
			$index[ $key ][] = $slug;
		}

		// FIFO cap on distinct artifact keys.
		if ( count( $index ) > self::MAX_ARTIFACT_INDEX ) {
			$index = array_slice( $index, -1 * self::MAX_ARTIFACT_INDEX, null, true );
		}

		update_option( self::OPTION_ARTIFACT_INDEX, $index, false );
	}

	/**
	 * Get run histories for every suite that recorded runs for an artifact,
	 * merged newest-first.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param string $artifact_id   Optional artifact identifier (empty = any of the type).
	 * @return array<int,array<string,mixed>>
	 */
	public function get_runs_for_artifact( $artifact_type, $artifact_id = '' ) {
		$artifact_type = sanitize_key( (string) $artifact_type );
		$artifact_id   = sanitize_key( (string) $artifact_id );
		if ( '' === $artifact_type ) {
			return array();
		}

		$index = get_option( self::OPTION_ARTIFACT_INDEX, array() );
		if ( ! is_array( $index ) ) {
			return array();
		}

		$all = array();
		foreach ( $index as $key => $slugs ) {
			if ( ! is_array( $slugs ) ) {
				continue;
			}
			$parts = explode( ':', (string) $key, 2 );
			if ( 2 !== count( $parts ) || $parts[0] !== $artifact_type ) {
				continue;
			}
			if ( '' !== $artifact_id && '' !== $parts[1] && $parts[1] !== $artifact_id ) {
				continue;
			}
			foreach ( $slugs as $slug ) {
				$runs = $this->get_all( (string) $slug );
				foreach ( $runs as $run ) {
					$all[] = $run;
				}
			}
		}

		// Newest first.
		usort(
			$all,
			static function ( $a, $b ) {
				$a_time = isset( $a['started_at'] ) ? (int) $a['started_at'] : 0;
				$b_time = isset( $b['started_at'] ) ? (int) $b['started_at'] : 0;
				return $b_time - $a_time;
			}
		);

		return $all;
	}

	/**
	 * Get all stored runs for `$slug`, oldest → newest.
	 *
	 * @param string $slug Suite slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_all( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$raw  = get_option( self::option_name( $slug ), '' );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		// Defensive: drop any non-array entries (corrupted history).
		$out = array();
		foreach ( $decoded as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Get the most-recent `$n` runs for `$slug`, **most-recent-first**.
	 *
	 * The detector wants newest-first because it pairs the most-recent
	 * sample (the "current" run) with the trailing window. Returning
	 * the reversed slice avoids every caller doing it themselves.
	 *
	 * @param string $slug Suite slug.
	 * @param int    $n    Window size.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recent( $slug, $n ) {
		$n   = max( 0, (int) $n );
		$all = $this->get_all( $slug );
		if ( 0 === $n || empty( $all ) ) {
			return array();
		}
		$slice = array_slice( $all, -1 * $n );
		return array_reverse( $slice );
	}

	/**
	 * Drop all run history for `$slug`. Used by uninstall and tests.
	 *
	 * @param string $slug Suite slug.
	 * @return bool
	 */
	public function delete( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return (bool) delete_option( self::option_name( $slug ) );
	}
}
