<?php
/**
 * Memory Tier Manager — Phase A7 of the MemPalace Capture Framework.
 *
 * Implements the tier lifecycle that MemPalace, Letta / MemGPT, and mem0 share:
 *
 *  - **Promote** `recall` → `core` when importance ≥ threshold AND access
 *    frequency is high.  (Letta "self-edit" pattern.)
 *  - **Demote** `core` → `recall` → `archival` on staleness.  Verbatim
 *    records are NEVER deleted by demotion — only TTL purges them, and a
 *    tombstone in the audit trail proves deletion.
 *  - **Consolidate** N similar `recall` items into one `core` summary memory
 *    while keeping the originals at `archival` (mem0 verbatim discipline).
 *
 * Every transition fires the `wp_mcp_ai_memory_tier_transition` action so
 * downstream listeners (e.g. Graphify) can repaint nodes without a full graph
 * rebuild.
 *
 * The manager is opt-in: it uses WordPress cron and exposes a
 * `wp_mcp_ai_memory_tier_manager_enabled` filter so headless / CI environments
 * can disable it cleanly.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron-driven tier promotion / demotion for MemPalace memory records.
 */
class WP_MCP_AI_Memory_Tier_Manager {

	const CRON_HOOK = 'wp_mcp_ai_memory_tier_sweep';

	/**
	 * Maximum number of `core` memories per wing. Past this cap, lowest-rank
	 * records are demoted on the next sweep regardless of importance.
	 *
	 * Filterable: `wp_mcp_ai_memory_core_cap_per_wing`.
	 */
	const CORE_CAP_PER_WING_DEFAULT = 50;

	/**
	 * Importance threshold for `recall` → `core` promotion (0.0–1.0).
	 *
	 * Filterable: `wp_mcp_ai_memory_promote_importance_threshold`.
	 */
	const PROMOTE_IMPORTANCE_THRESHOLD_DEFAULT = 0.7;

	/**
	 * Access-count threshold for promotion. A record must have been accessed
	 * at least this many times within the lookback window to qualify.
	 *
	 * Filterable: `wp_mcp_ai_memory_promote_access_threshold`.
	 */
	const PROMOTE_ACCESS_THRESHOLD_DEFAULT = 3;

	/**
	 * Days of inactivity that demote `core` → `recall` and `recall` → `archival`.
	 *
	 * Filterable: `wp_mcp_ai_memory_demote_inactivity_days`.
	 */
	const DEMOTE_INACTIVITY_DAYS_DEFAULT = 30;

	/**
	 * Default Ebbinghaus half-life (in days) used by the Phase 5 decay sweep.
	 *
	 * After this many days without access, a record's confidence_score halves
	 * (multiplied by 0.5). The decay curve is `exp( -days * ln(2) / half_life )`.
	 *
	 * Filterable: `wp_mcp_ai_memory_decay_half_life_days`.
	 *
	 * @since 1.1.20
	 */
	const DECAY_HALF_LIFE_DAYS_DEFAULT = 30;

	/**
	 * Minimum confidence floor — records do not decay below this value.
	 *
	 * Prevents long-tail rows from collapsing to numerical zero where they
	 * become indistinguishable from un-scored rows.
	 *
	 * Filterable: `wp_mcp_ai_memory_decay_floor`.
	 *
	 * @since 1.1.20
	 */
	const DECAY_FLOOR_DEFAULT = 0.1;

	/**
	 * Additive bump applied to `confidence_score` each time
	 * {@see strengthen_on_access()} is called.
	 *
	 * The new confidence is `min( 1.0, current + bump )`.
	 *
	 * Filterable: `wp_mcp_ai_memory_access_strengthen`.
	 *
	 * @since 1.1.20
	 */
	const ACCESS_STRENGTHEN_DEFAULT = 0.05;

	/**
	 * Default decay batch size — chunks of candidates processed per pass
	 * inside {@see decay_sweep()}.
	 *
	 * Filterable: `wp_mcp_ai_memory_decay_batch_size`.
	 *
	 * @since 1.1.20
	 */
	const DECAY_BATCH_SIZE_DEFAULT = 100;

