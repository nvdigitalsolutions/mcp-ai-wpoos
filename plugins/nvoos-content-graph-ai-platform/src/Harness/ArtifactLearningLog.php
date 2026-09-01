<?php
/**
 * Artifact Learning Log — differential record of past mutations.
 *
 * Persists past mutations as (diff, score-delta) pairs — the differential
 * signal that tells future mutators *which specific change caused which
 * effect*. This is Imbue's learning log: entries come from the local
 * neighborhood of a lineage (ancestors or siblings), so a mutator sees what
 * was tried nearby and avoids re-trying regressions.
 *
 * Storage: option `wp_mcp_ai_artifact_learning_log`, capped FIFO at
 * MAX_ENTRIES. Entries reference population hashes (Phase C), so lineage
 * relationships are resolved through the artifact population.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Imbue (2026). "LLM-based Evolution as a Universal Optimizer."
 *   https://imbue.com/blog/2026-02-27-darwinian-evolver
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Learning Log class.
 *
 * @since 1.9.0
 */
class ArtifactLearningLog {

	/**
	 * Option key for the learning log.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const OPTION_KEY = 'wp_mcp_ai_artifact_learning_log';

	/**
	 * Maximum entries retained (FIFO).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Ancestor-walk strategy.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const STRATEGY_ANCESTORS = 'ancestors';

	/**
	 * Sibling strategy.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const STRATEGY_SIBLINGS = 'siblings';

	/**
	 * Record a mutation in the learning log.
	 *
	 * @since 1.9.0
	 *
	 * @param array $entry Entry fields: `artifact_type`, `artifact_id`,
	 *                     `parent_hash`, `child_hash`, `kind`, `diff`,
	 *                     `change_summary`, `score_delta`, `assistant_id`.
	 * @return string|WP_Error Entry ID on success, WP_Error for invalid input.
	 */
	public static function record( array $entry ) {
		$artifact_type = isset( $entry['artifact_type'] ) ? sanitize_key( (string) $entry['artifact_type'] ) : '';
		if ( '' === $artifact_type ) {
			return new \WP_Error(
				'wp_mcp_ai_learning_log_invalid_type',
				__( 'Learning-log entries require an artifact type.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$log = self::load();

		$id     = uniqid( 'll_', false );
		$now    = time();
		$record = array(
			'id'             => $id,
			'artifact_type'  => $artifact_type,
			'artifact_id'    => isset( $entry['artifact_id'] ) ? sanitize_key( (string) $entry['artifact_id'] ) : '',
			'parent_hash'    => isset( $entry['parent_hash'] ) ? sanitize_key( (string) $entry['parent_hash'] ) : '',
			'child_hash'     => isset( $entry['child_hash'] ) ? sanitize_key( (string) $entry['child_hash'] ) : '',
			'kind'           => isset( $entry['kind'] ) ? sanitize_key( (string) $entry['kind'] ) : '',
			'diff'           => self::scrub( isset( $entry['diff'] ) ? (string) $entry['diff'] : '' ),
			'change_summary' => sanitize_textarea_field( self::scrub( isset( $entry['change_summary'] ) ? (string) $entry['change_summary'] : '' ) ),
			'score_delta'    => isset( $entry['score_delta'] ) ? (float) $entry['score_delta'] : 0.0,
			'assistant_id'   => isset( $entry['assistant_id'] ) ? absint( $entry['assistant_id'] ) : 0,
			'created_at'     => $now,
		);

		$log[] = $record;

		/**
		 * Filters the maximum number of learning-log entries retained.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max Maximum entries. Default MAX_ENTRIES (200).
		 */
		$max = (int) apply_filters( 'wp_mcp_ai_artifact_learning_log_max', self::MAX_ENTRIES );
		$max = max( 1, $max );

		if ( count( $log ) > $max ) {
			$log = array_slice( $log, -1 * $max );
		}

		self::save( $log );

		return $id;
	}

	/**
	 * Get learning-log entries for a lineage neighborhood.
	 *
	 * `ancestors` walks the parent chain through the artifact population and
	 * returns mutations whose parent belongs to that chain (or is the hash
	 * itself). `siblings` returns mutations whose parent is a sibling of the
	 * given hash (children of the same parent).
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash     Population hash to anchor the neighborhood.
	 * @param int    $n        Maximum entries returned. Default 5.
	 * @param string $strategy `ancestors` (default) or `siblings`.
	 * @return array<int,array> Entries, most recent first.
	 */
	public static function get_for_neighborhood( $hash, $n = 5, $strategy = self::STRATEGY_ANCESTORS ) {
		$hash = sanitize_key( (string) $hash );
		$n    = max( 1, min( 50, (int) $n ) );

		$related = self::related_hashes( $hash, $strategy );
		if ( empty( $related ) ) {
			return array();
		}

		$matches = array();
		foreach ( self::load() as $entry ) {
			$parent = isset( $entry['parent_hash'] ) ? (string) $entry['parent_hash'] : '';
			if ( '' !== $parent && in_array( $parent, $related, true ) ) {
				$matches[] = $entry;
			}
		}

		// Newest first.
		usort(
			$matches,
			static function ( $a, $b ) {
				$a_time = isset( $a['created_at'] ) ? (int) $a['created_at'] : 0;
				$b_time = isset( $b['created_at'] ) ? (int) $b['created_at'] : 0;
				return $b_time - $a_time;
			}
		);

		return array_slice( $matches, 0, $n );
	}

	/**
	 * Get all log entries, optionally filtered.
	 *
	 * @since 1.9.0
	 *
	 * @param array $filters Optional filters: `artifact_type`, `artifact_id`,
	 *                       `parent_hash`, `child_hash`, `kind`, `assistant_id`.
	 * @return array<int,array> Entries (oldest first, insertion order).
	 */
	public static function get_entries( array $filters = array() ) {
		$log = self::load();

		$out = array();
		foreach ( $log as $entry ) {
			if ( ! empty( $filters['artifact_type'] ) && ( isset( $entry['artifact_type'] ) ? $entry['artifact_type'] : '' ) !== sanitize_key( (string) $filters['artifact_type'] ) ) {
				continue;
			}
			if ( ! empty( $filters['artifact_id'] ) && ( isset( $entry['artifact_id'] ) ? (string) $entry['artifact_id'] : '' ) !== sanitize_key( (string) $filters['artifact_id'] ) ) {
				continue;
			}
			if ( ! empty( $filters['parent_hash'] ) && ( isset( $entry['parent_hash'] ) ? (string) $entry['parent_hash'] : '' ) !== sanitize_key( (string) $filters['parent_hash'] ) ) {
				continue;
			}
			if ( ! empty( $filters['child_hash'] ) && ( isset( $entry['child_hash'] ) ? (string) $entry['child_hash'] : '' ) !== sanitize_key( (string) $filters['child_hash'] ) ) {
				continue;
			}
			if ( ! empty( $filters['kind'] ) && ( isset( $entry['kind'] ) ? $entry['kind'] : '' ) !== sanitize_key( (string) $filters['kind'] ) ) {
				continue;
			}
			if ( ! empty( $filters['assistant_id'] ) && (int) ( isset( $entry['assistant_id'] ) ? $entry['assistant_id'] : 0 ) !== absint( $filters['assistant_id'] ) ) {
				continue;
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Drop the whole log (tests / uninstall).
	 *
	 * @since 1.9.0
	 * @return bool True when the option was deleted.
	 */
	public static function clear() {
		return (bool) delete_option( self::OPTION_KEY );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the hashes that define a lineage neighborhood.
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash     Anchor hash.
	 * @param string $strategy Strategy.
	 * @return array<int,string> Related hashes.
	 */
	private static function related_hashes( $hash, $strategy ) {
		if ( self::STRATEGY_SIBLINGS === $strategy ) {
			if ( ! class_exists( __NAMESPACE__ . '\\ArtifactPopulation' ) ) {
				return array();
			}

			$entry  = ArtifactPopulation::get_entry( $hash );
			$parent = is_array( $entry ) && isset( $entry['parent'] ) ? (string) $entry['parent'] : '';

			// Siblings are the other children of the same parent.
			if ( '' === $parent ) {
				return array();
			}
			$parent_entry = ArtifactPopulation::get_entry( $parent );
			if ( ! is_array( $parent_entry ) || empty( $parent_entry['children'] ) || ! is_array( $parent_entry['children'] ) ) {
				return array();
			}

			return array_values(
				array_filter(
					$parent_entry['children'],
					static function ( $child ) use ( $hash ) {
						return (string) $child !== $hash;
					}
				)
			);
		}

		// Ancestors: the hash itself plus its parent chain (bounded depth).
		$related = array( $hash );

		if ( class_exists( __NAMESPACE__ . '\\ArtifactPopulation' ) ) {
			$current = $hash;
			for ( $depth = 0; $depth < 5; $depth++ ) {
				$entry = ArtifactPopulation::get_entry( $current );
				if ( ! is_array( $entry ) || empty( $entry['parent'] ) ) {
					break;
				}
				$parent    = (string) $entry['parent'];
				$related[] = $parent;
				$current   = $parent;
			}
		}

		return $related;
	}

	/**
	 * Load the learning-log option.
	 *
	 * @since 1.9.0
	 * @return array Entries.
	 */
	private static function load() {
		$log = get_option( self::OPTION_KEY, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Persist the learning-log option.
	 *
	 * @since 1.9.0
	 *
	 * @param array $log Entries.
	 * @return void
	 */
	private static function save( $log ) {
		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * PII-scrub free-form text (graceful when the filter is absent).
	 *
	 * @since 1.9.0
	 *
	 * @param string $text Raw text.
	 * @return string Scrubbed text.
	 */
	private static function scrub( $text ) {
		$text = (string) $text;

		if ( '' === $text || ! class_exists( __NAMESPACE__ . '\\PiiFilter' ) ) {
			return $text;
		}

		$scrubbed = PiiFilter::scrub( $text );
		if ( is_array( $scrubbed ) && isset( $scrubbed['text'] ) && is_string( $scrubbed['text'] ) ) {
			return $scrubbed['text'];
		}

		return $text;
	}
}
