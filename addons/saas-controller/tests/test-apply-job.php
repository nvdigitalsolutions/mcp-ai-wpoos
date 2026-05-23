<?php
/**
 * Tests for NVOOS_SaaS_Controller_Apply_Job (Phase 8).
 *
 * Exercises the queued background apply worker end-to-end without HTTP:
 * enqueueing flattens a plan into a single-row queue, ticks pop one row at
 * a time, and the projection drops the queue while exposing a usable
 * progress projection. Cloudflare interaction is stubbed via the same
 * `NVOOS_SaaS_Stub_Mutating_Client` shape used by `test-apply-engine.php`,
 * injected into the worker via the
 * `nvoos_saas_controller_apply_job_engine` filter.
 *
 * @package NV_oOS_SaaS_Controller
 */

if ( ! class_exists( 'NVOOS_SaaS_Stub_Mutating_Client' ) ) {
	require_once __DIR__ . '/test-apply-engine.php';
}

/**
 * Tests for the apply job background worker.
 *
 * @covers NVOOS_SaaS_Controller_Apply_Job
 */
class Test_NVOOS_SaaS_Controller_Apply_Job extends WP_UnitTestCase {

	/**
	 * Stub mutating client shared across all ticks in a single test.
	 *
	 * A test can inspect every Cloudflare call the worker made across the
	 * whole job.
	 *
	 * @var NVOOS_SaaS_Stub_Mutating_Client
	 */
	private $stub;

	/**
	 * The apply engine instance under test.
	 *
	 * @var NVOOS_SaaS_Controller_Apply_Engine
	 */
	private $engine;

