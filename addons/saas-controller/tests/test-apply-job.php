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
 * @covers NVOOS_SaaS_Controller_Apply_Job
 */
class Test_NVOOS_SaaS_Controller_Apply_Job extends WP_UnitTestCase {

	/**
	 * Stub mutating client shared across all ticks in a single test, so a
	 * test can inspect every Cloudflare call the worker made across the
	 * whole job.
	 *
	 * @var NVOOS_SaaS_Stub_Mutating_Client
	 */
	private $stub;

	/**
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

	private function worker_dist_path() {
		return NVOOS_SAAS_CONTROLLER_PATH . 'worker/dist/index.js';
	}

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

	public function test_cancel_unknown_job_returns_wp_error() {
		$result = NVOOS_SaaS_Controller_Apply_Job::cancel( 'no-such-job' );
		$this->assertWPError( $result );
		$this->assertSame( 'apply_job_not_found', $result->get_error_code() );
	}

	public function test_get_progress_unknown_job_returns_null() {
		$this->assertNull( NVOOS_SaaS_Controller_Apply_Job::get_progress( 'never-existed' ) );
	}

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

	public function test_enqueue_audits_and_cancel_audits() {
		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_plan( $this->plan_with_two_creates_and_one_update() );
		NVOOS_SaaS_Controller_Apply_Job::cancel( $state['id'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 50 );
		$actions = array_column( $entries, 'action' );
		$this->assertContains( 'apply_job_enqueued', $actions );
		$this->assertContains( 'apply_job_cancelled', $actions );
	}
}
