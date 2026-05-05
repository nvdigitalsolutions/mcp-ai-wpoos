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

/**
 * Apply background-job worker.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Apply_Job {

	/**
	 * Cron hook for tick processing. Filterable via `cron_schedules` is
	 * not needed — every tick is a single-event reschedule, never a
	 * recurring schedule.
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

		// Schedule the first tick 1s in the past so spawn_cron() picks it up.
		$tick_timestamp = time() - 1;
		wp_schedule_single_event( $tick_timestamp, self::CRON_HOOK, array( $job_id ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return self::project( $state );
	}

	/**
	 * Cron callback. Pops one row off the queue, dispatches it through
	 * the apply engine, persists the result, and re-schedules until the
	 * queue is drained.
	 *
	 * Stays idempotent under racing cron spawns: the very first thing it
	 * does is mark `status=running` and rewrite the state, so a second
	 * concurrent tick with the same `$job_id` reads the updated queue
	 * (which already had its head popped) on its next read.
	 *
	 * @since 0.1.0
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
			return;
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
			return;
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
			// Cap the error log to keep the transient bounded.
			if ( count( $state['errors'] ) > 10 ) {
				$state['errors'] = array_slice( $state['errors'], -10 );
			}
		}
		$state['updated_at'] = time();

		if ( empty( $state['queue'] ) ) {
			$state['status'] = 'completed';
			self::save_state( $state );
			return;
		}

		// Re-schedule next tick.
		$tick_timestamp = time() - 1;
		wp_schedule_single_event( $tick_timestamp, self::CRON_HOOK, array( $job_id ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		self::save_state( $state );
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