	/**
	 * Tracks whether this test class created the placeholder
	 * `worker/dist/index.js` file so tearDown can reverse it.
	 *
	 * @var bool
	 */
	private $created_dist = false;

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		delete_option( NVOOS_SaaS_Controller_Apply_Engine::DEPLOYED_OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();

		$this->ensure_worker_dist();
		$this->stub   = new NVOOS_SaaS_Stub_Mutating_Client();
		$this->engine = new NVOOS_SaaS_Controller_Apply_Engine( $this->stub );

		add_filter( 'nvoos_saas_controller_apply_job_engine', array( $this, 'inject_engine' ) );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'nvoos_saas_controller_apply_job_engine', array( $this, 'inject_engine' ) );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		delete_option( NVOOS_SaaS_Controller_Apply_Engine::DEPLOYED_OPTION );
		$this->cleanup_worker_dist();
		parent::tearDown();
	}

	/**
	 * Filter callback used to inject the per-test stub engine.
	 *
	 * @return NVOOS_SaaS_Controller_Apply_Engine
	 */
	public function inject_engine() {
		return $this->engine;
	}

	/**
	 * Get the path to the worker dist file.
	 *
	 * @return string
	 */
	private function worker_dist_path() {
		return NVOOS_SAAS_CONTROLLER_PATH . 'worker/dist/index.js';
	}

	/**
	 * Ensure the worker dist file exists for testing.
	 *
	 * @return void
	 */
	private function ensure_worker_dist() {
		$path = $this->worker_dist_path();
		if ( file_exists( $path ) ) {
			$this->created_dist = false;
			return;
		}
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "export default { fetch() { return new Response('test'); } };\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		$this->created_dist = true;
	}

	/**
	 * Remove the worker dist file if this test created it.
	 *
	 * @return void
	 */
	private function cleanup_worker_dist() {
		if ( $this->created_dist ) {
			$path = $this->worker_dist_path();
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			$dir = dirname( $path );
			if ( is_dir( $dir ) && false === ( new \FilesystemIterator( $dir ) )->valid() ) {
				rmdir( $dir );
			}
			$this->created_dist = false;
		}
	}

	/**
	 * Build a plan fixture with two creates and one update.
	 *
	 * @return array
	 */
	private function plan_with_two_creates_and_one_update() {
		return array(
			'creates' => array(
				array(
					'kind'    => 'd1',
					'name'    => 'mcp-oos',
					'binding' => 'DB',
				),
				array(
					'kind'    => 'kv',
					'title'   => 'cache',
					'binding' => 'KV',
				),
			),
			'updates' => array(
				array(
					'kind' => 'worker',
					'name' => 'mcp-oos-worker',
				),
			),
			'noops'   => array(),
			'orphans' => array(),
			'errors'  => array(),
			'summary' => array(
				'creates' => 2,
				'updates' => 1,
				'noops'   => 0,
				'orphans' => 0,
				'errors'  => 0,
			),
		);
	}

	/**
	 * Test that enqueue_plan returns queued state and projection omits queue.
	 *
	 * @return void
	 */
	public function test_enqueue_plan_returns_queued_state_and_projection_omits_queue() {
		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );

		$this->assertIsArray( $state );
		$this->assertSame( 'queued', $state['status'] );
		$this->assertSame( 3, $state['total'] );
		$this->assertSame( 0, $state['processed'] );
		$this->assertSame( 0, $state['percent'] );
		$this->assertSame( array(), $state['results'] );
		$this->assertArrayNotHasKey( 'queue', $state, 'Projection must not leak the internal queue.' );
		$this->assertNotEmpty( $state['id'] );

		// The transient must hold the full state including the queue.
		$raw = NVOOS_SaaS_Controller_Apply_Job::get_state( $state['id'] );
		$this->assertIsArray( $raw );
		$this->assertCount( 3, $raw['queue'] );
	}

	/**
	 * Test that enqueue_plan rejects an empty plan.
	 *
	 * @return void
	 */
	public function test_enqueue_plan_rejects_empty_plan() {
		$result = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan(
			array(
				'creates' => array(),
				'updates' => array(),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'empty_apply_plan', $result->get_error_code() );
	}

	/**
	 * Test that handle_tick processes one row and updates progress.
	 *
	 * @return void
	 */
	public function test_handle_tick_processes_one_row_and_updates_progress() {
		$state  = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		$job_id = $state['id'];

		// Tick 1: D1 create.
		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );
		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $job_id );
		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 1, $progress['processed'] );
		$this->assertCount( 1, $progress['results'] );
		$this->assertSame( 'd1', $progress['results'][0]['kind'] );
		$this->assertSame( 'ok', $progress['results'][0]['status'] );
		$this->assertSame( 1, $progress['summary']['ok'] );

		// Tick 2: KV create.
		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );
		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $job_id );
		$this->assertSame( 2, $progress['processed'] );
		$this->assertSame( 'kv', $progress['results'][1]['kind'] );

		// Tick 3: Worker update — should mark completed.
		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );
		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $job_id );
		$this->assertSame( 'completed', $progress['status'] );
		$this->assertSame( 3, $progress['processed'] );
		$this->assertSame( 100, $progress['percent'] );
		$this->assertCount( 3, $progress['results'] );
		$this->assertSame( 'worker', $progress['results'][2]['kind'] );
		$this->assertSame( 3, $progress['summary']['ok'] );

		// Stub should have observed exactly the three calls in plan order.
		$this->assertCount( 3, $this->stub->calls );
		$this->assertSame( 'd1', $this->stub->calls[0][0] );
		$this->assertSame( 'kv', $this->stub->calls[1][0] );
		$this->assertSame( 'worker', $this->stub->calls[2][0] );
	}

	/**
	 * Test that handle_tick records partial failure without aborting queue.
	 *
	 * @return void
	 */
	public function test_handle_tick_records_partial_failure_without_aborting_queue() {
		// Make D1 fail; KV + Worker should still proceed.
		$this->stub->next_d1 = new WP_Error( 'cf_boom', 'Cloudflare 500' );

		$state  = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		$job_id = $state['id'];

		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );
		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );
		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );

		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $job_id );
		$this->assertSame( 'completed', $progress['status'] );
		$this->assertSame( 1, $progress['summary']['error'] );
		$this->assertSame( 2, $progress['summary']['ok'] );
		$this->assertNotEmpty( $progress['errors'] );
		$this->assertStringContainsString( 'Cloudflare 500', $progress['errors'][0] );
	}

	/**
	 * Test that cancel drains queue and short-circuits future ticks.
	 *
	 * @return void
	 */
	public function test_cancel_drains_queue_and_short_circuits_future_ticks() {
		$state  = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		$job_id = $state['id'];

		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );

		$cancelled = NVOOS_SaaS_Controller_Apply_Job::cancel( $job_id );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 1, $cancelled['processed'] );

		// Subsequent ticks must be no-ops.
		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );
		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $job_id );
		$this->assertSame( 'cancelled', $progress['status'] );
		$this->assertSame( 1, $progress['processed'] );
		$this->assertCount( 1, $this->stub->calls, 'Cancellation must stop further Cloudflare calls.' );
	}

	/**
	 * Test that cancel of an unknown job returns WP_Error.
	 *
	 * @return void
	 */
	public function test_cancel_unknown_job_returns_wp_error() {
		$result = NVOOS_SaaS_Controller_Apply_Job::cancel( 'no-such-job' );
		$this->assertWPError( $result );
		$this->assertSame( 'apply_job_not_found', $result->get_error_code() );
	}

	/**
	 * Test that get_progress of an unknown job returns null.
	 *
	 * @return void
	 */
	public function test_get_progress_unknown_job_returns_null() {
		$this->assertNull( NVOOS_SaaS_Controller_Apply_Job::get_progress( 'never-existed' ) );
	}

	/**
	 * Test that enqueue_from_token consumes token and creates a job.
	 *
	 * @return void
	 */
	public function test_enqueue_from_token_consumes_token_and_creates_job() {
		$plan   = $this->plan_with_two_creates_and_one_update();
		$issued = NVOOS_SaaS_Controller_Apply_Engine::issue_token( $plan );

		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_from_token( $issued['token'] );
		$this->assertIsArray( $state );
		$this->assertSame( 'queued', $state['status'] );

		// Token must now be single-use exhausted.
		$second = NVOOS_SaaS_Controller_Apply_Job::enqueue_from_token( $issued['token'] );
		$this->assertWPError( $second );
		$this->assertSame( 'consumed_apply_token', $second->get_error_code() );
	}

	/**
	 * Test that enqueue and cancel both record audit entries.
	 *
	 * @return void
	 */
	public function test_enqueue_audits_and_cancel_audits() {
		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		NVOOS_SaaS_Controller_Apply_Job::cancel( $state['id'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 50 );
		$actions = array_column( $entries, 'action' );
		$this->assertContains( 'apply_job_enqueued', $actions );
		$this->assertContains( 'apply_job_cancelled', $actions );
	}

	/**
	 * The inline-shutdown kick should drive a `queued` job to make
	 * progress in the same PHP process, without waiting for the WP-Cron
	 * loopback. Mirrors the equivalent test in the base plugin's
	 * Mine Memories suite. The kick is wired by `enqueue_plan()` as a
	 * `shutdown` action; firing it via `do_action('shutdown')` simulates
	 * the end of the REST request.
	 */
	public function test_inline_shutdown_kick_advances_queued_job() {
		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );

		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $state['id'] );
		$this->assertSame( 'queued', $progress['status'] );

		// Capture the observability action so we can assert it fires
		// with the documented `( $class, $job_id, $duration_ms, $success )`
		// payload — same shape as Mine Memories and the Tool Async
		// Executor so Pro OTel subscribers receive uniform telemetry.
		$captured = array();
		$listener = static function ( $class, $job_id, $duration_ms, $success ) use ( &$captured ) {
			$captured[] = array(
				'class'       => $class,
				'job_id'      => $job_id,
				'duration_ms' => $duration_ms,
				'success'     => $success,
			);
		};
		add_action( 'wp_mcp_ai_inline_kick_completed', $listener, 10, 4 );

		try {
			do_action( 'shutdown' );
		} finally {
			remove_action( 'wp_mcp_ai_inline_kick_completed', $listener, 10 );
		}

		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $state['id'] );
		$this->assertNotSame( 'queued', $progress['status'], 'inline shutdown kick should have advanced the job past queued' );
		$this->assertGreaterThanOrEqual( 1, $progress['processed'], 'inline shutdown kick should have processed at least one row' );

		$this->assertNotEmpty( $captured, 'wp_mcp_ai_inline_kick_completed must fire for SaaS Apply' );
		$this->assertSame( 'NVOOS_SaaS_Controller_Apply_Job', $captured[0]['class'] );
		$this->assertSame( $state['id'], $captured[0]['job_id'] );
		$this->assertTrue( $captured[0]['success'] );
	}

	/**
	 * `kick_inline()` must short-circuit on terminal job statuses
	 * (cancelled / completed / failed) so the audit-log and engine
	 * stubs are never touched after the job is done.
	 */
	public function test_kick_inline_short_circuits_on_terminal_status() {
		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		NVOOS_SaaS_Controller_Apply_Job::cancel( $state['id'] );

		$calls_before = count( $this->stub->calls );
		NVOOS_SaaS_Controller_Apply_Job::kick_inline( $state['id'] );
		$this->assertCount( $calls_before, $this->stub->calls, 'kick_inline must not touch the engine after cancel' );

		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $state['id'] );
		$this->assertSame( 'cancelled', $progress['status'] );
	}

	/**
	 * The shared `wp_mcp_ai_inline_kick_enabled` filter must disable the
	 * shutdown registration. Operators rely on this escape hatch to
	 * debug hosts where `fastcgi_finish_request()` interacts badly with
	 * another plugin.
	 */
	public function test_inline_kick_enabled_filter_disables_shutdown_registration() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
		try {
			$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );

			do_action( 'shutdown' );

			$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $state['id'] );
			$this->assertSame( 'queued', $progress['status'], 'with the filter disabled the inline kick must not advance the job' );
			$this->assertSame( 0, $progress['processed'] );
		} finally {
			remove_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
		}
	}

	/**
	 * The cooperative tick lock guarantees that re-entering `handle_tick`
	 * while a prior tick is mid-flight (e.g. a delayed cron loopback
	 * firing concurrently with the inline-shutdown kick) is a no-op.
	 * Simulated here by pre-acquiring the lock and then calling
	 * `handle_tick` — it should leave state untouched.
	 */
	public function test_handle_tick_no_ops_when_lock_held() {
		$state  = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		$job_id = $state['id'];

		$lock_key = NVOOS_SaaS_Controller_Apply_Job::TICK_LOCK_PREFIX . $job_id;
		set_transient( $lock_key, 1, NVOOS_SaaS_Controller_Apply_Job::TICK_LOCK_TTL );
		if ( function_exists( 'wp_cache_add' ) ) {
			wp_cache_add( $lock_key, 1, NVOOS_SaaS_Controller_Apply_Job::TICK_LOCK_CACHE_GROUP, NVOOS_SaaS_Controller_Apply_Job::TICK_LOCK_TTL );
		}

		NVOOS_SaaS_Controller_Apply_Job::handle_tick( $job_id );

		$progress = NVOOS_SaaS_Controller_Apply_Job::get_progress( $job_id );
		$this->assertSame( 'queued', $progress['status'], 'handle_tick must no-op while another worker holds the lock' );
		$this->assertSame( 0, $progress['processed'] );
		$this->assertCount( 0, $this->stub->calls, 'engine must not be invoked while the lock is held' );

		// Cleanup so the global tearDown does not see a leaked lock.
		delete_transient( $lock_key );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $lock_key, NVOOS_SaaS_Controller_Apply_Job::TICK_LOCK_CACHE_GROUP );
		}
	}
}
