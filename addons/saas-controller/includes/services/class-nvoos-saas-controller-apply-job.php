<?php
/**
 * Background async Apply worker (Phase 8).
 *
 * Wraps {@see NVOOS_SaaS_Controller_Apply_Engine::apply_row()} in a queued,
 * cron-tick driven worker so large applies (multiple D1 DBs + KV namespaces
 * + Stripe products/prices + Worker upload) do not run inside a single REST
 * request and risk hitting `max_execution_time` on shared hosts.
 *
 * # Lifecycle
 *
 * 1. Operator hits **Run Plan** in the Operations tab → POST /apply/preview
 *    issues a HITL apply token bound to the previewed plan (existing Phase 5b).
 * 2. Operator clicks **Apply (background)** → POST /apply/enqueue with the
 *    apply token. {@see self::enqueue()}:
 *      • consumes the single-use token via the existing apply engine,
 *      • flattens the previewed plan's `creates[]` and `updates[]` into an
 *        ordered queue of `{ section, row }` tuples,
 *      • allocates a UUID job id and persists the queue + a results buffer
 *        as a 6h transient (mirroring the base plugin's
 *        `WP_MCP_AI_Transcript_Mining_Job` pattern referenced in the addon
 *        README's "Features (planned)" notes),
 *      • schedules the first tick in the immediate past so `spawn_cron()`
 *        can fire it without waiting for the next organic page load.
 * 3. Each tick {@see self::handle_tick()} processes ONE row, appends a
 *    structured result to the buffer, mirrors a single audit-log entry,
 *    and re-schedules itself until the queue is drained or the job is
 *    cancelled. One row per tick keeps each cron callback well under any
 *    realistic `max_execution_time`, even if the underlying mutating call
 *    has a long network leg (e.g. Worker multipart upload to Cloudflare).
 * 4. The admin UI polls {@see self::get_progress()} via `GET /apply/jobs/{id}`
 *    until `status` is one of `completed | cancelled | failed`.
 *
 * # State shape (transient)
 *
 *     [
 *         'id'           => '<uuid4>',
 *         'status'       => 'queued|running|completed|cancelled|failed',
 *         'created_at'   => <unix-ts>,
 *         'updated_at'   => <unix-ts>,
 *         'user_id'      => <int>,
 *         'queue'        => [ [ 'section' => 'create|update', 'row' => [...] ], ... ],
 *         'total'        => <int>,        // initial queue size, never decreases.
 *         'processed'    => <int>,        // rows pulled from the queue and dispatched.
 *         'results'      => [ <result-row>, ... ],
 *         'summary'      => [ 'ok' => N, 'error' => N, 'skipped' => N ],
 *         'errors'       => [ <string>, ... ], // bounded to the last 10.
 *         'last_message' => '<short>',
 *     ]
 *
 * # Why one row per tick (not a configurable batch size)
 *
 * Apply rows are heterogeneous: a D1 create returns in <1s but a Worker
 * upload regularly takes 5-15s. A "batch of 5" tick can therefore exceed
 * `max_execution_time` on shared hosts even though the average row is fast.
 * One row per tick keeps the worst-case tick bounded by the slowest single
 * Cloudflare/Stripe/OpenRouter call, not by an arbitrary batch size, and
 * makes the progress display naturally row-accurate. The `MAX_TOTAL_ROWS`
 * ceiling is the real bound on a single apply.
 *
 * # Why the queue is server-side (not derived from the plan again)
 *
 * Same reasoning as the synchronous Apply path: caching the *exact* set of
 * rows the operator reviewed means the apply mutations always match what
 * the operator approved, even if live state changed mid-apply. Re-deriving
 * the plan per tick would risk silently skipping (or worse, re-creating)
 * resources mid-job.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NVOOS_SaaS_Controller_Apply_Job' ) ) {
	return;
}

// Load the shared inline-async-tick trait from the base plugin. The trait
// (introduced in PR #4916 + #4920 for the Mine Memories job) gives this
// addon the same `DISABLE_WP_CRON` / firewalled-loopback fallback for free.
// The base plugin is a hard prerequisite for this addon (see the activation
// gate in nvoos-saas-controller.php), so WP_MCP_AI_PATH is guaranteed to
// exist by the time this file is loaded.
if ( defined( 'WP_MCP_AI_PATH' ) && ! trait_exists( 'WP_MCP_AI_Inline_Async_Tick_Trait' ) ) {
	$nvoos_saas_apply_job_trait_path = WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-inline-async-tick.php';
	if ( file_exists( $nvoos_saas_apply_job_trait_path ) ) {
		require_once $nvoos_saas_apply_job_trait_path;
	}
	unset( $nvoos_saas_apply_job_trait_path );
}

/**
 * Apply background-job worker.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Apply_Job {

	use WP_MCP_AI_Inline_Async_Tick_Trait;

	/**
	 * Cron hook for tick processing. The `cron_schedules` filter is not
	 * needed — every tick is a single-event reschedule, never a recurring
	 * schedule.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'nvoos_saas_controller_apply_tick';

	/**
	 * Transient key prefix for job state records.
	 *
	 * @var string
	 */
	const STATE_PREFIX = 'nvoos_saas_apply_job_';

	/**
	 * State TTL: jobs are discarded 6 hours after their last update.
	 * Filterable via `nvoos_saas_controller_apply_job_state_ttl` so an
	 * operator working through a 100-row plan during off-peak maintenance
	 * can extend the window if needed.
	 *
	 * @var int
	 */
	const STATE_TTL = 21600;

	/**
	 * Hard ceiling on the number of plan rows a single job will execute.
	 * The plan generator already produces compact diffs (creates only,
	 * never deletes) so realistic plans live well below this limit; the
	 * ceiling is a safety net against a mis-resolved desired-config that
	 * accidentally requests hundreds of rows in one go.
	 *
	 * @var int
	 */
	const MAX_TOTAL_ROWS = 200;

	/**
	 * Prefix for the cooperative tick lock key (consumed by the
	 * inline-async-tick trait helpers).
	 *
	 * @var string
	 */
	const TICK_LOCK_PREFIX = 'nvoos_saas_apply_lock_';

	/**
	 * Object-cache group used by the cooperative tick lock. Layered with
	 * the transient set in
	 * {@see WP_MCP_AI_Inline_Async_Tick_Trait::inline_async_acquire_tick_lock()}
	 * to give atomic in-process protection on persistent object caches.
	 *
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'nvoos_saas_apply';

	/**
	 * Tick lock TTL in seconds. Must comfortably exceed the slowest
	 * realistic per-row apply call (a Worker multipart upload can take
	 * 5–15 s in the wild) plus jitter; 120 s is the chosen ceiling.
	 *
	 * @var int
	 */
	const TICK_LOCK_TTL = 120;

	/**
	 * Stale-job staleness threshold for the REST self-heal kick. When the
	 * admin UI polls `/apply/jobs/{id}` and the job has sat in `queued`
	 * past this many seconds since `updated_at`, the controller schedules
	 * a shutdown kick on the way out so the next poll observes progress.
	 *
	 * @var int
	 */
	const STALE_QUEUED_THRESHOLD_SECONDS = 5;

	/**
	 * Inline-loop wall-clock budget when `DISABLE_WP_CRON` is true.
	 * Bounds the worst-case time a single PHP request spends running
	 * apply rows back-to-back so the inline fallback cannot blow past
	 * `max_execution_time` on shared hosts. One Worker upload can take
	 * 5–15 s, so 60 s gives ~3–6 ticks per request in the worst case
	 * while still leaving headroom under a 90 s default execution cap.
	 *
	 * @var int
	 */
	const INLINE_LOOP_BUDGET_SECONDS = 60;

	/**
	 * Wire the tick handler. Idempotent — safe to call on every plugin
	 * boot (and on every PHPUnit test run, which is why the
	 * `has_action()` guard is present).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( ! has_action( self::CRON_HOOK, array( __CLASS__, 'handle_tick' ) ) ) {
			add_action( self::CRON_HOOK, array( __CLASS__, 'handle_tick' ) );
		}
	}

	/**
	 * Enqueue a new apply job from a previously-issued HITL apply token.
	 *
	 * Returns the freshly created state record (without the queue, to
	 * keep the response small) on success, or a `WP_Error` if the token
	 * is invalid/expired/already used. The token is single-use: a
	 * successful enqueue consumes it just like the synchronous
	 * {@see NVOOS_SaaS_Controller_REST::route_apply_run()} does, so an
	 * operator cannot enqueue the same plan twice from a single preview.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token Plaintext apply token returned by `/apply/preview`.
	 * @return array|WP_Error State projection on success.
	 */
	public static function enqueue_from_token( $token ) {
		$plan = NVOOS_SaaS_Controller_Apply_Engine::consume_token( $token );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		return self::enqueue_plan( $plan );
	}

	/**
	 * Enqueue a new apply job from a raw plan array.
	 *
	 * Lower-level helper used by both {@see self::enqueue_from_token()}
	 * and the test suite. Callers that have not gone through the HITL
	 * token flow are responsible for their own authorization (the only
	 * caller in the addon is the REST route, which checks
	 * `manage_options` *and* a fresh apply token).
	 *
	 * @since 0.1.0
	 *
	 * @param array $plan Plan from {@see NVOOS_SaaS_Controller_Plan_Generator::generate()}.
	 * @return array|WP_Error State projection on success.
	 */
	public static function enqueue_plan( array $plan ) {
		$queue = self::flatten_plan( $plan );
		if ( empty( $queue ) ) {
			return new WP_Error(
				'empty_apply_plan',
				__( 'Plan has no rows to apply; nothing to enqueue.', 'nvoos-saas-controller' ),
				array( 'status' => 409 )
			);
		}

		if ( count( $queue ) > self::MAX_TOTAL_ROWS ) {
			return new WP_Error(
				'apply_plan_too_large',
				sprintf(
					/* translators: 1: row count, 2: max allowed. */
					__( 'Plan has %1$d rows which exceeds the %2$d-row safety ceiling for a single background apply. Split the plan into smaller chunks.', 'nvoos-saas-controller' ),
					count( $queue ),
					self::MAX_TOTAL_ROWS
				),
				array( 'status' => 413 )
			);
		}

		$job_id = wp_generate_uuid4();
		$now    = time();

		$state = array(
			'id'           => $job_id,
			'status'       => 'queued',
			'created_at'   => $now,
			'updated_at'   => $now,
			'user_id'      => get_current_user_id(),
			'queue'        => $queue,
			'total'        => count( $queue ),
			'processed'    => 0,
			'results'      => array(),
			'summary'      => array(
				'ok'      => 0,
				'error'   => 0,
				'skipped' => 0,
			),
			'errors'       => array(),
			'last_message' => '',
		);

		self::save_state( $state );

		// Audit the enqueue so the operator has a stable correlation
		// point in the audit log even if the very first tick hits a
		// cron-spawn failure.
		if ( class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			NVOOS_SaaS_Controller_Audit_Log::instance()->record(
				array(
					'channel' => 'internal',
					'action'  => 'apply_job_enqueued',
					'target'  => $job_id,
					'status'  => 'ok',
					'message' => sprintf(
						/* translators: %d: number of plan rows enqueued. */
						__( 'Apply job enqueued with %d rows.', 'nvoos-saas-controller' ),
						count( $queue )
					),
				)
			);
		}

		// Schedule the first tick 1s in the past so spawn_cron() picks it
		// up immediately. WordPress's wp_get_ready_cron_jobs() returns
		// any single-event whose timestamp is <= time(), and spawn_cron()
		// then dispatches it via a non-blocking loopback request. This
		// mirrors the pattern used by the base plugin's
		// WP_MCP_AI_Transcript_Mining_Job (see includes/services/
		// class-wp-mcp-ai-transcript-mining-job.php). Scheduling at
		// time() + N would require an organic page load N seconds later
		// before the job moves at all.
		$tick_timestamp = time() - 1;
		wp_schedule_single_event( $tick_timestamp, self::CRON_HOOK, array( $job_id ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		// Industry-standard inline-async fallback: when the WP-Cron loopback
		// is disabled or firewalled, the rescheduled single-event would
		// otherwise sit forever and the apply job would never advance past
		// `queued`. Register a `shutdown` action that re-checks state and
		// runs the first tick inline in this same PHP process once the
		// REST response has been flushed.
		//
		// Honours the shared escape hatch (filter
		// `wp_mcp_ai_inline_kick_enabled`) so operators can disable the
		// fallback per-job or globally without touching code.
		if ( self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			add_action(
				'shutdown',
				static function () use ( $job_id ) {
					NVOOS_SaaS_Controller_Apply_Job::kick_inline( $job_id );
				},
				20
			);
		}

		return self::project( $state );
	}

	/**
	 * Run the first available tick of a job inline, in the current PHP
	 * process. Used both by the `shutdown` action registered in
	 * {@see self::enqueue_plan()} and by the self-healing branch of the
	 * REST poll endpoint when a job has sat in `queued` longer than the
	 * stale threshold.
	 *
	 * Mirrors the shape of
	 * {@see WP_MCP_AI_Transcript_Mining_Job::kick_inline()} so the
	 * `wp_mcp_ai_inline_kick_completed` observability action fires for
	 * SaaS Apply too — Pro measurement bootstrap subscribers record
	 * duration/failure metrics for free.
	 *
	 * @since 0.1.0
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

		// Survive a client disconnect and flush the response (FastCGI)
		// so the operator's browser sees the JSON immediately while the
		// tick continues. Delegated to the shared trait helper so all
		// Tier-1 jobs detach the same way.
		self::inline_async_detach_worker_from_client();

		// Wrap the tick body in the shared observability helper so the
		// `wp_mcp_ai_inline_kick_completed` action fires once per kick.
		self::inline_async_run_kick(
			__CLASS__,
			$job_id,
			static function () use ( $job_id ) {
				$state = self::get_state( $job_id );
				if ( ! is_array( $state ) ) {
					return;
				}

				// Only kick when the cron tick has not already advanced
				// the job to a terminal state. The cooperative lock in
				// handle_tick() would block us for `running` anyway, but
				// short-circuiting here avoids the lock churn (and the
				// extra audit-log noise that a no-op tick would create).
				if ( in_array( $state['status'], array( 'cancelled', 'completed', 'failed' ), true ) ) {
					return;
				}

				self::handle_tick( $job_id );
			}
		);
	}

	/**
	 * Cron callback. Pops one row off the queue, dispatches it through
	 * the apply engine, persists the result, and re-schedules until the
	 * queue is drained.
	 *
	 * Stays idempotent under racing cron spawns: a cooperative tick lock
	 * (provided by {@see WP_MCP_AI_Inline_Async_Tick_Trait}) guarantees
	 * that at most one tick body runs at a time for a given `$job_id`,
	 * even when the WP-Cron loopback fires concurrently with the
	 * inline-shutdown kick.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function handle_tick( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return;
		}

		$state = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return;
		}

		if ( in_array( $state['status'], array( 'cancelled', 'completed', 'failed' ), true ) ) {
			return;
		}

		// Cooperative lock against concurrent ticks. If another worker (a
		// delayed cron loopback, a parallel shutdown handler, etc.) is
		// already inside the critical section for this job, bail — that
		// worker will save fresh state when it exits and the next tick
		// can pick up from there.
		$lock_key = self::TICK_LOCK_PREFIX . $job_id;
		if ( ! self::inline_async_acquire_tick_lock( $lock_key, self::TICK_LOCK_CACHE_GROUP, self::TICK_LOCK_TTL ) ) {
			return;
		}

		$tick_started_at = time();
		$should_loop     = false;

		try {
			$should_loop = self::process_tick_body( $job_id );
		} finally {
			self::inline_async_release_tick_lock( $lock_key, self::TICK_LOCK_CACHE_GROUP );
		}

		// `DISABLE_WP_CRON` inline-loop branch: when WP-Cron is disabled,
		// re-scheduling the next tick alone is insufficient because the
		// scheduled event will never fire of its own accord. Recurse in
		// this same PHP process while we still have wall-clock budget.
		// The recursion re-enters handle_tick(), which acquires its own
		// lock and re-reads state, so the cancellation gate is honoured.
		if ( self::inline_async_should_loop( $tick_started_at, $should_loop, self::INLINE_LOOP_BUDGET_SECONDS ) ) {
			self::handle_tick( $job_id );
		}
	}

	/**
	 * Process exactly one row for the given job. Returns true when the
	 * queue still has work after this row (so the caller can decide
	 * whether to recurse inline under `DISABLE_WP_CRON`).
	 *
	 * Internal helper of {@see self::handle_tick()}; runs inside the
	 * cooperative tick lock.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Job identifier.
	 * @return bool Whether the queue still has work.
	 */
	private static function process_tick_body( $job_id ) {
		$state = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return false;
		}

		// Re-check the cancellation/completion gate after taking the
		// lock: another worker may have moved the job past these states
		// in the brief window between the outer check and lock acquire.
		if ( in_array( $state['status'], array( 'cancelled', 'completed', 'failed' ), true ) ) {
			return false;
		}

		// Mark running before pulling a row so concurrent ticks see the
		// updated state.
		$state['status']     = 'running';
		$state['updated_at'] = time();
		self::save_state( $state );

		// Pop a single row. `array_shift` returns null for an empty queue.
		$head = array_shift( $state['queue'] );
		if ( null === $head ) {
			$state['status']       = 'completed';
			$state['last_message'] = __( 'No more plan rows to apply.', 'nvoos-saas-controller' );
			$state['updated_at']   = time();
			self::save_state( $state );
			return false;
		}

		$section = isset( $head['section'] ) ? (string) $head['section'] : 'create';
		$row     = isset( $head['row'] ) && is_array( $head['row'] ) ? $head['row'] : array();

		$engine = self::build_engine();
		if ( is_wp_error( $engine ) ) {
			$state['status']       = 'failed';
			$state['last_message'] = $engine->get_error_message();
			$state['errors'][]     = $engine->get_error_message();
			$state['updated_at']   = time();
			self::save_state( $state );
			return false;
		}

		$result = $engine->apply_row( $row, $section );

		// Append + accumulate.
		$state['results'][] = $result;
		$status             = isset( $result['status'] ) ? (string) $result['status'] : 'error';
		if ( ! isset( $state['summary'][ $status ] ) ) {
			$state['summary'][ $status ] = 0;
		}
		++$state['summary'][ $status ];
		++$state['processed'];
		$state['last_message'] = isset( $result['message'] ) ? (string) $result['message'] : '';
		if ( 'error' === $status && isset( $result['message'] ) ) {
			$state['errors'][] = (string) $result['message'];
			// Internal storage cap (10): keeps the transient bounded but
			// retains a slightly larger forensic window than the public
			// projection exposes. The projection trims to the last 5
			// (see self::project()) so the admin UI stays compact while
			// `get_state()` still has the older entries available for
			// diagnostics.
			if ( count( $state['errors'] ) > 10 ) {
				$state['errors'] = array_slice( $state['errors'], -10 );
			}
		}
		$state['updated_at'] = time();

		if ( empty( $state['queue'] ) ) {
			$state['status'] = 'completed';
			self::save_state( $state );
			return false;
		}

		// Re-schedule next tick. Past timestamp by design — see the same
		// rationale in self::enqueue_plan() above.
		$tick_timestamp = time() - 1;
		wp_schedule_single_event( $tick_timestamp, self::CRON_HOOK, array( $job_id ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		self::save_state( $state );
		return true;
	}

	/**
	 * Cancel a queued or running job. Future ticks short-circuit; an
	 * already-firing tick will finish its current row before the
	 * cancelled status is observed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated state projection or error.
	 */
	public static function cancel( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$state  = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'apply_job_not_found', __( 'Apply job not found or expired.', 'nvoos-saas-controller' ), array( 'status' => 404 ) );
		}
		if ( in_array( $state['status'], array( 'completed', 'cancelled', 'failed' ), true ) ) {
			return self::project( $state );
		}

		$state['status']       = 'cancelled';
		$state['queue']        = array();
		$state['last_message'] = __( 'Cancelled by operator.', 'nvoos-saas-controller' );
		$state['updated_at']   = time();
		self::save_state( $state );

		if ( class_exists( 'NVOOS_SaaS_Controller_Audit_Log' ) ) {
			NVOOS_SaaS_Controller_Audit_Log::instance()->record(
				array(
					'channel' => 'internal',
					'action'  => 'apply_job_cancelled',
					'target'  => $job_id,
					'status'  => 'ok',
					'message' => sprintf(
						/* translators: 1: rows processed before cancel, 2: rows total. */
						__( 'Apply job cancelled at %1$d/%2$d rows.', 'nvoos-saas-controller' ),
						(int) $state['processed'],
						(int) $state['total']
					),
				)
			);
		}

		return self::project( $state );
	}

	/**
	 * Read the full state record for a job (queue included). Returns
	 * `null` when the transient is missing or expired.
	 *
	 * @since 0.1.0
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
	 * Public progress projection. Drops the internal queue (which can
	 * carry secrets via plan-row `value` fields like Stripe price unit
	 * amounts or OpenRouter labels) and exposes only the fields the
	 * admin UI needs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null
	 */
	public static function get_progress( $job_id ) {
		$state = self::get_state( $job_id );
		if ( ! is_array( $state ) ) {
			return null;
		}
		return self::project( $state );
	}

	/**
	 * Build the public projection for a state record.
	 *
	 * @since 0.1.0
	 *
	 * @param array $state Raw state record.
	 * @return array
	 */
	private static function project( array $state ) {
		$total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
		$processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
		$percent   = $total > 0 ? min( 100, (int) floor( ( $processed * 100 ) / $total ) ) : 0;

		return array(
			'id'           => isset( $state['id'] ) ? (string) $state['id'] : '',
			'status'       => isset( $state['status'] ) ? (string) $state['status'] : '',
			'total'        => $total,
			'processed'    => $processed,
			'percent'      => $percent,
			'summary'      => isset( $state['summary'] ) && is_array( $state['summary'] ) ? $state['summary'] : array(
				'ok'      => 0,
				'error'   => 0,
				'skipped' => 0,
			),
			'results'      => isset( $state['results'] ) && is_array( $state['results'] ) ? array_values( $state['results'] ) : array(),
			'errors'       => isset( $state['errors'] ) && is_array( $state['errors'] ) ? array_values( array_slice( $state['errors'], -5 ) ) : array(),
			'last_message' => isset( $state['last_message'] ) ? (string) $state['last_message'] : '',
			'created_at'   => isset( $state['created_at'] ) ? (int) $state['created_at'] : 0,
			'updated_at'   => isset( $state['updated_at'] ) ? (int) $state['updated_at'] : 0,
		);
	}

	/**
	 * Persist a state record to the transient store.
	 *
	 * @since 0.1.0
	 *
	 * @param array $state State record.
	 * @return void
	 */
	private static function save_state( array $state ) {
		$ttl = (int) apply_filters( 'nvoos_saas_controller_apply_job_state_ttl', self::STATE_TTL );
		if ( $ttl < 600 ) {
			$ttl = 600;
		}
		set_transient( self::STATE_PREFIX . $state['id'], $state, $ttl );
	}

	/**
	 * Flatten a plan into an ordered queue of `{ section, row }` tuples.
	 *
	 * Order is preserved exactly as the plan generator emitted it
	 * (creates first, then updates) so the background apply matches the
	 * synchronous apply byte-for-byte. Non-array rows are silently
	 * dropped — the same guard the synchronous engine already applies.
	 *
	 * @since 0.1.0
	 *
	 * @param array $plan Plan array.
	 * @return array
	 */
	private static function flatten_plan( array $plan ) {
		$queue = array();
		if ( isset( $plan['creates'] ) && is_array( $plan['creates'] ) ) {
			foreach ( $plan['creates'] as $row ) {
				if ( is_array( $row ) ) {
					$queue[] = array(
						'section' => 'create',
						'row'     => $row,
					);
				}
			}
		}
		if ( isset( $plan['updates'] ) && is_array( $plan['updates'] ) ) {
			foreach ( $plan['updates'] as $row ) {
				if ( is_array( $row ) ) {
					$queue[] = array(
						'section' => 'update',
						'row'     => $row,
					);
				}
			}
		}
		return $queue;
	}

	/**
	 * Build the apply engine instance the tick will use.
	 *
	 * Resolves the mutating Cloudflare client from the credential store
	 * (which can fail if the operator has rotated/cleared credentials
	 * between enqueue and the next tick) and best-effort-resolves the
	 * optional Stripe and OpenRouter clients exactly the way the
	 * synchronous `/apply/run` route does.
	 *
	 * Filterable via `nvoos_saas_controller_apply_job_engine` so tests
	 * can swap in a stub engine without monkey-patching the credential
	 * store.
	 *
	 * @since 0.1.0
	 *
	 * @return NVOOS_SaaS_Controller_Apply_Engine|WP_Error
	 */
	private static function build_engine() {
		/**
		 * Filter: short-circuit engine construction with a pre-built
		 * apply engine. Used by the test suite to inject stubs.
		 *
		 * @since 0.1.0
		 *
		 * @param NVOOS_SaaS_Controller_Apply_Engine|null $engine Pre-built engine, or null.
		 */
		$override = apply_filters( 'nvoos_saas_controller_apply_job_engine', null );
		if ( $override instanceof NVOOS_SaaS_Controller_Apply_Engine ) {
			return $override;
		}

		$account_override = null;
		if ( class_exists( 'NVOOS_SaaS_Controller_Deployment_Config' ) ) {
			$desired = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
			if ( ! empty( $desired['account_id'] ) ) {
				$account_override = (string) $desired['account_id'];
			}
		}

		$mutating = NVOOS_SaaS_Controller_Cloudflare_Mutating_Client::from_credential_store( $account_override );
		if ( is_wp_error( $mutating ) ) {
			return $mutating;
		}

		$stripe     = class_exists( 'NVOOS_SaaS_Controller_Stripe_Client' )
			? NVOOS_SaaS_Controller_Stripe_Client::from_credential_store()
			: null;
		$openrouter = class_exists( 'NVOOS_SaaS_Controller_OpenRouter_Client' )
			? NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store()
			: null;

		return new NVOOS_SaaS_Controller_Apply_Engine( $mutating, $stripe, $openrouter );
	}
}

NVOOS_SaaS_Controller_Apply_Job::bootstrap();
