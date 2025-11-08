<?php
/**
 * Tests for the admin cron manager UI enhancements.
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
}
