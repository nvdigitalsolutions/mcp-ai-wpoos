<?php
/**
 * Tests for the schedule REST trigger + toggle endpoints.
 *
 * These two routes are called from the browser by the Pro SPA, so they inherit
 * the same failure modes as the classic admin-ajax buttons:
 *
 *   - POST /mcp-ai-pro/v1/schedules/{id}/trigger must *queue* a background run
 *     and return a run ID immediately. Executing inline held the HTTP request
 *     open past Cloudflare's ~100 s edge timeout, which surfaced as an HTML 524
 *     (not JSON) while the PHP worker kept running invisibly.
 *   - POST /mcp-ai-pro/v1/schedules/{id}/toggle previously forwarded a single
 *     argument to a three-argument `toggle_schedule()`; `$enabled` was null,
 *     `isset()` in `update_schedule()` returned false, and the endpoint
 *     silently did nothing. It now flips the stored state when no body is
 *     sent, matching what the SPA's Enable/Disable button expects.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_REST_Trigger_Toggle.
 */
class Test_Pro_Schedule_REST_Trigger_Toggle extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Reset storage and auth between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not available.' );
		}

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );

		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::delete( 'pro_schedules' );
			WP_MCP_AI_Cache_Helper::delete( 'pro_schedule_history' );
		}

		$this->register_controller_routes();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Make sure the schedule routes exist on the current REST server.
	 *
	 * Other suites in the same process may already have hooked the controller
	 * onto `rest_api_init`; registering the routes directly (and only when they
	 * are absent) keeps this file independent of discovery order.
	 */
	private function register_controller_routes() {
		$server = rest_get_server();

		foreach ( array_keys( $server->get_routes() ) as $pattern ) {
			if ( false !== strpos( $pattern, '/schedules/' ) && false !== strpos( $pattern, '/trigger' ) ) {
				return;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_REST_Controller' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-schedule-rest-controller.php';
		}

		( new WP_MCP_AI_Pro_Schedule_REST_Controller() )->register_routes();
	}

	/**
	 * Create an enabled task schedule bound to a unique hook.
	 *
	 * @param bool $enabled Whether the schedule starts enabled.
	 * @return array{0:string,1:string} Schedule ID and hook name.
	 */
	private function make_task_schedule( $enabled = true ) {
		$hook = 'wp_mcp_ai_rest_trigger_' . uniqid();

		$schedule_id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => $hook,
				'schedule'      => 'single',
				'timestamp'     => time() + 3600,
				'enabled'       => $enabled,
			),
			$this->admin_id
		);

		$this->assertIsString( $schedule_id, 'Fixture schedule could not be created.' );

		return array( $schedule_id, $hook );
	}

	/**
	 * Dispatch a REST request against the schedule namespace.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path after the namespace.
	 * @param array  $params Request parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch_rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/mcp-ai-pro/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Trigger
	// -------------------------------------------------------------------------

	/**
	 * Non-admins cannot trigger a schedule.
	 */
	public function test_trigger_requires_manage_options() {
		list( $schedule_id ) = $this->make_task_schedule();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/trigger' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * The trigger is a queue acknowledgement, not an execution.
	 *
	 * Regression guard for the gateway-timeout bug: a provider round trip
	 * inside the request outlives Cloudflare's read timeout and returns HTML
	 * 524 instead of JSON.
	 */
	public function test_trigger_queues_a_run_instead_of_executing_it() {
		$fired                      = 0;
		list( $schedule_id, $hook ) = $this->make_task_schedule();

		add_action(
			$hook,
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$response = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/trigger' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertTrue( $data['queued'] );
		$this->assertSame( $schedule_id, $data['schedule_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $data['run_id'] );
		$this->assertContains( $data['backend'], array( 'action-scheduler', 'wp-cron' ) );
		$this->assertNotEmpty( $data['message'] );

		$this->assertSame( 0, $fired, 'The trigger endpoint must queue the run, never execute it inline.' );

		$run = WP_MCP_AI_Pro_Schedule_Manager::get_run( $schedule_id, $data['run_id'] );

		$this->assertIsArray( $run, 'The queue acknowledgement must be backed by a pending run record.' );
		$this->assertSame( 'pending', $run['status'] );
	}

	/**
	 * Unknown schedule IDs are a 404, not a 500.
	 */
	public function test_trigger_rejects_unknown_schedule() {
		$response = $this->dispatch_rest( 'POST', '/schedules/nosuchschedule/trigger' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'] );
	}

	/**
	 * Disabled schedules are refused instead of silently no-op'ing.
	 */
	public function test_trigger_rejects_disabled_schedule() {
		list( $schedule_id ) = $this->make_task_schedule( false );

		$response = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/trigger' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'schedule_disabled', $response->get_data()['code'] );
	}

	/**
	 * A second trigger is refused while the first run is still open.
	 */
	public function test_trigger_refuses_a_concurrent_run() {
		list( $schedule_id ) = $this->make_task_schedule();

		$first = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/trigger' );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/trigger' );

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'schedule_already_running', $second->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// Toggle
	// -------------------------------------------------------------------------

	/**
	 * Posting no body flips the stored state (the SPA sends no body).
	 */
	public function test_toggle_without_body_flips_state() {
		list( $schedule_id ) = $this->make_task_schedule( true );

		$response = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/toggle' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );

		$stored = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$this->assertEmpty( $stored['enabled'], 'The flip must persist, not just echo a response.' );

		// A second flip restores the original state.
		$again = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/toggle' );
		$this->assertTrue( $again->get_data()['enabled'] );

		$stored = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$this->assertNotEmpty( $stored['enabled'] );
	}

	/**
	 * An explicit false disables an enabled schedule.
	 */
	public function test_toggle_with_explicit_false_disables() {
		list( $schedule_id ) = $this->make_task_schedule( true );

		$response = $this->dispatch_rest(
			'POST',
			'/schedules/' . $schedule_id . '/toggle',
			array( 'enabled' => false )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );
	}

	/**
	 * An explicit true enables a disabled schedule.
	 */
	public function test_toggle_with_explicit_true_enables() {
		list( $schedule_id ) = $this->make_task_schedule( false );

		$response = $this->dispatch_rest(
			'POST',
			'/schedules/' . $schedule_id . '/toggle',
			array( 'enabled' => true )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['enabled'] );

		$stored = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$this->assertNotEmpty( $stored['enabled'] );
	}

	/**
	 * Unknown schedule IDs are a 404, not a silent no-op.
	 */
	public function test_toggle_rejects_unknown_schedule() {
		$response = $this->dispatch_rest( 'POST', '/schedules/nosuchschedule/toggle' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'] );
	}

	/**
	 * Non-admins cannot toggle a schedule.
	 */
	public function test_toggle_requires_manage_options() {
		list( $schedule_id ) = $this->make_task_schedule( true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_rest( 'POST', '/schedules/' . $schedule_id . '/toggle' );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Run status (the poll half of the async trigger)
	// -------------------------------------------------------------------------

	/**
	 * The run id of the newest recorded run.
	 *
	 * `record_run()` is protected and the trigger does not hand its run id
	 * back to a synchronous caller, so read it from the history the manager
	 * just wrote.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return string Run id, or an empty string when nothing was recorded.
	 */
	private function newest_run_id( $schedule_id ) {
		$history = get_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION, array() );
		$newest  = end( $history[ $schedule_id ] );

		return isset( $newest['run_id'] ) ? (string) $newest['run_id'] : '';
	}

	/**
	 * An in-flight run reports `pending` and `finished: false`.
	 */
	public function test_run_status_reports_an_open_run() {
		list( $schedule_id ) = $this->make_task_schedule();

		$run_id = WP_MCP_AI_Pro_Schedule_Manager::begin_run( $schedule_id, $this->admin_id, 'manual' );

		$response = $this->dispatch_rest( 'GET', '/schedules/' . $schedule_id . '/runs/' . $run_id );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertSame( $run_id, $data['run_id'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertFalse( $data['finished'] );
	}

	/**
	 * A completed run reports `finished: true` and its duration.
	 */
	public function test_run_status_reports_a_finished_run() {
		list( $schedule_id ) = $this->make_task_schedule();

		$this->assertTrue( WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $schedule_id, $this->admin_id ) );

		$run_id = $this->newest_run_id( $schedule_id );
		$this->assertNotEmpty( $run_id, 'The synchronous trigger must record a run.' );

		$data = $this->dispatch_rest( 'GET', '/schedules/' . $schedule_id . '/runs/' . $run_id )->get_data();

		$this->assertSame( 'success', $data['status'] );
		$this->assertTrue( $data['finished'] );
	}

	/**
	 * An unknown run id is a 404 the client can stop on.
	 */
	public function test_run_status_is_a_404_for_an_unknown_run() {
		list( $schedule_id ) = $this->make_task_schedule();

		$response = $this->dispatch_rest( 'GET', '/schedules/' . $schedule_id . '/runs/deadbeefcafe' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'run_not_found', $response->get_data()['code'] );
	}

	/**
	 * A run id is scoped to the schedule it belongs to.
	 */
	public function test_run_status_is_scoped_to_its_schedule() {
		list( $first )  = $this->make_task_schedule();
		list( $second ) = $this->make_task_schedule();

		$run_id = WP_MCP_AI_Pro_Schedule_Manager::begin_run( $first, $this->admin_id, 'manual' );

		$response = $this->dispatch_rest( 'GET', '/schedules/' . $second . '/runs/' . $run_id );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A worker that died hard is reaped on read, so the poller terminates.
	 */
	public function test_run_status_reaps_a_stale_run() {
		list( $schedule_id ) = $this->make_task_schedule();

		$run_id = WP_MCP_AI_Pro_Schedule_Manager::begin_run( $schedule_id, $this->admin_id, 'manual' );

		$history = get_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION, array() );
		$last    = count( $history[ $schedule_id ] ) - 1;

		$history[ $schedule_id ][ $last ]['start_time'] = time() - ( WP_MCP_AI_Pro_Schedule_Manager::RUN_STALE_AFTER + 60 );
		update_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION, $history );

		// load_history() caches for 60 s; drop the entry so the reaper sees the
		// back-dated timestamp rather than the fresh one it cached.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::delete( 'pro_schedule_history' );
		}

		$data = $this->dispatch_rest( 'GET', '/schedules/' . $schedule_id . '/runs/' . $run_id )->get_data();

		$this->assertSame( 'timeout', $data['status'] );
		$this->assertTrue( $data['finished'] );
	}
}
