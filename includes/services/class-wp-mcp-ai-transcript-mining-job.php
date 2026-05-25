<?php
/**
 * Background job runner for retroactive transcript-to-memory mining.
 *
 * Wraps the `WP_MCP_AI_Tool_Mine_Agent_Memory` tool's `transcripts` source
 * in a queued, tick-driven worker so admin operators can extract memories
 * from a long history of stored chat transcripts without blocking a single
 * REST request. Each tick processes a bounded number of sessions, then
 * re-schedules itself via `wp_schedule_single_event`, until the queue is
 * drained or the job is cancelled.
 *
 * State for each job is stored in a transient keyed by job id, so jobs are
 * inherently ephemeral (they evaporate after the configured TTL of 6h).
 * That matches the shape expected by industry practice — Mem0 / LangMem
 * post-mortems both call out "long-lived background extraction" as a
 * footgun. The job here is short-lived, previewable (slice 1's `dry_run`),
 * and cancellable.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-inline-async-tick.php';

/**
 * Transcript mining background job.
 */
class WP_MCP_AI_Transcript_Mining_Job {

	use WP_MCP_AI_Inline_Async_Tick_Trait;

	/**
	 * Cron hook for tick processing.
	 */
	const CRON_HOOK = 'wp_mcp_ai_transcript_mining_tick';

	/**
	 * Object-cache group used by the cooperative tick lock.
	 *
	 * Layered with the transient set in
	 * {@see WP_MCP_AI_Inline_Async_Tick_Trait::inline_async_acquire_tick_lock()}
	 * to give atomic in-process protection on persistent object caches.
	 *
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_tx_mine';

	/**
	 * Transient key prefix for job state records.
	 */
	const STATE_PREFIX = 'wp_mcp_ai_tx_mine_job_';

	/**
	 * State TTL: jobs are discarded 6 hours after their last update.
	 */
	const STATE_TTL = 21600;

	/**
	 * Default number of sessions processed per tick.
	 */
	const DEFAULT_BATCH_SIZE = 10;

	/**
	 * Maximum total sessions allowed in a single job. Bounds the amount of
	 * memory any one operator can write through a single click.
	 */
	const MAX_TOTAL_SESSIONS = 500;

	/**
	 * Transient/object-cache key prefix for the per-tick cooperative lock.
	 *
	 * The lock prevents an inline shutdown worker and a (possibly delayed)
	 * WP-Cron loopback from firing the same tick concurrently and double-
	 * processing a batch.
	 */
	const TICK_LOCK_PREFIX = 'wp_mcp_ai_tx_mine_lock_';

	/**
	 * Tick-lock TTL in seconds. Short enough that a crashed worker does not
	 * permanently jam the job, long enough that a healthy batch comfortably
	 * fits inside it.
	 */
	const TICK_LOCK_TTL = 60;

	/**
	 * Maximum wall-clock time, in seconds, that the in-process tick loop is
	 * allowed to consume on a single PHP request when WP-Cron is disabled.
	 * Past this budget, control returns to the caller and the job waits for
	 * the next inbound REST poll (which will kick the worker again) instead
	 * of risking a PHP `max_execution_time` cliff.
	 */
	const INLINE_LOOP_BUDGET_SECONDS = 20;

	/**
	 * Minimum age, in seconds, that a `queued` job must reach before the
	 * REST poll endpoint is allowed to dispatch a self-healing inline kick.
	 * Avoids racing the initial enqueue request and its own shutdown
	 * handler.
	 */
	const STALE_QUEUED_THRESHOLD_SECONDS = 5;

	/**
	 * Wire the tick handler.
	 *
	 * Idempotent: safe to call on every plugin boot.
	 */
	public static function bootstrap() {
		if ( ! has_action( self::CRON_HOOK, array( __CLASS__, 'handle_tick' ) ) ) {
			add_action( self::CRON_HOOK, array( __CLASS__, 'handle_tick' ) );
		}
	}

