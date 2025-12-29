<?php
/**
 * Cron Manager
 *
 * @package WP_MCP_AI
 */


require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';

/**
 * Tests for the cron manager utilities.
 */
class WP_MCP_AI_Cron_Manager_Test extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	public function test_remove_job_unschedules_event() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_remove_job';
		$future   = time() + HOUR_IN_SECONDS;

		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );

		$job_id = array_key_first( $jobs );
		$this->assertTrue( WP_MCP_AI_Cron_Manager::remove_job( $job_id ) );

		$this->assertSame( array(), WP_MCP_AI_Cron_Manager::get_jobs() );
		$this->assertFalse( wp_next_scheduled( $hook ) );
	}

	public function test_maybe_prune_jobs_retains_recent_executed_jobs() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_prune_job';
		$future   = time() + MINUTE_IN_SECONDS;

		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );

		// Simulate the job being executed and removed from WordPress cron.
		_set_cron_array( array() );

		// Job should still be in the list because it's within 24 hours.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs, 'Job should be retained for 24 hours after execution' );
	}

	public function test_maybe_prune_jobs_removes_old_executed_jobs() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_old_job';
		// Set timestamp to 25 hours ago.
		$old_time = time() - ( 25 * HOUR_IN_SECONDS );

		// Manually create a job with an old timestamp.
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $old_time, $admin_id );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );

		// Simulate the job not being in WordPress cron (already executed).
		_set_cron_array( array() );

		// Job should be removed because it's older than 24 hours.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertSame( array(), $jobs, 'Jobs older than 24 hours should be pruned' );
	}

	public function test_maybe_prune_jobs_removes_jobs_without_timestamp() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_no_timestamp_job';

		// Manually create a job without a timestamp.
		$jobs                = WP_MCP_AI_Cron_Manager::get_jobs();
		$jobs['test_job_id'] = array(
			'job_id'          => 'test_job_id',
			'hook'            => $hook,
			'args'            => array(),
			'schedule'        => 'single',
			'first_timestamp' => 0,
			'created_at'      => time(),
			'created_by'      => $admin_id,
		);
		update_option( WP_MCP_AI_Cron_Manager::OPTION_NAME, $jobs );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );

		// Simulate the job not being in WordPress cron.
		_set_cron_array( array() );

		// Job without timestamp should be removed immediately.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertSame( array(), $jobs, 'Jobs without timestamp should be pruned immediately' );
	}

	public function test_maybe_prune_jobs_respects_settings_registry() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_custom_retention_job';
		// Set timestamp to 2 hours ago.
		$two_hours_ago = time() - ( 2 * HOUR_IN_SECONDS );

		// Set custom retention period to 1 hour via settings registry.
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cron_job_retention_period'] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Manually create a job with a timestamp 2 hours ago.
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $two_hours_ago, $admin_id );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );

		// Simulate the job not being in WordPress cron (already executed).
		_set_cron_array( array() );

		// Job should be removed because it's older than the 1-hour retention period.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertSame( array(), $jobs, 'Job should be pruned when exceeding custom retention period from settings' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}
}
