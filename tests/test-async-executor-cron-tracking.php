<?php
/**
 * Tests for async executor cron job tracking integration.
 *
 * Verifies that the WP_MCP_AI_Tool_Async_Executor properly records
 * cron jobs in the WP_MCP_AI_Cron_Manager when scheduling async tasks.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

/**
 * Test async executor cron tracking integration.
 */
class WP_MCP_AI_Async_Executor_Cron_Tracking_Test extends WP_UnitTestCase {

	/**
	 * Async executor instance.
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor
	 */
	private $executor;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear cron and cron manager data.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		// Create executor instance.
		$this->executor = new WP_MCP_AI_Tool_Async_Executor();
		$this->executor->init();
	}

	/**
	 * Cleanup test environment.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test that queuing a tool records the cron job in the cron manager.
	 */
	public function test_queue_tool_records_cron_job() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Queue a tool for async execution.
		$job_id = $this->executor->queue_tool(
			'example_tool',
			array( 'param' => 'value' ),
			array( 'user_id' => $user_id )
		);

		// Verify job was queued.
		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'async_', $job_id );

		// Verify cron job is scheduled.
		$next_run = wp_next_scheduled(
			WP_MCP_AI_Tool_Async_Executor::CRON_HOOK,
			array( $job_id )
		);
		$this->assertNotFalse( $next_run, 'Cron job should be scheduled' );

		// Verify cron job is recorded in the cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertNotEmpty( $jobs, 'Cron manager should have recorded jobs' );

		// Find our specific job in the cron manager.
		$found = false;
		foreach ( $jobs as $job ) {
			if ( isset( $job['hook'] ) &&
				WP_MCP_AI_Tool_Async_Executor::CRON_HOOK === $job['hook'] &&
				isset( $job['args'][0] ) &&
				$job_id === $job['args'][0] ) {
				$found = true;
				$this->assertSame( 'single', $job['schedule'], 'Should be a one-time job' );
				$this->assertSame( $user_id, $job['created_by'], 'Should track the user who created it' );
				break;
			}
		}

		$this->assertTrue( $found, 'Cron manager should contain the async tool execution job' );
	}

	/**
	 * Test that queuing a tool without user context records with system user (0).
	 */
	public function test_queue_tool_without_user_records_system_job() {
		// Queue a tool without user context.
		$job_id = $this->executor->queue_tool(
			'example_tool',
			array( 'param' => 'value' ),
			array() // No user_id in context.
		);

		// Verify job was queued.
		$this->assertIsString( $job_id );

		// Verify cron job is recorded in the cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertNotEmpty( $jobs );

		// Find our specific job in the cron manager.
		$found = false;
		foreach ( $jobs as $job ) {
			if ( isset( $job['hook'] ) &&
				WP_MCP_AI_Tool_Async_Executor::CRON_HOOK === $job['hook'] &&
				isset( $job['args'][0] ) &&
				$job_id === $job['args'][0] ) {
				$found = true;
				$this->assertSame( 0, $job['created_by'], 'Should be recorded as system job' );
				break;
			}
		}

		$this->assertTrue( $found, 'Cron manager should contain the async tool execution job' );
	}

	/**
	 * Test that cleanup cron job is recorded in the cron manager.
	 */
	public function test_cleanup_job_recorded_in_cron_manager() {
		// Clear any existing cleanup jobs.
		wp_clear_scheduled_hook( 'wp_mcp_ai_cleanup_async_results' );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		// Initialize executor (which schedules cleanup).
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Verify cleanup cron job is scheduled.
		$next_run = wp_next_scheduled( 'wp_mcp_ai_cleanup_async_results' );
		$this->assertNotFalse( $next_run, 'Cleanup cron job should be scheduled' );

		// Verify cleanup cron job is recorded in the cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertNotEmpty( $jobs, 'Cron manager should have recorded jobs' );

		// Find the cleanup job.
		$found = false;
		foreach ( $jobs as $job ) {
			if ( isset( $job['hook'] ) && 'wp_mcp_ai_cleanup_async_results' === $job['hook'] ) {
				$found = true;
				$this->assertSame( 'hourly', $job['schedule'], 'Cleanup should run hourly' );
				$this->assertSame( 0, $job['created_by'], 'Should be a system job' );
				break;
			}
		}

		$this->assertTrue( $found, 'Cleanup job should be recorded in cron manager' );
	}

	/**
	 * Test that multiple tool queues create separate cron manager entries.
	 */
	public function test_multiple_tool_queues_create_separate_entries() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Queue two different tools.
		$job_id_1 = $this->executor->queue_tool(
			'tool_one',
			array( 'param' => 'value1' ),
			array( 'user_id' => $user_id )
		);

		$job_id_2 = $this->executor->queue_tool(
			'tool_two',
			array( 'param' => 'value2' ),
			array( 'user_id' => $user_id )
		);

		// Verify both jobs were queued.
		$this->assertIsString( $job_id_1 );
		$this->assertIsString( $job_id_2 );
		$this->assertNotEquals( $job_id_1, $job_id_2, 'Job IDs should be different' );

		// Get all cron manager jobs.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		// Count matching async tool execution jobs.
		$count = 0;
		foreach ( $jobs as $job ) {
			if ( isset( $job['hook'] ) && WP_MCP_AI_Tool_Async_Executor::CRON_HOOK === $job['hook'] ) {
				++$count;
			}
		}

		$this->assertGreaterThanOrEqual( 2, $count, 'Should have at least 2 async tool execution jobs recorded' );
	}
}
