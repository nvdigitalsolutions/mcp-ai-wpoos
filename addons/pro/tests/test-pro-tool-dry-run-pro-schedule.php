<?php
/**
 * Tests for the dry_run_pro_schedule tool.
 *
 * Validates the read-only inspector returns a structured preview without
 * mutating any persistent state.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule' )
	&& defined( 'WP_MCP_AI_PRO_PATH' )
	&& file_exists( WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-dry-run-pro-schedule.php' )
) {
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-dry-run-pro-schedule.php';
}

/**
 * Class Test_Pro_Tool_Dry_Run_Pro_Schedule
 */
class Test_Pro_Tool_Dry_Run_Pro_Schedule extends WP_UnitTestCase {

	/**
	 * Skip the suite when the Pro addon isn't loaded.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule' ) ) {
			$this->markTestSkipped( 'Pro addon not active.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'Pro Schedule Manager not available.' );
		}
	}

	/**
	 * The slug and capability flags match the existing read-only tool family.
	 */
	public function test_metadata_marks_tool_as_read_only() {
		$tool = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();

		$this->assertSame( 'dry_run_pro_schedule', $tool->get_slug() );
		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
	}

	/**
	 * Non-admin users are refused.
	 */
	public function test_execute_denies_non_admin() {
		$tool   = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();
		$result = $tool->execute( array( 'schedule_id' => 'whatever' ), array( 'user_id' => 0 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Missing schedule_id is reported instead of throwing.
	 */
	public function test_execute_requires_schedule_id() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$tool  = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();

		$result = $tool->execute( array(), array( 'user_id' => $admin ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_schedule_id', $result->get_error_code() );
	}

	/**
	 * Unknown schedule_ids surface a clean error code.
	 */
	public function test_execute_reports_missing_schedule() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$tool  = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();

		$result = $tool->execute(
			array( 'schedule_id' => 'does-not-exist-' . wp_generate_uuid4() ),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'schedule_not_found', $result->get_error_code() );
	}

	/**
	 * Happy path: create a real schedule and confirm the dry-run mirrors it
	 * without recording a run.
	 */
	public function test_execute_returns_preview_for_real_schedule() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$created = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'name'          => 'Dry-run test schedule',
				'description'   => 'fixture',
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_test_dryrun_hook',
				'schedule'      => 'daily',
				'enabled'       => true,
			),
			$admin
		);

		if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
			$this->markTestSkipped( 'Could not create fixture schedule: ' . ( is_wp_error( $created ) ? $created->get_error_message() : 'unknown error' ) );
		}

		$schedule_id = (string) $created['id'];

		// Snapshot run-count before dry-run so we can prove dry-run is side-effect free.
		$before       = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$before_count = isset( $before['run_count'] ) ? (int) $before['run_count'] : 0;

		$tool   = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();
		$result = $tool->execute(
			array(
				'schedule_id' => $schedule_id,
				'count'       => 3,
			),
			array( 'user_id' => $admin )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $schedule_id, $result['schedule_id'] );
		$this->assertSame( 'task', $result['schedule_type'] );
		$this->assertSame( 'daily', $result['cadence'] );
		$this->assertTrue( $result['enabled'] );
		$this->assertArrayHasKey( 'next_runs', $result );
		$this->assertLessThanOrEqual( 3, count( $result['next_runs'] ) );
		$this->assertArrayHasKey( 'action', $result );
		$this->assertSame( 'task', $result['action']['type'] );
		$this->assertSame( 'wp_mcp_ai_test_dryrun_hook', $result['action']['hook'] );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertIsArray( $result['warnings'] );

		// Side-effect free: run_count must be unchanged.
		$after = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$this->assertSame( $before_count, isset( $after['run_count'] ) ? (int) $after['run_count'] : 0 );

		// Cleanup the fixture so we don't leak cron events.
		WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $schedule_id );
	}

	/**
	 * Paused schedules surface a warning instead of silently dispatching.
	 */
	public function test_disabled_schedule_emits_warning() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$created = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'name'          => 'Paused dry-run schedule',
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_test_dryrun_paused',
				'schedule'      => 'daily',
				'enabled'       => false,
			),
			$admin
		);

		if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
			$this->markTestSkipped( 'Could not create fixture schedule.' );
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule();
		$result = $tool->execute(
			array( 'schedule_id' => $created['id'] ),
			array( 'user_id' => $admin )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['enabled'] );
		$this->assertFalse( $result['would_dispatch'] );
		$this->assertNotEmpty( $result['warnings'] );

		WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $created['id'] );
	}
}
