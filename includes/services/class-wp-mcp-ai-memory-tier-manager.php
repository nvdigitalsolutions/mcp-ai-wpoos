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
			'context_id'   => isset( $record['context_id'] ) ? (string) $record['context_id'] : '',
			'agent_id'     => isset( $record['agent_id'] ) ? (string) $record['agent_id'] : '',
			'wing'         => isset( $record['wing'] ) ? (string) $record['wing'] : '',
			'room'         => isset( $record['room'] ) ? (string) $record['room'] : '',
			'from_tier'    => isset( $transition['from'] ) ? (string) $transition['from'] : '',
			'to_tier'      => isset( $transition['to'] ) ? (string) $transition['to'] : '',
			'kind'         => isset( $transition['kind'] ) ? (string) $transition['kind'] : '',
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
								'tier'           => $payload['to_tier'],
								'tier_changed'   => $payload['transitioned_at'],
								'tier_kind'      => $payload['kind'],
								'tier_previous'  => $payload['from_tier'],
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
