<?php
/**
 * tests/test-create-cron-job-tool.php
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';

/**
 * Tests for the create cron job tool.
 */
class WP_MCP_AI_Create_Cron_Job_Tool_Test extends WP_UnitTestCase {
	/**
	 * Reset the cron array before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
	}

	/**
	 * Clean up cron events and current user after each test.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Ensure users without the required capability cannot create cron jobs.
	 */
	public function test_execute_requires_manage_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook' => 'wp_mcp_ai_subscriber_event',
			),
			array(
				'user_id' => $subscriber_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure a valid hook is required when scheduling.
	 */
	public function test_execute_requires_valid_hook() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_hook', $result->get_error_code() );
	}

	/**
	 * Ensure timestamps in the past are rejected.
	 */
	public function test_execute_rejects_past_timestamp() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_past_event',
				'timestamp' => time() - HOUR_IN_SECONDS,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_past_timestamp', $result->get_error_code() );
	}

	/**
	 * Ensure a single event is scheduled correctly and normalises associative arguments.
	 */
	public function test_execute_schedules_single_event() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_single_event';
		$args     = array( 'message' => 'ping' );
		$future   = time() + 5 * MINUTE_IN_SECONDS;

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'schedule'  => 'single',
				'args'      => $args,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $hook, $result['hook'] );
		$this->assertSame( 'single', $result['schedule'] );
		$this->assertSame( $future, $result['timestamp'] );
		$this->assertSame( array( $args ), $result['args'] );
		$this->assertNotEmpty( $result['scheduled_for'] );

		$scheduled_time = wp_next_scheduled( $hook, array( $args ) );
		$this->assertSame( $future, $scheduled_time );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );
		$job = array_shift( $jobs );
		$this->assertSame( $hook, $job['hook'] );
		$this->assertSame( array( $args ), $job['args'] );
		$this->assertSame( 'single', $job['schedule'] );
	}

	/**
	 * Ensure missing timestamps default to two minutes in the future.
	 */
	public function test_execute_defaults_timestamp_when_missing() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_default_timestamp';
		$before   = time();

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook' => $hook,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $hook, $result['hook'] );
		$this->assertSame( 'single', $result['schedule'] );
		$this->assertGreaterThanOrEqual( $before + 20, $result['timestamp'] );

		$next_run = wp_next_scheduled( $hook, array() );
		$this->assertNotFalse( $next_run );
		$this->assertGreaterThanOrEqual( $before + 20, $next_run );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );
	}

	/**
	 * Ensure duplicate events with the same hook and arguments are blocked.
	 */
	public function test_execute_prevents_duplicate_events() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_duplicate_event';
		$future   = time() + 10 * MINUTE_IN_SECONDS;
		$args     = array( 'first-arg' );

		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		$first = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'args'      => $args,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $first );

		$second = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future + MINUTE_IN_SECONDS,
				'args'      => $args,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $second );
		$this->assertSame( 'wp_mcp_ai_event_exists', $second->get_error_code() );
	}

	/**
	 * Ensure positional arguments are preserved without re-indexing.
	 */
	public function test_execute_preserves_positional_arguments() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_positional_args';
		$args     = array( 'one', 'two' );
		$future   = time() + HOUR_IN_SECONDS;

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'args'      => $args,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $args, $result['args'] );

		$scheduled_time = wp_next_scheduled( $hook, $args );
		$this->assertSame( $future, $scheduled_time );
	}

	/**
	 * Ensure invalid schedules are rejected with a clear error.
	 */
	public function test_execute_rejects_invalid_schedule() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'     => 'wp_mcp_ai_invalid_schedule_event',
				'schedule' => 'not-a-real-schedule',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_schedule', $result->get_error_code() );
	}

	/**
	 * Ensure invalid argument types are rejected.
	 */
	public function test_execute_rejects_non_array_arguments() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook' => 'wp_mcp_ai_invalid_args',
				'args' => 'nope',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_args', $result->get_error_code() );
	}

	/**
	 * Ensure recurring events can be scheduled when using a valid schedule.
	 */
	public function test_execute_schedules_recurring_event() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_recurring_event';
		$future   = time() + 5 * MINUTE_IN_SECONDS;

		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'schedule'  => 'hourly',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'hourly', $result['schedule'] );
		$this->assertSame( $hook, $result['hook'] );

		$scheduled_time = wp_next_scheduled( $hook );
		$this->assertSame( $future, $scheduled_time );
	}
}
