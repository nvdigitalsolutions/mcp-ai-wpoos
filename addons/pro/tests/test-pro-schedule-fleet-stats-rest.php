<?php
/**
 * Tests for the schedule fleet-stats REST endpoint.
 *
 * Covers permission gating and the aggregate math in
 * WP_MCP_AI_Pro_Schedule_Manager::get_fleet_stats() — success rate,
 * 24 h failure window, overdue detection (2x-median-gap rule) and the
 * last-run timestamp — plus the registration-order discipline that keeps
 * the literal /schedules/fleet-stats route ahead of the (?P<id>...) pattern.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Fleet_Stats_REST.
 */
class Test_Pro_Schedule_Fleet_Stats_REST extends WP_UnitTestCase {

	/**
	 * Skip when the manager is unavailable; register the CRUD controller.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not available.' );
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-schedule-rest-controller.php';
		WP_MCP_AI_Pro_Schedule_REST_Controller::init();
	}

	/**
	 * Reset storage and auth between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::delete( 'pro_schedules' );
			WP_MCP_AI_Cache_Helper::delete( 'pro_schedule_history' );
		}

		// Ensure routes are registered.
		do_action( 'rest_api_init' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	/**
	 * Seed schedules + history with a known aggregate shape:
	 * - 2 schedules (1 enabled, 1 disabled)
	 * - 5 runs: 3 success, 2 failure; 1 failure inside 24 h, 1 old
	 * - Schedule B's 2 runs form an overdue series (gap 1 000 s, last run
	 *   99 000 s ago => 99 000 > 2 000).
	 *
	 * @return void
	 */
	protected function seed_fleet() {
		$now = time();

		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array(
				'fleet_a' => array(
					'id'      => 'fleet_a',
					'name'    => 'Fleet A',
					'enabled' => true,
				),
				'fleet_b' => array(
					'id'      => 'fleet_b',
					'name'    => 'Fleet B',
					'enabled' => false,
				),
			)
		);

		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION,
			array(
				'fleet_a' => array(
					array(
						'status'     => 'success',
						'start_time' => $now - 300,
						'duration'   => 1.2,
						'error'      => '',
						'action_log' => array(),
					),
					array(
						'status'     => 'failure',
						'start_time' => $now - 100,
						'duration'   => 0.4,
						'error'      => 'boom',
						'action_log' => array(),
					),
					array(
						'status'     => 'failure',
						'start_time' => $now - ( 3 * DAY_IN_SECONDS ),
						'duration'   => 0.4,
						'error'      => 'old boom',
						'action_log' => array(),
					),
				),
				'fleet_b' => array(
					array(
						'status'     => 'success',
						'start_time' => $now - 100000,
						'duration'   => 0.5,
						'error'      => '',
						'action_log' => array(),
					),
					array(
						'status'     => 'success',
						'start_time' => $now - 99000,
						'duration'   => 0.5,
						'error'      => '',
						'action_log' => array(),
					),
				),
			)
		);
	}

	/**
	 * Unauthenticated requests are refused.
	 */
	public function test_unauth_blocked() {
		$this->seed_fleet();
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/fleet-stats' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertTrue( in_array( $response->get_status(), array( 401, 403 ), true ), 'should refuse unauth' );
	}

	/**
	 * Authenticated admin receives the computed aggregates.
	 */
	public function test_admin_gets_fleet_stats() {
		$this->seed_fleet();

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/fleet-stats' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data  = $response->get_data();
		$stats = $data['stats'];

		$this->assertTrue( $data['ok'] );
		$this->assertSame( 2, $stats['total_schedules'] );
		$this->assertSame( 1, $stats['enabled_schedules'] );
		$this->assertSame( 5, $stats['total_runs'] );
		$this->assertSame( 3, $stats['success_runs'] );
		$this->assertSame( 2, $stats['failure_runs'] );
		$this->assertSame( 60, $stats['success_rate'] );
		$this->assertSame( 1, $stats['failures_24h'] );
		$this->assertSame( 1, $stats['overdue_schedules'] );
		$this->assertSame( time() - 100, $stats['last_run'] );
	}

	/**
	 * An empty fleet returns zeroed aggregates and a null last run.
	 */
	public function test_empty_fleet_is_zeroed() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/fleet-stats' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$stats = $response->get_data()['stats'];
		$this->assertSame( 0, $stats['total_schedules'] );
		$this->assertSame( 0, $stats['total_runs'] );
		$this->assertSame( 0, $stats['success_rate'] );
		$this->assertNull( $stats['last_run'] );
	}

	/**
	 * A single-run schedule is never flagged overdue (needs >= 2 samples).
	 */
	public function test_single_run_is_not_overdue() {
		$now = time();
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array(
				'solo' => array(
					'id'      => 'solo',
					'name'    => 'Solo',
					'enabled' => true,
				),
			)
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION,
			array(
				'solo' => array(
					array(
						'status'     => 'success',
						'start_time' => $now - 500000,
						'duration'   => 1.0,
						'error'      => '',
						'action_log' => array(),
					),
				),
			)
		);

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/fleet-stats' );
		$response = rest_get_server()->dispatch( $request );
		$stats    = $response->get_data()['stats'];

		$this->assertSame( 0, $stats['overdue_schedules'] );
		$this->assertSame( 100, $stats['success_rate'] );
	}
}
