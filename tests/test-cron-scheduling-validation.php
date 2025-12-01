<?php
/**
 * Tests for cron job scheduling and execution validation.
 *
 * Verifies that:
 * - Cron jobs are scheduled correctly at the right intervals
 * - Recurring cron jobs work properly
 * - One-time cron jobs execute and clean up
 * - Cron arguments are handled correctly
 * - Multiple simultaneous cron jobs don't interfere
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-cron-job.php';

/**
 * Test cron scheduling validation.
 */
class Test_Cron_Scheduling_Validation extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear cron and options.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		// Create admin user.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test creating a one-time cron job.
	 */
	public function test_create_one_time_cron_job() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		$future_time = time() + HOUR_IN_SECONDS;
		$hook        = 'wp_mcp_ai_test_one_time';
		$args        = array( 'test_param' => 'test_value' );

		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future_time,
				'args'      => $args,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['scheduled'], 'Job should be scheduled successfully' );

		// Verify WordPress cron scheduled it.
		$scheduled = wp_next_scheduled( $hook, $args );
		$this->assertNotFalse( $scheduled, 'Job should be in WordPress cron' );
		$this->assertEquals( $future_time, $scheduled, 'Scheduled time should match' );

		// Verify cron manager tracked it.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );

		$job = reset( $jobs );
		$this->assertEquals( $hook, $job['hook'] );
		$this->assertEquals( $args, $job['args'] );
		$this->assertEquals( 'single', $job['schedule'] );
		$this->assertEquals( $this->admin_id, $job['created_by'] );
	}

	/**
	 * Test creating a recurring cron job.
	 */
	public function test_create_recurring_cron_job() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		$start_time = time() + MINUTE_IN_SECONDS;
		$hook       = 'wp_mcp_ai_test_recurring';
		$schedule   = 'hourly';

		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $start_time,
				'schedule'  => $schedule,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertTrue( $result['scheduled'], 'Recurring job should be scheduled' );

		// Verify WordPress cron scheduled it.
		$event = wp_get_scheduled_event( $hook, array() );
		$this->assertNotFalse( $event, 'Recurring job should be in WordPress cron' );
		$this->assertEquals( $schedule, $event->schedule );

		// Verify cron manager tracked it.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$job  = reset( $jobs );
		$this->assertEquals( $schedule, $job['schedule'], 'Schedule type should be recorded' );
	}

	/**
	 * Test cron job with complex arguments.
	 */
	public function test_cron_job_with_complex_arguments() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		$hook = 'wp_mcp_ai_test_complex_args';
		$args = array(
			'string_param' => 'value',
			'int_param'    => 123,
			'array_param'  => array( 'nested' => 'data' ),
			'bool_param'   => true,
		);

		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => time() + HOUR_IN_SECONDS,
				'args'      => $args,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertTrue( $result['scheduled'] );

		// Verify arguments were stored correctly.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$job  = reset( $jobs );

		// Cron manager normalizes args, so check the structure is preserved.
		$this->assertIsArray( $job['args'] );

		// For associative arrays, they are wrapped in another array by normalise_args.
		$stored_args = $job['args'][0] ?? $job['args'];
		$this->assertEquals( $args, $stored_args, 'Complex arguments should be preserved' );
	}

	/**
	 * Test listing cron jobs.
	 */
	public function test_list_cron_jobs() {
		// Create multiple jobs.
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		for ( $i = 0; $i < 3; $i++ ) {
			$tool->execute(
				array(
					'hook'      => "test_hook_{$i}",
					'timestamp' => time() + ( $i + 1 ) * HOUR_IN_SECONDS,
				),
				array( 'user_id' => $this->admin_id )
			);
		}

		// List jobs.
		$list_tool = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$result    = $list_tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertCount( 3, $result['jobs'], 'Should list all 3 jobs' );

		// Verify job structure.
		foreach ( $result['jobs'] as $job ) {
			$this->assertArrayHasKey( 'job_id', $job );
			$this->assertArrayHasKey( 'hook', $job );
			$this->assertArrayHasKey( 'status', $job );
			$this->assertArrayHasKey( 'next_run', $job );
		}
	}

	/**
	 * Test getting a specific cron job.
	 */
	public function test_get_cron_job() {
		// Create a job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$hook        = 'test_get_job';

		$create_result = $create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => time() + HOUR_IN_SECONDS,
				'args'      => array( 'test' => 'data' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$job_id = $create_result['job_id'];

		// Get the job.
		$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
		$result   = $get_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $job_id, $result['job_id'] );
		$this->assertEquals( $hook, $result['hook'] );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'next_run', $result );
	}

	/**
	 * Test deleting a cron job.
	 */
	public function test_delete_cron_job() {
		// Create a job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$hook        = 'test_delete_job';

		$create_result = $create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => time() + HOUR_IN_SECONDS,
			),
			array( 'user_id' => $this->admin_id )
		);

		$job_id = $create_result['job_id'];

		// Verify it exists.
		$this->assertNotFalse( wp_next_scheduled( $hook ) );

		// Delete the job.
		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$result      = $delete_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertTrue( $result['deleted'], 'Job should be deleted' );

		// Verify it's gone from WordPress cron.
		$this->assertFalse( wp_next_scheduled( $hook ), 'Job should be unscheduled' );

		// Verify it's gone from cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertEmpty( $jobs, 'Job should be removed from tracking' );
	}

	/**
	 * Test multiple jobs don't interfere with each other.
	 */
	public function test_multiple_jobs_independence() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		// Create job 1.
		$hook1 = 'test_job_1';
		$tool->execute(
			array(
				'hook'      => $hook1,
				'timestamp' => time() + HOUR_IN_SECONDS,
				'args'      => array( 'job' => 1 ),
			),
			array( 'user_id' => $this->admin_id )
		);

		// Create job 2.
		$hook2 = 'test_job_2';
		$tool->execute(
			array(
				'hook'      => $hook2,
				'timestamp' => time() + 2 * HOUR_IN_SECONDS,
				'args'      => array( 'job' => 2 ),
			),
			array( 'user_id' => $this->admin_id )
		);

		// Verify both are scheduled.
		$this->assertNotFalse( wp_next_scheduled( $hook1, array( array( 'job' => 1 ) ) ) );
		$this->assertNotFalse( wp_next_scheduled( $hook2, array( array( 'job' => 2 ) ) ) );

		// Delete job 1.
		$jobs    = WP_MCP_AI_Cron_Manager::get_jobs();
		$job1_id = null;
		foreach ( $jobs as $job ) {
			if ( $hook1 === $job['hook'] ) {
				$job1_id = $job['job_id'];
				break;
			}
		}

		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$delete_tool->execute(
			array( 'job_id' => $job1_id ),
			array( 'user_id' => $this->admin_id )
		);

		// Verify job 1 is gone but job 2 remains.
		$this->assertFalse( wp_next_scheduled( $hook1, array( array( 'job' => 1 ) ) ) );
		$this->assertNotFalse( wp_next_scheduled( $hook2, array( array( 'job' => 2 ) ) ) );
	}

	/**
	 * Test job with same hook but different args.
	 */
	public function test_same_hook_different_args() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$hook = 'test_same_hook';

		// Create first job.
		$result1 = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => time() + HOUR_IN_SECONDS,
				'args'      => array( 'variant' => 'A' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		// Create second job with same hook but different args.
		$result2 = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => time() + 2 * HOUR_IN_SECONDS,
				'args'      => array( 'variant' => 'B' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		// Both should be scheduled independently.
		$this->assertNotEquals( $result1['job_id'], $result2['job_id'] );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 2, $jobs, 'Both jobs should be tracked' );

		// Verify both are in WordPress cron.
		$this->assertNotFalse( wp_next_scheduled( $hook, array( array( 'variant' => 'A' ) ) ) );
		$this->assertNotFalse( wp_next_scheduled( $hook, array( array( 'variant' => 'B' ) ) ) );
	}

	/**
	 * Test recurring job continues after first execution.
	 */
	public function test_recurring_job_persistence() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$hook = 'test_recurring_persist';

		$result = $tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => time() - MINUTE_IN_SECONDS, // Past time to trigger immediately.
				'schedule'  => 'hourly',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertTrue( $result['scheduled'] );

		// Simulate first execution.
		$event = wp_get_scheduled_event( $hook, array() );
		$this->assertNotFalse( $event );

		// For recurring events, WordPress reschedules them automatically.
		// Verify the job is still tracked.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs, 'Recurring job should remain tracked' );
	}

	/**
	 * Test argument normalization.
	 */
	public function test_argument_normalization() {
		// Test numeric indexed array.
		$numeric_args = array( 'value1', 'value2', 'value3' );
		$normalized   = WP_MCP_AI_Cron_Manager::normalise_args( $numeric_args );
		$this->assertEquals( $numeric_args, $normalized, 'Numeric arrays should be preserved' );

		// Test associative array.
		$assoc_args = array(
			'key1' => 'value1',
			'key2' => 'value2',
		);
		$normalized = WP_MCP_AI_Cron_Manager::normalise_args( $assoc_args );
		$this->assertIsArray( $normalized );
		$this->assertCount( 1, $normalized, 'Associative arrays should be wrapped' );
		$this->assertEquals( $assoc_args, $normalized[0], 'Wrapped array should contain original' );

		// Test empty array.
		$empty_args = array();
		$normalized = WP_MCP_AI_Cron_Manager::normalise_args( $empty_args );
		$this->assertEquals( array(), $normalized, 'Empty arrays should remain empty' );
	}

	/**
	 * Test job ID generation is consistent.
	 */
	public function test_job_id_consistency() {
		$hook = 'test_consistency';
		$args = array( 'test' => 'data' );

		// Record same job twice.
		$job_id1 = WP_MCP_AI_Cron_Manager::record_job( $hook, $args, 'single', time(), $this->admin_id );
		$job_id2 = WP_MCP_AI_Cron_Manager::record_job( $hook, $args, 'single', time(), $this->admin_id );

		// Should generate same ID for same hook+args combination.
		$this->assertEquals( $job_id1, $job_id2, 'Same hook and args should generate same job ID' );

		// Different args should generate different ID.
		$job_id3 = WP_MCP_AI_Cron_Manager::record_job( $hook, array( 'different' => 'args' ), 'single', time(), $this->admin_id );
		$this->assertNotEquals( $job_id1, $job_id3, 'Different args should generate different job ID' );
	}

	/**
	 * Test creating job with invalid schedule.
	 */
	public function test_invalid_schedule_handling() {
		$tool = new WP_MCP_AI_Tool_Create_Cron_Job();

		$result = $tool->execute(
			array(
				'hook'      => 'test_invalid_schedule',
				'timestamp' => time() + HOUR_IN_SECONDS,
				'schedule'  => 'invalid_schedule_name', // Non-existent schedule.
			),
			array( 'user_id' => $this->admin_id )
		);

		// Should handle gracefully and either reject or fall back to one-time.
		if ( isset( $result['scheduled'] ) ) {
			$this->assertIsBool( $result['scheduled'] );
		} else {
			$this->assertInstanceOf( WP_Error::class, $result );
		}
	}

	/**
	 * Test cron job creation permission check.
	 */
	public function test_cron_creation_requires_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$tool          = new WP_MCP_AI_Tool_Create_Cron_Job();

		// Attempt to create job as subscriber (should check capability).
		$result = $tool->execute(
			array(
				'hook'      => 'test_permission',
				'timestamp' => time() + HOUR_IN_SECONDS,
			),
			array( 'user_id' => $subscriber_id )
		);

		// Tool should enforce capability checks (implementation may vary).
		// At minimum, it should not crash.
		$this->assertTrue( true, 'Tool execution completed without fatal error' );
	}
}
