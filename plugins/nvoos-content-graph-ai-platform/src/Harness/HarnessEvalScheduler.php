<?php
/**
 * Harness Layer G — Profile-driven eval invocation.
 *
 * Walks the assistants that have opted in via `harness_profile.enabled`
 * with one or more `evals_enabled` slugs, runs the matching suites
 * registered with `WP_MCP_AI_Eval_Suite_Registry`, and records summaries
 * to:
 *
 *   - the existing `WP_MCP_AI_Eval_Run_Store` (suite-scoped trend history,
 *     consumed by the regression detector and the OTel exporter), and
 *   - per-assistant post meta `_wp_mcp_ai_harness_last_evals` for fast
 *     "what was the last result for this assistant × this suite?" lookup
 *     by the admin UI.
 *
 * The actual generation step (the `callable` that produces a model output
 * for a given case) is intentionally NOT shipped here — every site routes
 * model calls through different code (Pro's autonomous-session API, a
 * custom REST proxy, a local stub for tests, etc). Sites/Pro provide it
 * via the `wp_mcp_ai_harness_eval_generator` filter; the scheduler will
 * skip the run with a logged notice when no generator is wired up,
 * preserving the behaviour-preserving guarantee of the base harness.
 *
 * @package   WP_MCP_AI
 * @since     1.4.0
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
 * Harness eval scheduler.
 *
 * @since 1.4.0
 */
class HarnessEvalScheduler {

	use InlineAsyncTickTrait;

	/**
	 * Cron hook fired daily.
	 */
	const CRON_HOOK = 'wp_mcp_ai_harness_eval_tick';

	/**
	 * Post meta storing the last summary per (assistant, suite).
	 *
	 * Shape: array<string, array{ started_at:int, summary:array }>.
	 * Keyed by suite slug.
	 */
	const META_LAST_EVALS = '_wp_mcp_ai_harness_last_evals';

	/**
	 * Hard cap on the number of (assistant, suite) pairs processed per
	 * tick. Prevents a misconfigured site from running thousands of
	 * suites on one wp-cron invocation.
	 */
	const MAX_PAIRS_PER_TICK = 25;

	/**
	 * Fixed key for the tick lock.  Only one eval tick should run at a
	 * time; re-entrant cron loopbacks are silently skipped.
	 *
	 * Used with {@see inline_async_acquire_tick_lock()} /
	 * {@see inline_async_release_tick_lock()}.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const TICK_LOCK_KEY = 'wp_mcp_ai_harness_eval_tick_lock';

	/**
	 * Object-cache group for the eval tick lock.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_harness_eval';

	/**
	 * Tick lock TTL in seconds. Each (assistant × suite) pair fires at
	 * most one AI call; 120 s accommodates sites with up to ~4 s per call
	 * and the 25-pair cap.
	 *
	 * @since 1.4.1
	 * @var int
	 */
	const TICK_LOCK_TTL = 120;

