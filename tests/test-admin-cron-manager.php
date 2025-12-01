<?php
/**
 * Tests for the admin cron manager UI enhancements.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';

/**
 * Tests for the admin cron manager UI.
 */
class WP_MCP_AI_Admin_Cron_Manager_Test extends WP_UnitTestCase {

	/**
	 * Admin cron manager instance.
	 *
	 * @var WP_MCP_AI_Admin_Cron_Manager
	 */
	private $manager;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		$this->manager = new WP_MCP_AI_Admin_Cron_Manager();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test statistics calculation with no jobs.
	 */
	public function test_get_statistics_empty() {
		$jobs = array();

		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$stats = $stats_method->invoke( $this->manager, $jobs );

		$this->assertIsArray( $stats );
		$this->assertEquals( 0, $stats['total'] );
		$this->assertEquals( 0, $stats['active'] );
		$this->assertEquals( 0, $stats['inactive'] );
		$this->assertEquals( 0, $stats['recurring'] );
		$this->assertEquals( 0, $stats['one_off'] );
	}

	/**
	 * Test statistics calculation with active one-off job.
	 */
	public function test_get_statistics_with_active_oneoff() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_test_job';
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

		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$stats = $stats_method->invoke( $this->manager, $jobs );

		$this->assertEquals( 1, $stats['total'] );
		$this->assertEquals( 1, $stats['active'] );
		$this->assertEquals( 0, $stats['inactive'] );
		$this->assertEquals( 0, $stats['recurring'] );
		$this->assertEquals( 1, $stats['one_off'] );
	}

	/**
	 * Test statistics calculation with recurring job.
	 */
	public function test_get_statistics_with_recurring() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_recurring_job';
		$future   = time() + HOUR_IN_SECONDS;

		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'schedule'  => 'hourly',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$stats = $stats_method->invoke( $this->manager, $jobs );

		$this->assertEquals( 1, $stats['total'] );
		$this->assertEquals( 1, $stats['active'] );
		$this->assertEquals( 0, $stats['inactive'] );
		$this->assertEquals( 1, $stats['recurring'] );
		$this->assertEquals( 0, $stats['one_off'] );
	}

	/**
	 * Test statistics calculation with inactive job.
	 */
	public function test_get_statistics_with_inactive() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_inactive_job';
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

		// Clear the cron to make the job inactive.
		_set_cron_array( array() );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$stats = $stats_method->invoke( $this->manager, $jobs );

		$this->assertEquals( 1, $stats['total'] );
		$this->assertEquals( 0, $stats['active'] );
		$this->assertEquals( 1, $stats['inactive'] );
		$this->assertEquals( 0, $stats['recurring'] );
		$this->assertEquals( 1, $stats['one_off'] );
	}

	/**
	 * Test statistics calculation with mixed jobs.
	 */
	public function test_get_statistics_with_mixed_jobs() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$future   = time() + HOUR_IN_SECONDS;
		$tool     = new WP_MCP_AI_Tool_Create_Cron_Job();

		// Create one-off job.
		$tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_oneoff',
				'timestamp' => $future,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		// Create recurring job.
		$tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_recurring',
				'timestamp' => $future,
				'schedule'  => 'daily',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$stats = $stats_method->invoke( $this->manager, $jobs );

		$this->assertEquals( 2, $stats['total'] );
		$this->assertEquals( 2, $stats['active'] );
		$this->assertEquals( 0, $stats['inactive'] );
		$this->assertEquals( 1, $stats['recurring'] );
		$this->assertEquals( 1, $stats['one_off'] );
	}

	/**
	 * Test that the manager can be instantiated.
	 */
	public function test_manager_instantiation() {
		$this->assertInstanceOf( WP_MCP_AI_Admin_Cron_Manager::class, $this->manager );
	}

	/**
	 * Test that page slug constant exists.
	 */
	public function test_page_slug_constant() {
		$this->assertEquals( 'wp-mcp-ai-cron-manager', WP_MCP_AI_Admin_Cron_Manager::PAGE_SLUG );
	}

	/**
	 * Test that jobs created via the create_cron_job tool appear in the cron manager.
	 *
	 * This test confirms that when the assistant creates scheduled events through
	 * the tool, they will appear in the cron manager for monitoring and management.
	 */
	public function test_created_jobs_appear_in_cron_manager() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_test_scheduled_event';
		$future   = time() + HOUR_IN_SECONDS;

		// Create a test job using the tool (simulating assistant creating an event).
		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );

		// Verify the job appears when the cron manager retrieves jobs.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		$this->assertNotEmpty( $jobs, 'Created job should appear in cron manager' );
		$this->assertCount( 1, $jobs, 'Exactly one job should be in the cron manager' );

		// Verify job details are correct.
		$job = array_shift( $jobs );
		$this->assertEquals( $hook, $job['hook'], 'Job hook should match' );
		$this->assertEquals( 'single', $job['schedule'], 'Job schedule should be single' );
		$this->assertEquals( $admin_id, $job['created_by'], 'Job creator should match' );

		// Verify the job appears in statistics.
		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$all_jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$stats    = $stats_method->invoke( $this->manager, $all_jobs );

		$this->assertEquals( 1, $stats['total'], 'Statistics should show 1 total job' );
		$this->assertEquals( 1, $stats['active'], 'Statistics should show 1 active job' );
		$this->assertEquals( 1, $stats['one_off'], 'Statistics should show 1 one-off job' );

		// Verify the job is actually scheduled in WordPress cron.
		$scheduled_time = wp_next_scheduled( $hook, array() );
		$this->assertNotFalse( $scheduled_time, 'Job should be scheduled in WordPress cron' );
		$this->assertEquals( $future, $scheduled_time, 'Scheduled time should match requested time' );
	}

	/**
	 * Test that multiple test jobs including recurring ones appear in the cron manager.
	 *
	 * This test confirms that various types of scheduled events created during
	 * testing will all appear in the cron manager interface.
	 */
	public function test_multiple_test_jobs_appear_in_cron_manager() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$future   = time() + HOUR_IN_SECONDS;
		$tool     = new WP_MCP_AI_Tool_Create_Cron_Job();

		// Create a one-off test job.
		$result1 = $tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_test_oneoff',
				'timestamp' => $future,
			),
			array(
				'user_id' => $admin_id,
			)
		);
		$this->assertNotWPError( $result1 );

		// Create a recurring test job.
		$result2 = $tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_test_recurring',
				'timestamp' => $future + HOUR_IN_SECONDS,
				'schedule'  => 'hourly',
			),
			array(
				'user_id' => $admin_id,
			)
		);
		$this->assertNotWPError( $result2 );

		// Create another one-off job with arguments.
		$result3 = $tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_test_with_args',
				'timestamp' => $future + ( 2 * HOUR_IN_SECONDS ),
				'args'      => array( 'test_key' => 'test_value' ),
			),
			array(
				'user_id' => $admin_id,
			)
		);
		$this->assertNotWPError( $result3 );

		// Verify all jobs appear in the cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 3, $jobs, 'All three test jobs should appear in cron manager' );

		// Verify job hooks are all present.
		$hooks = array_column( $jobs, 'hook' );
		$this->assertContains( 'wp_mcp_ai_test_oneoff', $hooks, 'One-off job should be listed' );
		$this->assertContains( 'wp_mcp_ai_test_recurring', $hooks, 'Recurring job should be listed' );
		$this->assertContains( 'wp_mcp_ai_test_with_args', $hooks, 'Job with args should be listed' );

		// Verify statistics reflect all jobs.
		$stats_method = new ReflectionMethod( $this->manager, 'get_statistics' );
		$stats_method->setAccessible( true );
		$stats = $stats_method->invoke( $this->manager, $jobs );

		$this->assertEquals( 3, $stats['total'], 'Statistics should show 3 total jobs' );
		$this->assertEquals( 3, $stats['active'], 'Statistics should show 3 active jobs' );
		$this->assertEquals( 1, $stats['recurring'], 'Statistics should show 1 recurring job' );
		$this->assertEquals( 2, $stats['one_off'], 'Statistics should show 2 one-off jobs' );

		// Verify all jobs are scheduled in WordPress cron.
		$this->assertNotFalse( wp_next_scheduled( 'wp_mcp_ai_test_oneoff' ), 'One-off job should be scheduled' );
		$this->assertNotFalse( wp_next_scheduled( 'wp_mcp_ai_test_recurring' ), 'Recurring job should be scheduled' );
		$this->assertNotFalse( wp_next_scheduled( 'wp_mcp_ai_test_with_args', array( array( 'test_key' => 'test_value' ) ) ), 'Job with args should be scheduled' );
	}

	/**
	 * Test that the admin manager can render the page with test jobs.
	 *
	 * This test confirms that when jobs are created, the render_page method
	 * can be called successfully and the jobs will appear in the admin UI.
	 */
	public function test_render_page_shows_test_jobs() {
		// Create an admin user with proper capabilities.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test jobs using the tool.
		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$future = time() + HOUR_IN_SECONDS;

		// Create a one-off job.
		$tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_render_test_oneoff',
				'timestamp' => $future,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		// Create a recurring job.
		$tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_render_test_recurring',
				'timestamp' => $future + HOUR_IN_SECONDS,
				'schedule'  => 'daily',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		// Verify jobs were created.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 2, $jobs, 'Two jobs should be created before rendering' );

		// Capture the output of render_page.
		ob_start();
		$this->manager->render_page();
		$output = ob_get_clean();

		// Verify the output contains job information.
		$this->assertStringContainsString( 'wp_mcp_ai_render_test_oneoff', $output, 'One-off job hook should appear in rendered output' );
		$this->assertStringContainsString( 'wp_mcp_ai_render_test_recurring', $output, 'Recurring job hook should appear in rendered output' );

		// Verify statistics are displayed.
		$this->assertStringContainsString( 'Total Events', $output, 'Total events label should appear' );
		$this->assertStringContainsString( 'Active', $output, 'Active label should appear' );
		$this->assertStringContainsString( 'Recurring', $output, 'Recurring label should appear' );
		$this->assertStringContainsString( 'One-off', $output, 'One-off label should appear' );

		// Verify the stats show correct counts.
		$this->assertStringContainsString( '>2<', $output, 'Should show 2 total events' );
		$this->assertStringContainsString( '>1<', $output, 'Should show 1 recurring and 1 one-off' );
	}
}