	/**
	 * Enqueue a new mining job and schedule its first tick.
	 *
	 * @param array $args   Tool arguments passed straight through to
	 *                      `mine_agent_memory` on each tick. The
	 *                      `transcript_query.session_keys` field is
	 *                      overridden per tick from the resolved queue.
	 * @param array $config Job configuration:
	 *                      - `batch_size` (int)  Sessions per tick.
	 *                      - `total`      (int)  Pre-resolved session
	 *                                            count, used for progress.
	 *                      - `session_keys` (array) Resolved list of
	 *                                            session keys to process.
	 * @return array|WP_Error State record or error.
	 */
	public static function enqueue( array $args, array $config = array() ) {
		if ( empty( $args['agent_id'] ) ) {
			return new WP_Error( 'missing_agent_id', __( 'agent_id is required.', 'mcp-ai-wpoos' ) );
		}

		$batch_size   = isset( $config['batch_size'] ) ? max( 1, min( 50, (int) $config['batch_size'] ) ) : self::DEFAULT_BATCH_SIZE;
		$session_keys = isset( $config['session_keys'] ) && is_array( $config['session_keys'] )
			? array_values( array_unique( array_map( 'sanitize_text_field', $config['session_keys'] ) ) )
			: array();

		// If the caller did not pre-resolve session keys, fall back to one
		// virtual "session": the tick will let the underlying tool resolve
		// its own session set on each call. This keeps the API ergonomic
		// for callers that just want "mine whatever the tool finds".
		if ( empty( $session_keys ) ) {
			$session_keys = array( '__auto__' );
		}

		// Bound total work.
		if ( count( $session_keys ) > self::MAX_TOTAL_SESSIONS ) {
			$session_keys = array_slice( $session_keys, 0, self::MAX_TOTAL_SESSIONS );
		}

		$job_id = wp_generate_uuid4();
		$now    = time();

		$state = array(
			'id'            => $job_id,
			'status'        => 'queued',
			'created_at'    => $now,
			'updated_at'    => $now,
			'user_id'       => get_current_user_id(),
			'agent_id'      => sanitize_text_field( (string) $args['agent_id'] ),
			'args'          => self::sanitize_args( $args ),
			'batch_size'    => $batch_size,
			'queue'         => $session_keys,
			'total'         => count( $session_keys ),
			'processed'     => 0,
			'mined_count'   => 0,
			'skipped_count' => 0,
			'failed_count'  => 0,
			'errors'        => array(),
			'last_message'  => '',
		);

		self::save_state( $state );

		// Schedule the first tick in the immediate past so wp_get_ready_cron_jobs()
		// returns it and spawn_cron() can fire it without waiting for the next organic
		// page load. Scheduling 1s in the future guarantees spawn_cron() skips the
		// event (it only dispatches events whose timestamp is <= time()).
		$tick_timestamp = time() - 1;
		wp_schedule_single_event( $tick_timestamp, self::CRON_HOOK, array( $job_id ) );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'transcript_mining',
				sprintf(
					/* translators: 1: assistant id, 2: total session count */
					__( 'Transcript mining job enqueued for assistant %1$s (%2$d sessions queued).', 'mcp-ai-wpoos' ),
					$state['agent_id'],
					$state['total']
				),
				array(
					'job_id'        => $job_id,
					'agent_id'      => $state['agent_id'],
					'batch_size'    => $batch_size,
					'total'         => $state['total'],
					'auto_resolved' => ( 1 === count( $session_keys ) && '__auto__' === $session_keys[0] ),
					'dry_run'       => ! empty( $state['args']['dry_run'] ),
				)
			);
		}

		// Register the tick with the Cron Manager so it is visible in the
		// admin Cron Manager page and can be monitored/cancelled from there.
		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			WP_MCP_AI_Cron_Manager::record_job(
				self::CRON_HOOK,
				array( $job_id ),
				'single',
				$tick_timestamp,
				$state['user_id']
			);
		}

		// Kick WordPress cron immediately so the first tick runs even if no page
		// load follows this request. spawn_cron() returning false is not an error —
		// it means another cron spawn is already in flight and will pick up the event.
		$spawn_result = false;
		if ( function_exists( 'spawn_cron' ) ) {
			$spawn_result = spawn_cron();
		}

		// Industry-standard inline-async fallback: register a shutdown action
		// that re-checks job state once the REST response has been flushed and,
		// if the cron loopback hasn't already drained the queue (which is the
		// common case on hosts where `DISABLE_WP_CRON` is true or where the
		// `wp-cron.php` loopback is firewalled), executes the first tick in
		// this same PHP process. Without this, jobs sit at `status: queued`
		// indefinitely on otherwise-correctly-configured sites.
		//
		// Honour the shared escape hatch (filter
		// `wp_mcp_ai_inline_kick_enabled`) so operators can disable the
		// fallback globally or per-job without touching code.
		if ( self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			add_action(
				'shutdown',
				static function () use ( $job_id ) {
					WP_MCP_AI_Transcript_Mining_Job::kick_inline( $job_id );
				},
				20
			);
		}

		// If `spawn_cron()` could not dispatch the loopback (returns false when
		// the `_doing_wp_cron` lock is held or when the host blocks the request),
		// surface a single diagnostic event so operators can correlate the
		// "queued forever" symptom with a misconfigured loopback. The inline
		// shutdown worker registered above guarantees the job still progresses.
		if ( function_exists( 'spawn_cron' ) && false === $spawn_result && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'transcript_mining',
				__( 'spawn_cron() returned false after enqueue — WP-Cron loopback may be misconfigured. Falling back to inline shutdown worker.', 'mcp-ai-wpoos' ),
				array(
					'job_id' => $job_id,
					'hint'   => 'check loopback HTTP reachability and DISABLE_WP_CRON setting',
				)
			);
		}

		return $state;
	}

	/**
	 * Run the first available tick of a job inline, in the current PHP
	 * process. Used both by the `shutdown` action registered in
	 * {@see enqueue()} and by the self-healing branch of the REST poll
	 * endpoint when a job has sat in `queued` longer than the stale
	 * threshold.
	 *
	 * Behaviour:
	 *
	 * - Flushes the active HTTP response (FastCGI) and detaches the worker
	 *   from the client (`ignore_user_abort`) so the operator's browser
	 *   sees the JSON response immediately while the tick continues.
	 * - Re-reads job state and bails if a parallel cron tick has already
	 *   advanced the job past `queued`.
	 * - Delegates to {@see handle_tick()} which owns the cooperative lock
	 *   and the actual batch processing.
	 *
	 * Safe to call from any request context; the cooperative lock prevents
	 * duplicate execution when WP-Cron eventually fires its own tick.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function kick_inline( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return;
		}

		// Honour the global escape hatch so operators can disable the
		// inline-async fallback (per-job or globally) when it interacts
		// badly with the host environment. When disabled, the cron
		// loopback path is unchanged.
		if ( ! self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			return;
		}

		// Survive a client disconnect and flush the response (FastCGI) so
		// the operator's browser sees the JSON immediately while the tick
		// continues. Delegated to the shared trait helper so all Tier-1
		// jobs detach the same way.
		self::inline_async_detach_worker_from_client();

		// Wrap the tick body in the shared observability helper so the
		// `wp_mcp_ai_inline_kick_completed` action fires for Mine
		// Memories the same way it does for the Tool Async Executor.
		self::inline_async_run_kick(
			__CLASS__,
			$job_id,
			static function () use ( $job_id ) {
				$state = self::get_state( $job_id );
				if ( ! is_array( $state ) ) {
					return;
				}

				// Only kick when the cron tick has not already advanced the
				// job. Once status is `running`, `completed`, `cancelled`,
				// or `failed`, the cooperative lock in handle_tick() would
				// block us anyway — short-circuit to avoid the lock churn.
				if ( 'queued' !== $state['status'] ) {
					return;
				}

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'transcript_mining',
						__( 'Transcript mining job kicked inline (cron loopback fallback).', 'mcp-ai-wpoos' ),
						array(
							'job_id' => $job_id,
							'source' => 'inline_shutdown',
						)
					);
				}

				self::handle_tick( $job_id );
			}
		);
	}

	/**
	 * Cron callback. Processes one batch and re-schedules if more work remains.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function handle_tick( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$state  = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return;
		}

		if ( in_array( $state['status'], array( 'cancelled', 'completed', 'failed' ), true ) ) {
			return;
		}

		// Cooperative lock against concurrent ticks. If another worker (a
		// delayed cron loopback, a parallel shutdown handler, etc.) is
		// already inside the critical section for this job, bail — that
		// worker will save fresh state when it exits and the next tick can
		// pick up from there.
		if ( ! self::acquire_tick_lock( $job_id ) ) {
			return;
		}

		$tick_started_at = time();
		$should_loop     = false;

		try {
			$should_loop = self::process_tick_body( $job_id, $tick_started_at );
		} finally {
			self::release_tick_lock( $job_id );
		}

		// `DISABLE_WP_CRON` inline-loop branch: when WP-Cron is disabled,
		// re-scheduling the next tick alone is insufficient because the
		// scheduled event will never fire of its own accord. Recurse in
		// this same PHP process while we still have wall-clock budget.
		// The recursion re-enters handle_tick(), which acquires its own
		// lock and re-reads state, so the cancellation gate is honoured.
		if ( $should_loop ) {
			self::handle_tick( $job_id );
		}
	}

	/**
	 * Process exactly one batch for the given job. Returns true when the
	 * caller should immediately invoke another tick inline (because
	 * WP-Cron is disabled and the queue still has work and we are inside
	 * the inline-loop wall-clock budget).
	 *
	 * Internal helper of {@see handle_tick()}; runs inside the tick lock.
	 *
	 * @param string $job_id          Job identifier.
	 * @param int    $tick_started_at Unix timestamp recorded immediately
	 *                                before the lock was acquired; used
	 *                                to bound the inline loop.
	 * @return bool Whether to continue with another tick inline.
	 */
	private static function process_tick_body( $job_id, $tick_started_at ) {
		$state = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return false;
		}

		// Re-check the cancellation/completion gate after taking the lock:
		// another worker may have moved the job past these states in the
		// brief window between the outer check and lock acquisition.
		if ( in_array( $state['status'], array( 'cancelled', 'completed', 'failed' ), true ) ) {
			return false;
		}

		$state['status']     = 'running';
		$state['updated_at'] = time();
		self::save_state( $state );

		$batch = array_splice( $state['queue'], 0, $state['batch_size'] );
		if ( empty( $batch ) ) {
			$state['status']       = 'completed';
			$state['last_message'] = __( 'No more sessions to process.', 'mcp-ai-wpoos' );
			$state['updated_at']   = time();
			self::save_state( $state );
			return false;
		}

		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ? WP_MCP_AI_Tool_Registry::get_instance() : null );

		$tool = $registry ? $registry->get_tool( 'mine_agent_memory' ) : null;
		if ( ! $tool ) {
			$state['status']       = 'failed';
			$state['last_message'] = __( 'mine_agent_memory tool unavailable.', 'mcp-ai-wpoos' );
			$state['errors'][]     = $state['last_message'];
			$state['updated_at']   = time();
			self::save_state( $state );
			return false;
		}

		// Build the per-tick tool arguments. When the queue holds explicit
		// session keys, scope the tool to just this batch via
		// `transcript_query.session_keys`. The `__auto__` sentinel means
		// "let the tool resolve its own session set" — used when the
		// caller did not pre-resolve a queue.
		$args = $state['args'];
		if ( ! ( 1 === count( $batch ) && '__auto__' === $batch[0] ) ) {
			if ( ! isset( $args['transcript_query'] ) || ! is_array( $args['transcript_query'] ) ) {
				$args['transcript_query'] = array();
			}
			$args['transcript_query']['session_keys'] = $batch;
		}

		$result = $tool->execute( $args, array() );

		$mined_this_tick   = 0;
		$skipped_this_tick = 0;
		$failed_this_tick  = 0;
		$tick_message      = '';

		if ( is_wp_error( $result ) ) {
			$state['failed_count'] += count( $batch );
			$state['errors'][]      = $result->get_error_message();
			$state['last_message']  = $result->get_error_message();
			$failed_this_tick       = count( $batch );
			$tick_message           = $result->get_error_message();

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					sprintf(
						/* translators: %s: error message from mine_agent_memory tool */
						__( 'Transcript mining tick failed: %s', 'mcp-ai-wpoos' ),
						$result->get_error_message()
					),
					array(
						'job_id'     => $job_id,
						'agent_id'   => isset( $state['agent_id'] ) ? $state['agent_id'] : '',
						'batch_size' => count( $batch ),
						'error_code' => $result->get_error_code(),
					)
				);
			}
		} elseif ( is_array( $result ) ) {
			$mined_this_tick         = isset( $result['count'] ) ? (int) $result['count'] : 0;
			$skipped_this_tick       = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
			$failed_this_tick        = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
			$state['mined_count']   += $mined_this_tick;
			$state['skipped_count'] += $skipped_this_tick;
			$state['failed_count']  += $failed_this_tick;
			$state['last_message']   = isset( $result['message'] ) ? (string) $result['message'] : '';
			$tick_message            = $state['last_message'];
			if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
				$state['errors'][] = (string) $result['message'];
			}
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) && ! is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_event(
				'transcript_mining',
				'' !== $tick_message
					? $tick_message
					: __( 'Transcript mining tick completed.', 'mcp-ai-wpoos' ),
				array(
					'job_id'     => $job_id,
					'agent_id'   => isset( $state['agent_id'] ) ? $state['agent_id'] : '',
					'batch_size' => count( $batch ),
					'mined'      => $mined_this_tick,
					'skipped'    => $skipped_this_tick,
					'failed'     => $failed_this_tick,
					'dry_run'    => ! empty( $state['args']['dry_run'] ),
				)
			);
		}

		$state['processed'] += count( $batch );
		$state['updated_at'] = time();

		$queue_remaining = ! empty( $state['queue'] );

		if ( ! $queue_remaining ) {
			$state['status'] = 'completed';
		} else {
			// Re-schedule the next tick in the immediate past so spawn_cron()
			// can fire it without waiting for the next organic page load.
			$tick_timestamp = time() - 1;
			wp_schedule_single_event( $tick_timestamp, self::CRON_HOOK, array( $job_id ) );

			// Update the Cron Manager entry so the tick remains visible.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				WP_MCP_AI_Cron_Manager::record_job(
					self::CRON_HOOK,
					array( $job_id ),
					'single',
					$tick_timestamp,
					isset( $state['user_id'] ) ? (int) $state['user_id'] : 0
				);
			}

			// Drive the next tick immediately.
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}

		self::save_state( $state );

		// Continue inline only when WP-Cron is disabled (the scheduled
		// event we just queued will never fire on its own), there is more
		// work, and we still have wall-clock budget to spare in this
		// request. Delegated to the shared trait helper so all Tier-1
		// jobs share one predicate.
		return self::inline_async_should_loop( $tick_started_at, $queue_remaining, self::INLINE_LOOP_BUDGET_SECONDS );
	}

	/**
	 * Acquire the per-job tick lock.
	 *
	 * Thin wrapper around the shared trait helper that pins the
	 * Mine-Memories-specific key prefix, cache group, and TTL so callers
	 * inside this class can stay terse.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True when the lock was acquired by this caller.
	 */
	private static function acquire_tick_lock( $job_id ) {
		return self::inline_async_acquire_tick_lock(
			self::TICK_LOCK_PREFIX . $job_id,
			self::TICK_LOCK_CACHE_GROUP,
			self::TICK_LOCK_TTL
		);
	}

	/**
	 * Release the per-job tick lock acquired by {@see acquire_tick_lock()}.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private static function release_tick_lock( $job_id ) {
		self::inline_async_release_tick_lock(
			self::TICK_LOCK_PREFIX . $job_id,
			self::TICK_LOCK_CACHE_GROUP
		);
	}

	/**
	 * Cancel a running job. Future ticks short-circuit; an already-firing
	 * tick will finish its current batch.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated state record or error.
	 */
	public static function cancel( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$state  = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'job_not_found', __( 'Job not found.', 'mcp-ai-wpoos' ) );
		}

		if ( in_array( $state['status'], array( 'completed', 'cancelled', 'failed' ), true ) ) {
			return $state;
		}

		$state['status']       = 'cancelled';
		$state['queue']        = array();
		$state['last_message'] = __( 'Cancelled by operator.', 'mcp-ai-wpoos' );
		$state['updated_at']   = time();
		self::save_state( $state );
		return $state;
	}

	/**
	 * Read the state record for a job.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null
	 */
	public static function get_state( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return null;
		}
		$state = get_transient( self::STATE_PREFIX . $job_id );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Public progress projection (drops internal fields like the queue).
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null
	 */
	public static function get_progress( $job_id ) {
		$state = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return null;
		}

		$total     = (int) $state['total'];
		$processed = (int) $state['processed'];
		$percent   = $total > 0 ? min( 100, (int) floor( ( $processed * 100 ) / $total ) ) : 0;

		return array(
			'id'            => $state['id'],
			'status'        => $state['status'],
			'total'         => $total,
			'processed'     => $processed,
			'mined_count'   => (int) $state['mined_count'],
			'skipped_count' => (int) $state['skipped_count'],
			'failed_count'  => (int) $state['failed_count'],
			'percent'       => $percent,
			'last_message'  => (string) $state['last_message'],
			'errors'        => array_values( array_slice( (array) $state['errors'], -5 ) ),
			'created_at'    => (int) $state['created_at'],
			'updated_at'    => (int) $state['updated_at'],
		);
	}

	/**
	 * Persist a state record.
	 *
	 * @param array $state State record.
	 * @return void
	 */
	private static function save_state( array $state ) {
		set_transient( self::STATE_PREFIX . $state['id'], $state, self::STATE_TTL );
	}

	/**
	 * Sanitise tool args before storing them on the job. Only the fields
	 * that drive transcript mining are preserved; anything else is dropped
	 * to keep the job state record small and predictable.
	 *
	 * @param array $args Raw tool args.
	 * @return array
	 */
	private static function sanitize_args( array $args ) {
		$out = array(
			'agent_id' => sanitize_text_field( (string) $args['agent_id'] ),
			'source'   => 'transcripts',
		);
		foreach ( array( 'wing', 'room', 'context_type', 'importance' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_string( $args[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $args[ $key ] );
			}
		}
		foreach ( array( 'verbatim', 'dry_run' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$out[ $key ] = (bool) $args[ $key ];
			}
		}
		foreach ( array( 'ttl', 'chunk_size' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$out[ $key ] = (int) $args[ $key ];
			}
		}
		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$out['tags'] = array_values( array_filter( array_map( 'sanitize_text_field', $args['tags'] ) ) );
		}
		if ( isset( $args['transcript_query'] ) && is_array( $args['transcript_query'] ) ) {
			$tq    = $args['transcript_query'];
			$clean = array();
			foreach ( array( 'assistant_id', 'user_id', 'min_messages', 'posts_per_page' ) as $k ) {
				if ( isset( $tq[ $k ] ) ) {
					$clean[ $k ] = (int) $tq[ $k ];
				}
			}
			foreach ( array( 'since', 'until' ) as $k ) {
				if ( isset( $tq[ $k ] ) && is_string( $tq[ $k ] ) ) {
					$clean[ $k ] = sanitize_text_field( $tq[ $k ] );
				}
			}
			if ( isset( $tq['only_unextracted'] ) ) {
				$clean['only_unextracted'] = (bool) $tq['only_unextracted'];
			}
			if ( isset( $tq['session_keys'] ) && is_array( $tq['session_keys'] ) ) {
				$clean['session_keys'] = array_values( array_map( 'sanitize_text_field', $tq['session_keys'] ) );
			}
			$out['transcript_query'] = $clean;
		}
		return $out;
	}
}

WP_MCP_AI_Transcript_Mining_Job::bootstrap();