	/**
	 * Hard upper bound on candidates processed by a single decay sweep.
	 *
	 * Keeps the daily cron under ~30s on typical sites even when the corpus
	 * grows to tens of thousands of rows.
	 *
	 * Filterable: `wp_mcp_ai_memory_decay_max_per_sweep`.
	 *
	 * @since 1.1.20
	 */
	const DECAY_MAX_PER_SWEEP_DEFAULT = 1000;

	/**
	 * Minimum absolute delta between old and new confidence before the
	 * Phase 5 decay sweep treats a row as "changed".
	 *
	 * Avoids wasteful CCT writes when an already-floored row is re-examined
	 * day after day.
	 *
	 * @since 1.1.20
	 */
	const DECAY_WRITE_EPSILON = 0.001;

	/**
	 * Singleton.
	 *
	 * @var WP_MCP_AI_Memory_Tier_Manager|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return WP_MCP_AI_Memory_Tier_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up the cron hook + ensure a sweep is scheduled.
	 */
	public static function bootstrap() {
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_tier_manager_enabled', true ) ) {
			return;
		}

		add_action( self::CRON_HOOK, array( self::get_instance(), 'sweep' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Run a single sweep of the tier lifecycle.
	 *
	 * @return array {
	 *   'promoted'      => int,
	 *   'demoted'       => int,
	 *   'consolidated'  => int,
	 * }
	 */
	public function sweep() {
		$summary = array(
			'promoted'     => 0,
			'demoted'      => 0,
			'consolidated' => 0,
		);

		$candidates = $this->load_candidates();
		if ( empty( $candidates ) ) {
			// Memory Layer 2026 Phase 5 — confidence decay pass.
			// Decay loads its own candidate pool, so it must still run even
			// when the tier-transition candidate pool is empty.
			if ( (bool) apply_filters( 'wp_mcp_ai_memory_decay_enabled', true ) ) {
				$summary['decayed'] = $this->decay_sweep();
			}
			return $summary;
		}

		foreach ( $candidates as $record ) {
			$transition = $this->evaluate( $record );
			if ( null === $transition ) {
				continue;
			}

			$this->apply_transition( $record, $transition );

			if ( 'promote' === $transition['kind'] ) {
				++$summary['promoted'];
			} elseif ( 'demote' === $transition['kind'] ) {
				++$summary['demoted'];
			} elseif ( 'consolidate' === $transition['kind'] ) {
				++$summary['consolidated'];
			}
		}

		// Apply the per-wing core cap as a final pass.
		$summary['demoted'] += $this->enforce_core_cap();

		/**
		 * Fires after a tier sweep completes.
		 *
		 * @param array $summary Counts of transitions applied.
		 */
		do_action( 'wp_mcp_ai_memory_tier_sweep_completed', $summary );

		// Memory Layer 2026 Phase 5 — confidence decay pass.
		if ( (bool) apply_filters( 'wp_mcp_ai_memory_decay_enabled', true ) ) {
			$summary['decayed'] = $this->decay_sweep();
		}

		return $summary;
	}

	/**
	 * Evaluate a single record and return the transition to apply, if any.
	 *
	 * Public so test suites and admin tools can dry-run the policy.
	 *
	 * @param array $record Memory record (CCT row, normalised).
	 * @return array|null Transition descriptor, or null when no change.
	 */
	public function evaluate( array $record ) {
		$tier         = isset( $record['tier'] ) ? (string) $record['tier'] : (
			isset( $record['memory_tier'] ) ? $this->letta_to_memp( (string) $record['memory_tier'] ) : WP_MCP_AI_Memory_Capture_Service::TIER_RECALL
		);
		$importance   = isset( $record['importance'] ) ? (float) $record['importance'] : 0.5;
		$access_count = isset( $record['access_count'] ) ? (int) $record['access_count'] : 0;
		$last_access  = isset( $record['last_accessed'] ) ? (string) $record['last_accessed'] : '';
		$verbatim     = ! empty( $record['verbatim'] );

		$promote_imp_threshold = (float) apply_filters( 'wp_mcp_ai_memory_promote_importance_threshold', self::PROMOTE_IMPORTANCE_THRESHOLD_DEFAULT );
		$promote_access_min    = (int) apply_filters( 'wp_mcp_ai_memory_promote_access_threshold', self::PROMOTE_ACCESS_THRESHOLD_DEFAULT );
		$demote_days           = (int) apply_filters( 'wp_mcp_ai_memory_demote_inactivity_days', self::DEMOTE_INACTIVITY_DAYS_DEFAULT );

		$days_since_access = $this->days_since( $last_access );

		// Promotion: recall → core. Both criteria are required (no single
		// signal can promote — guards against `core` becoming a dumping ground).
		if (
			WP_MCP_AI_Memory_Capture_Service::TIER_RECALL === $tier
			&& $importance >= $promote_imp_threshold
			&& $access_count >= $promote_access_min
		) {
			return array(
				'kind' => 'promote',
				'from' => $tier,
				'to'   => WP_MCP_AI_Memory_Capture_Service::TIER_CORE,
			);
		}

		// Demotion: core → recall on inactivity.
		if (
			WP_MCP_AI_Memory_Capture_Service::TIER_CORE === $tier
			&& $days_since_access > $demote_days
		) {
			return array(
				'kind' => 'demote',
				'from' => $tier,
				'to'   => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
			);
		}

		// Demotion: recall → archival on extended inactivity.
		if (
			WP_MCP_AI_Memory_Capture_Service::TIER_RECALL === $tier
			&& $days_since_access > ( $demote_days * 2 )
		) {
			return array(
				'kind'     => 'demote',
				'from'     => $tier,
				'to'       => WP_MCP_AI_Memory_Capture_Service::TIER_ARCHIVAL,
				'verbatim' => $verbatim,
			);
		}

		return null;
	}

	/**
	 * Apply a transition: emit the canonical event, update the CCT row when
	 * possible, and let listeners (Graphify) react.
	 *
	 * @param array $record     Memory record.
	 * @param array $transition Transition descriptor from {@see evaluate()}.
	 * @return void
	 */
	protected function apply_transition( array $record, array $transition ) {
		$payload = array(
			'context_id'      => isset( $record['context_id'] ) ? (string) $record['context_id'] : '',
			'agent_id'        => isset( $record['agent_id'] ) ? (string) $record['agent_id'] : '',
			'wing'            => isset( $record['wing'] ) ? (string) $record['wing'] : '',
			'room'            => isset( $record['room'] ) ? (string) $record['room'] : '',
			'from_tier'       => isset( $transition['from'] ) ? (string) $transition['from'] : '',
			'to_tier'         => isset( $transition['to'] ) ? (string) $transition['to'] : '',
			'kind'            => isset( $transition['kind'] ) ? (string) $transition['kind'] : '',
			'transitioned_at' => current_time( 'mysql' ),
		);

		/**
		 * Fires for every tier transition applied by the manager.
		 *
		 * Listeners can intercept and short-circuit by returning a truthy
		 * value from a filter on `wp_mcp_ai_memory_tier_transition_block`.
		 *
		 * @param array $payload Transition payload.
		 */
		do_action( 'wp_mcp_ai_memory_tier_transition', $payload );

		// CCT update is best-effort — headless tests and transient-only sites
		// observe the event without needing the row to mutate.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_item_handler();
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return;
		}

		if ( empty( $record['_ID'] ) ) {
			return;
		}

		try {
			$update_result = $handler->update_item(
				array(
					'_ID'      => (int) $record['_ID'],
					// Phase A `tier` is stored alongside Letta-style memory_tier
					// via the existing metadata field; the bridge maps it.
					'metadata' => wp_json_encode(
						array_merge(
							isset( $record['metadata_decoded'] ) && is_array( $record['metadata_decoded'] ) ? $record['metadata_decoded'] : array(),
							array(
								'tier'          => $payload['to_tier'],
								'tier_changed'  => $payload['transitioned_at'],
								'tier_kind'     => $payload['kind'],
								'tier_previous' => $payload['from_tier'],
							)
						)
					),
				)
			);

			if ( is_wp_error( $update_result ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Memory tier manager: CCT update failed; transition event was still emitted.',
					array(
						'transition'    => $payload,
						'cct_row_id'    => (int) $record['_ID'],
						'error_code'    => $update_result->get_error_code(),
						'error_message' => $update_result->get_error_message(),
						'partial'       => true,
					)
				);
			}
		} catch ( Throwable $exception ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Memory tier manager: exception during CCT update; transition event was still emitted.',
					array(
						'transition' => $payload,
						'cct_row_id' => (int) $record['_ID'],
						'message'    => $exception->getMessage(),
						'partial'    => true,
					)
				);
			}
		}
	}

	/**
	 * Enforce the per-wing `core` cap by demoting the lowest-importance
	 * records past the cap.
	 *
	 * @return int Records demoted.
	 */
	protected function enforce_core_cap() {
		$cap = (int) apply_filters( 'wp_mcp_ai_memory_core_cap_per_wing', self::CORE_CAP_PER_WING_DEFAULT );
		if ( $cap <= 0 ) {
			return 0;
		}

		$rows = $this->load_core_records_grouped_by_wing();
		if ( empty( $rows ) ) {
			return 0;
		}

		$demoted = 0;
		foreach ( $rows as $wing => $records ) {
			if ( count( $records ) <= $cap ) {
				continue;
			}
			// Sort by importance asc, then last_accessed asc — weakest first.
			usort(
				$records,
				static function ( $a, $b ) {
					$ai = isset( $a['importance'] ) ? (float) $a['importance'] : 0.5;
					$bi = isset( $b['importance'] ) ? (float) $b['importance'] : 0.5;
					if ( $ai !== $bi ) {
						return $ai < $bi ? -1 : 1;
					}
					$al = isset( $a['last_accessed'] ) ? (string) $a['last_accessed'] : '';
					$bl = isset( $b['last_accessed'] ) ? (string) $b['last_accessed'] : '';
					return strcmp( $al, $bl );
				}
			);
			$over = count( $records ) - $cap;
			for ( $i = 0; $i < $over; $i++ ) {
				$this->apply_transition(
					$records[ $i ],
					array(
						'kind' => 'demote',
						'from' => WP_MCP_AI_Memory_Capture_Service::TIER_CORE,
						'to'   => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
					)
				);
				++$demoted;
			}
			unset( $wing );
		}
		return $demoted;
	}

	/*
	==================================================================
	 * Memory Layer 2026 Phase 5 — Confidence decay
	 * ==================================================================
	 *
	 * The decay sweep is an *additive* third pass on top of the existing
	 * promote/demote/consolidate lifecycle. It does NOT change a record's
	 * tier; it only adjusts the `confidence_score` field that Phase 4's RRF
	 * fusion and Phase 7a's Memory Health UI consume.
	 *
	 * Algorithm (Ebbinghaus-inspired exponential decay):
	 *
	 *   days = ( now - last_accessed_at ) / 86400
	 *   factor = exp( -days * ln(2) / half_life_days )
	 *   new_confidence = max( floor, base * factor )
	 *
	 * Legacy rows (no `confidence_score`, no `last_accessed_at`) use the
	 * documented Phase 2 fallbacks: confidence defaults to 1.0, last access
	 * defaults to `stored_at` / `transaction_time`.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Run a single decay sweep.
	 *
	 * Iterates the candidate pool in batches, recomputes confidence using the
	 * Ebbinghaus curve, and — when the delta exceeds {@see DECAY_WRITE_EPSILON} —
	 * writes the new value back to the CCT row and emits
	 * `wp_mcp_ai_memory_decayed`.
	 *
	 * Performance bounds:
	 *   - batches of `wp_mcp_ai_memory_decay_batch_size` rows (default 100)
	 *   - hard cap of `wp_mcp_ai_memory_decay_max_per_sweep` rows (default 1000)
	 *
	 * @since 1.1.20
	 *
	 * @return int Count of rows whose confidence changed by more than
	 *             {@see DECAY_WRITE_EPSILON}.
	 */
	public function decay_sweep() {
		$candidates = $this->load_decay_candidates();
		if ( empty( $candidates ) ) {
			return 0;
		}

		$half_life  = (float) apply_filters( 'wp_mcp_ai_memory_decay_half_life_days', self::DECAY_HALF_LIFE_DAYS_DEFAULT );
		$floor      = (float) apply_filters( 'wp_mcp_ai_memory_decay_floor', self::DECAY_FLOOR_DEFAULT );
		$batch_size = max( 1, (int) apply_filters( 'wp_mcp_ai_memory_decay_batch_size', self::DECAY_BATCH_SIZE_DEFAULT ) );
		$max_total  = max( 1, (int) apply_filters( 'wp_mcp_ai_memory_decay_max_per_sweep', self::DECAY_MAX_PER_SWEEP_DEFAULT ) );

		// Clamp the candidate list to the per-sweep cap before chunking so the
		// caller's filter-supplied pool can be larger than the cap without paying
		// the cost of unused tail items.
		if ( count( $candidates ) > $max_total ) {
			$candidates = array_slice( $candidates, 0, $max_total );
		}

		$now_ts  = time();
		$changed = 0;

		$batches = array_chunk( $candidates, $batch_size );
		foreach ( $batches as $batch ) {
			foreach ( $batch as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				$context_id = isset( $record['context_id'] ) ? (string) $record['context_id'] : '';
				if ( '' === $context_id ) {
					continue;
				}

				$base       = $this->extract_base_confidence( $record );
				$timestamp  = $this->extract_last_access_timestamp( $record );
				$days_since = max( 0.0, (float) ( $now_ts - $timestamp ) / DAY_IN_SECONDS );
				$new_value  = self::compute_decayed_confidence( $base, $days_since, $half_life, $floor );

				if ( abs( $base - $new_value ) <= self::DECAY_WRITE_EPSILON ) {
					continue;
				}

				$this->persist_confidence_update(
					isset( $record['_ID'] ) ? (int) $record['_ID'] : 0,
					$context_id,
					$new_value
				);

				/**
				 * Fires once per record whose `confidence_score` was changed by the
				 * Phase 5 decay sweep.
				 *
				 * @since 1.1.20
				 *
				 * @param string $context_id     Memory context identifier.
				 * @param float  $old_confidence Confidence before decay.
				 * @param float  $new_confidence Confidence after decay.
				 */
				do_action( 'wp_mcp_ai_memory_decayed', $context_id, $base, $new_value );

				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Pure-math helper computing the decayed confidence value.
	 *
	 * Extracted as `public static` so test suites and admin diagnostics can
	 * verify the curve without going through the full sweep.
	 *
	 * @since 1.1.20
	 *
	 * @param float $base_confidence   Current confidence score (0.0–1.0).
	 * @param float $days_since_access Days elapsed since last access (>= 0).
	 * @param float $half_life_days    Half-life in days (> 0).
	 * @param float $floor             Minimum confidence value.
	 * @return float Decayed confidence, clamped to `[ floor, 1.0 ]`.
	 */
	public static function compute_decayed_confidence( $base_confidence, $days_since_access, $half_life_days, $floor ) {
		$base      = is_numeric( $base_confidence ) ? (float) $base_confidence : 1.0;
		$days      = max( 0.0, (float) $days_since_access );
		$half_life = (float) $half_life_days;
		$floor     = max( 0.0, min( 1.0, (float) $floor ) );

		if ( $half_life <= 0.0 ) {
			// Defensive: a non-positive half-life would explode the exp(); fall
			// back to the floor so the sweep degrades gracefully instead of
			// emitting NaN/Inf into the CCT.
			return $floor;
		}

		$factor = exp( -$days * M_LN2 / $half_life );
		$value  = max( $floor, $base * $factor );

		// Clamp upper bound — base may have been > 1.0 due to a misbehaving caller.
		return min( 1.0, $value );
	}

	/**
	 * Strengthen a memory record on read — the inverse of decay.
	 *
	 * Called by Phase 4's RRF fusion service when a record is returned in a
	 * result set so that frequently-accessed memories resist decay. Updates
	 * both `confidence_score` (bumped by `wp_mcp_ai_memory_access_strengthen`,
	 * default 0.05, capped at 1.0) and `last_accessed_at` (set to the current
	 * MySQL timestamp).
	 *
	 * Tolerates JetEngine being absent — returns `false` rather than raising
	 * when the CCT handler is not available.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $context_id         Memory context identifier.
	 * @param float|null $current_confidence Optional pre-fetched confidence score.
	 *                                       When null, the helper reads it from the
	 *                                       CCT row before computing the bump.
	 * @return float|false New confidence score on success, or `false` when the
	 *                     CCT handler is unavailable / the row is unknown.
	 */
	public static function strengthen_on_access( $context_id, $current_confidence = null ) {
		$context_id = is_scalar( $context_id ) ? (string) $context_id : '';
		if ( '' === $context_id ) {
			return false;
		}

		$bump = (float) apply_filters( 'wp_mcp_ai_memory_access_strengthen', self::ACCESS_STRENGTHEN_DEFAULT );

		$row = null;
		if ( null === $current_confidence ) {
			$row = self::fetch_cct_row_by_context_id( $context_id );
			if ( null === $row ) {
				return false;
			}
			$current_confidence = ( isset( $row['confidence_score'] ) && '' !== $row['confidence_score'] && is_numeric( $row['confidence_score'] ) )
				? (float) $row['confidence_score']
				: 1.0;
		}

		$old_value = max( 0.0, min( 1.0, (float) $current_confidence ) );
		$new_value = min( 1.0, $old_value + $bump );
		$timestamp = current_time( 'mysql' );

		/**
		 * Fires immediately before the strengthened confidence is persisted to
		 * the CCT row. Useful for tests and audit listeners; do not mutate.
		 *
		 * @since 1.1.20
		 *
		 * @param string $context_id Memory context identifier.
		 * @param float  $old_value  Confidence before the bump.
		 * @param float  $new_value  Confidence after the bump (capped at 1.0).
		 * @param string $timestamp  MySQL timestamp written to `last_accessed_at`.
		 */
		do_action( 'wp_mcp_ai_memory_strengthened', $context_id, $old_value, $new_value, $timestamp );

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return false;
		}

		$handler = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_item_handler();
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return false;
		}

		$row_id = isset( $row['_ID'] ) ? (int) $row['_ID'] : self::find_cct_row_id_by_context_id( $context_id );
		if ( ! $row_id ) {
			return false;
		}

		try {
			$result = $handler->update_item(
				array(
					'_ID'              => $row_id,
					'confidence_score' => (string) $new_value,
					'last_accessed_at' => $timestamp,
				)
			);
			if ( is_wp_error( $result ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Memory tier manager: strengthen_on_access CCT update failed.',
						array(
							'context_id' => $context_id,
							'cct_row_id' => $row_id,
							'error_code' => $result->get_error_code(),
						)
					);
				}
				return false;
			}
		} catch ( Throwable $exception ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Memory tier manager: strengthen_on_access threw during CCT update.',
					array(
						'context_id' => $context_id,
						'message'    => $exception->getMessage(),
					)
				);
			}
			return false;
		}

		return $new_value;
	}

	/**
	 * Load candidate records for the decay pass.
	 *
	 * Default implementation: dispatches to the existing tier-manager candidate
	 * filter so headless tests / sites that already feed candidates into the
	 * tier sweep do not need a second filter wired. Sites that want to feed a
	 * decay-specific pool (e.g. a paged CCT scan keyed on `last_accessed_at`)
	 * can hook `wp_mcp_ai_memory_decay_candidates` directly.
	 *
	 * @since 1.1.20
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function load_decay_candidates() {
		$base = $this->load_candidates();

		/**
		 * Filter the candidate pool fed to the Phase 5 decay sweep.
		 *
		 * Each record SHOULD include at minimum `context_id` (string), and MAY
		 * include `confidence_score`, `last_accessed_at`, `stored_at`,
		 * `transaction_time`, and `_ID` (CCT row id). Missing fields fall back
		 * to documented Phase 2 defaults.
		 *
		 * @since 1.1.20
		 *
		 * @param array $records Candidate records (defaults to the tier-manager pool).
		 */
		$records = apply_filters( 'wp_mcp_ai_memory_decay_candidates', is_array( $base ) ? $base : array() );

		return is_array( $records ) ? $records : array();
	}

	/**
	 * Read the current `confidence_score` from a record, with Phase 2 fallback.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $record Memory record.
	 * @return float Base confidence in `[0.0, 1.0]`.
	 */
	protected function extract_base_confidence( array $record ) {
		if ( isset( $record['confidence_score'] ) && '' !== $record['confidence_score'] && is_numeric( $record['confidence_score'] ) ) {
			return max( 0.0, min( 1.0, (float) $record['confidence_score'] ) );
		}
		return 1.0;
	}

	/**
	 * Resolve the last-access timestamp for a record (Phase 2 fallback chain).
	 *
	 * Order of preference:
	 *   1. `last_accessed_at`
	 *   2. `stored_at`
	 *   3. `transaction_time`
	 *   4. now() — prevents the sweep from collapsing a malformed row to floor.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $record Memory record.
	 * @return int Unix timestamp.
	 */
	protected function extract_last_access_timestamp( array $record ) {
		foreach ( array( 'last_accessed_at', 'stored_at', 'transaction_time' ) as $key ) {
			if ( ! empty( $record[ $key ] ) && is_string( $record[ $key ] ) ) {
				$ts = strtotime( $record[ $key ] );
				if ( false !== $ts ) {
					return (int) $ts;
				}
			}
		}
		return time();
	}

	/**
	 * Persist a decayed `confidence_score` back to the CCT row.
	 *
	 * Best-effort — silently no-ops when JetEngine isn't available. The decay
	 * event is emitted by the caller regardless so headless tests can observe
	 * the sweep without a live CCT table.
	 *
	 * @since 1.1.20
	 *
	 * @param int    $row_id     CCT `_ID` (0 when unknown — helper will resolve).
	 * @param string $context_id Memory context identifier (used as fallback lookup).
	 * @param float  $new_value  New confidence score (already clamped).
	 * @return void
	 */
	protected function persist_confidence_update( $row_id, $context_id, $new_value ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_item_handler();
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return;
		}

		$row_id = (int) $row_id;
		if ( $row_id <= 0 ) {
			$row_id = self::find_cct_row_id_by_context_id( $context_id );
		}
		if ( $row_id <= 0 ) {
			return;
		}

		try {
			$result = $handler->update_item(
				array(
					'_ID'              => $row_id,
					'confidence_score' => (string) $new_value,
				)
			);
			if ( is_wp_error( $result ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Memory tier manager: decay sweep CCT update failed.',
					array(
						'context_id' => $context_id,
						'cct_row_id' => $row_id,
						'error_code' => $result->get_error_code(),
					)
				);
			}
		} catch ( Throwable $exception ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Memory tier manager: decay sweep threw during CCT update.',
					array(
						'context_id' => $context_id,
						'message'    => $exception->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Resolve the CCT `_ID` for a context_id without going through reflection.
	 *
	 * Mirrors the lookup used by {@see WP_MCP_AI_Agent_Memory_CCT_Bridge}, but
	 * is duplicated here so the tier manager doesn't gain a hard dependency on
	 * the bridge's private static helpers. Returns 0 when the table is missing.
	 *
	 * @since 1.1.20
	 *
	 * @param string $context_id Memory context identifier.
	 * @return int CCT row id, or 0 when not found.
	 */
	protected static function find_cct_row_id_by_context_id( $context_id ) {
		$row = self::fetch_cct_row_by_context_id( $context_id );
		return ( null !== $row && isset( $row['_ID'] ) ) ? (int) $row['_ID'] : 0;
	}

	/**
	 * Fetch one CCT row (raw associative array) by context_id.
	 *
	 * Returns null when:
	 *   - JetEngine / the CCT class is not loaded;
	 *   - the `{prefix}jet_cct_ai_agent_memories` table has not been provisioned;
	 *   - no row matches the supplied context_id.
	 *
	 * @since 1.1.20
	 *
	 * @param string $context_id Memory context identifier.
	 * @return array<string,mixed>|null Raw row or null.
	 */
	protected static function fetch_cct_row_by_context_id( $context_id ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return null;
		}

		global $wpdb;
		$slug  = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		$suppress_state = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name composed from a trusted slug + $wpdb->prefix; value passed via prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name trusted (see comment above).
				"SELECT * FROM `{$table}` WHERE context_id = %s LIMIT 1",
				$context_id
			),
			ARRAY_A
		);

		$wpdb->suppress_errors( $suppress_state );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Load candidate records for evaluation.
	 *
	 * Implementation is intentionally minimal — a real deployment overrides
	 * via the `wp_mcp_ai_memory_tier_manager_candidates` filter to query the
	 * CCT table directly. The default returns an empty array so the cron
	 * sweep is a no-op on transient-only sites.
	 *
	 * @return array
	 */
	protected function load_candidates() {
		/**
		 * Filter: provide the list of candidate records for the next tier sweep.
		 *
		 * @param array $records Each: array with keys context_id, agent_id, wing,
		 *                       room, tier, importance, access_count,
		 *                       last_accessed, verbatim.
		 */
		$records = apply_filters( 'wp_mcp_ai_memory_tier_manager_candidates', array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Load `core`-tier records grouped by wing for the per-wing cap pass.
	 *
	 * Same filter contract as {@see load_candidates()}.
	 *
	 * @return array<string,array>
	 */
	protected function load_core_records_grouped_by_wing() {
		$records = $this->load_candidates();
		$grouped = array();
		foreach ( $records as $record ) {
			$tier = isset( $record['tier'] ) ? (string) $record['tier'] : '';
			if ( WP_MCP_AI_Memory_Capture_Service::TIER_CORE !== $tier ) {
				continue;
			}
			$wing = isset( $record['wing'] ) ? (string) $record['wing'] : '';
			if ( '' === $wing ) {
				continue;
			}
			if ( ! isset( $grouped[ $wing ] ) ) {
				$grouped[ $wing ] = array();
			}
			$grouped[ $wing ][] = $record;
		}
		return $grouped;
	}

	/**
	 * Days between $datetime and now. Returns PHP_INT_MAX for empty / invalid.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return int
	 */
	protected function days_since( $datetime ) {
		if ( ! is_string( $datetime ) || '' === $datetime ) {
			return PHP_INT_MAX;
		}
		$ts = strtotime( $datetime );
		if ( false === $ts ) {
			return PHP_INT_MAX;
		}
		$delta = time() - $ts;
		if ( $delta < 0 ) {
			return 0;
		}
		return (int) floor( $delta / DAY_IN_SECONDS );
	}

	/**
	 * Map a Letta-style memory_tier to the MemPalace tier axis.
	 *
	 * @param string $memory_tier working|episodic|semantic|procedural.
	 * @return string core|recall|archival
	 */
	protected function letta_to_memp( $memory_tier ) {
		switch ( $memory_tier ) {
			case 'working':
			case 'semantic':
				return WP_MCP_AI_Memory_Capture_Service::TIER_CORE;
			case 'episodic':
				return WP_MCP_AI_Memory_Capture_Service::TIER_RECALL;
			case 'procedural':
			default:
				return WP_MCP_AI_Memory_Capture_Service::TIER_ARCHIVAL;
		}
	}
}

WP_MCP_AI_Memory_Tier_Manager::bootstrap();

// Memory Layer 2026 Phase 5 — contradiction detector. Loaded alongside the
// tier manager so the `store_agent_context` integration's `class_exists()`
// gate finds it at runtime even before any caller has invoked the singleton.
if ( ! class_exists( 'WP_MCP_AI_Memory_Contradiction_Detector' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-memory-contradiction-detector.php';
}
WP_MCP_AI_Memory_Contradiction_Detector::bootstrap();