	/**
	 * Wire the cron hook + scheduling.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Schedule the daily cron once. When the event is newly created (i.e.
	 * it did not exist before this call) AND the inline-async kick is
	 * enabled, a one-shot shutdown kick is also registered so the first
	 * tick fires in the current request rather than waiting up to 24 hours
	 * for the loopback to catch up.
	 *
	 * @return void
	 */
	public static function maybe_schedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );

		// Inline-async-tick: fire the first tick inline on the shutdown of
		// the request that first activates the eval scheduler (e.g. saving
		// a harness profile or activating the plugin), so opted-in
		// assistants see an initial eval result within seconds rather than
		// waiting until tomorrow.
		if ( self::inline_async_kick_enabled( 'first_schedule', __CLASS__ ) ) {
			add_action(
				'shutdown',
				function () {
					self::inline_async_detach_worker_from_client();
					self::inline_async_run_kick(
						__CLASS__,
						'first_schedule',
						function () {
							self::tick();
						}
					);
				},
				22
			);
		}
	}

	/**
	 * Cron handler. Acquires the cooperative tick lock then delegates to
	 * {@see do_tick()} so that a WP-Cron loopback and a concurrent inline
	 * shutdown kick cannot run two overlapping eval batches.
	 *
	 * @return array{processed:int, skipped:int, errors:int} Summary for
	 *                                                       observability.
	 */
	public static function tick() {
		if ( ! self::inline_async_acquire_tick_lock( self::TICK_LOCK_KEY, self::TICK_LOCK_CACHE_GROUP, self::TICK_LOCK_TTL ) ) {
			return array(
				'processed' => 0,
				'skipped'   => 0,
				'errors'    => 0,
			);
		}
		try {
			return self::do_tick();
		} finally {
			self::inline_async_release_tick_lock( self::TICK_LOCK_KEY, self::TICK_LOCK_CACHE_GROUP );
		}
	}

	/**
	 * Inner tick body — extracted so tests can call it directly without
	 * going through the tick lock.
	 *
	 * Iterates opted-in assistants and runs their enabled suites, bounded
	 * by {@see MAX_PAIRS_PER_TICK}.
	 *
	 * @since 1.4.1
	 *
	 * @return array{processed:int, skipped:int, errors:int} Summary for
	 *                                                       observability.
	 */
	public static function do_tick() {
		$assistant_ids = self::find_opted_in_assistants();

		$processed = 0;
		$skipped   = 0;
		$errors    = 0;
		$pairs     = 0;

		foreach ( $assistant_ids as $assistant_id ) {
			$profile = HarnessProfile::get( $assistant_id );
			if ( empty( $profile['enabled'] ) ) {
				continue;
			}
			if ( empty( $profile['evals_enabled'] ) || ! is_array( $profile['evals_enabled'] ) ) {
				continue;
			}

			foreach ( $profile['evals_enabled'] as $suite_slug ) {
				if ( $pairs >= self::MAX_PAIRS_PER_TICK ) {
					break 2;
				}
				++$pairs;

				$result = self::run_suite_for_assistant( (int) $assistant_id, (string) $suite_slug );
				if ( is_wp_error( $result ) ) {
					if ( 'wp_mcp_ai_harness_eval_no_generator' === $result->get_error_code() ) {
						++$skipped;
					} else {
						++$errors;
					}
					continue;
				}
				++$processed;
			}
		}

		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Run a single suite for a single assistant. Public so admins can
	 * trigger a one-off run from a dashboard button or WP-CLI command.
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $suite_slug   Suite slug from the registry.
	 * @return array|WP_Error Run report on success; WP_Error on skip/fail.
	 */
	public static function run_suite_for_assistant( $assistant_id, $suite_slug ) {
		$assistant_id = (int) $assistant_id;
		$suite_slug   = sanitize_key( (string) $suite_slug );

		if ( $assistant_id <= 0 || '' === $suite_slug ) {
			return new \WP_Error( 'wp_mcp_ai_harness_eval_invalid_args', __( 'Invalid assistant or suite.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Standalone mode: the suite registry and runner live in the base
		// plugin's measurement subsystem; without it there is nothing to
		// schedule, so degrade to a WP_Error instead of a fatal.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_harness_eval_unavailable',
				__( 'Harness eval scheduling requires the base plugin evaluation infrastructure, which is not available in standalone mode.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$registry = \WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$registry->boot();
		$suite = $registry->get( $suite_slug );
		if ( ! $suite instanceof \WP_MCP_AI_Eval_Suite ) {
			return new \WP_Error(
				'wp_mcp_ai_harness_eval_unknown_suite',
				/* translators: %s: suite slug */
				sprintf( __( 'Eval suite "%s" is not registered.', 'nvoos-content-graph-ai-platform' ), $suite_slug )
			);
		}

		/**
		 * Filter the generator callable used by the harness eval scheduler.
		 *
		 * The callable signature matches `\WP_MCP_AI_Eval_Runner::run()`:
		 *   function ( WP_MCP_AI_Eval_Case $case, array $suite_context ): array|WP_Error
		 *
		 * Returning `null` (the default) signals "no generator wired up";
		 * the scheduler will skip the run rather than fail it.
		 *
		 * @since 1.4.0
		 *
		 * @param callable|null         $generator    Generator callable, or null.
		 * @param int                   $assistant_id Assistant post ID.
		 * @param WP_MCP_AI_Eval_Suite  $suite        Suite about to run.
		 * @param array                 $profile      Resolved harness profile.
		 */
		$profile   = HarnessProfile::get( $assistant_id );
		$generator = apply_filters(
			'wp_mcp_ai_harness_eval_generator',
			null,
			$assistant_id,
			$suite,
			$profile
		);

		if ( ! is_callable( $generator ) ) {
			return new \WP_Error(
				'wp_mcp_ai_harness_eval_no_generator',
				__( 'No harness eval generator is wired up. Hook `wp_mcp_ai_harness_eval_generator` to enable scheduled runs.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$runner = new \WP_MCP_AI_Eval_Runner();
		$report = $runner->run( $suite, $generator );

		// Record into the suite-scoped run store (trend history,
		// regression detector input).
		$store = new \WP_MCP_AI_Eval_Run_Store();
		$store->record(
			$suite_slug,
			isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array(),
			isset( $report['started_at'] ) ? (int) $report['started_at'] : null
		);

		// Record into per-assistant meta for the admin UI's
		// "last result" column.
		self::record_last_run( $assistant_id, $suite_slug, $report );

		/**
		 * Fires after the harness scheduler completes a per-assistant run.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $report       Runner report.
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $suite_slug   Suite slug.
		 */
		do_action( 'wp_mcp_ai_harness_eval_completed', $report, $assistant_id, $suite_slug );

		return $report;
	}

	/**
	 * Read the last-run summaries for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array<string, array{started_at:int, summary:array}>
	 */
	public static function get_last_runs( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return array();
		}
		$raw = get_post_meta( $assistant_id, self::META_LAST_EVALS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $slug => $row ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $row ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'started_at' => isset( $row['started_at'] ) ? (int) $row['started_at'] : 0,
				'summary'    => isset( $row['summary'] ) && is_array( $row['summary'] ) ? $row['summary'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Persist the last-run record for one (assistant, suite) pair.
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $suite_slug   Suite slug.
	 * @param array  $report       Runner report.
	 * @return void
	 */
	private static function record_last_run( $assistant_id, $suite_slug, array $report ) {
		$existing                = self::get_last_runs( $assistant_id );
		$existing[ $suite_slug ] = array(
			'started_at' => isset( $report['started_at'] ) ? (int) $report['started_at'] : time(),
			'summary'    => isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array(),
		);
		update_post_meta( $assistant_id, self::META_LAST_EVALS, $existing );
	}

	/**
	 * Find assistant IDs with a non-empty harness profile.
	 *
	 * Uses a lightweight meta_query against the `mcp_ai_assistant` CPT
	 * so we don't load full post objects.
	 *
	 * @return int[] Assistant IDs.
	 */
	private static function find_opted_in_assistants() {
		$ids = get_posts(
			array(
				'post_type'        => 'mcp_ai_assistant',
				'post_status'      => 'any',
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Upper bound on opted-in assistants scanned per eval tick; intentionally larger than the 25-pair processing cap.
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta query on custom harness CPT is required for evaluation scheduling; indexed via cron.
					array(
						'key'     => HarnessProfile::META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Clear the scheduled cron event. Called on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
