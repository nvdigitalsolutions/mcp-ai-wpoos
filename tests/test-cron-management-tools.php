<?php

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-cron-job.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php';

/**
 * Tests for the cron management tools.
 */
class WP_MCP_AI_Cron_Management_Tools_Test extends WP_UnitTestCase {
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
	 * Test list_cron_jobs returns empty array when no jobs exist.
	 */
	public function test_list_cron_jobs_empty() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'jobs', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertSame( 0, $result['count'] );
		$this->assertEmpty( $result['jobs'] );
	}

	/**
	 * Test list_cron_jobs requires proper permissions.
	 */
	public function test_list_cron_jobs_requires_permission() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test list_cron_jobs returns created jobs.
	 */
	public function test_list_cron_jobs_shows_created_jobs() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook1    = 'wp_mcp_ai_test_job_1';
		$hook2    = 'wp_mcp_ai_test_job_2';
		$future   = time() + HOUR_IN_SECONDS;

		// Create two jobs.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$create_tool->execute(
			array(
				'hook'      => $hook1,
				'timestamp' => $future,
			),
			array( 'user_id' => $admin_id )
		);

		$create_tool->execute(
			array(
				'hook'      => $hook2,
				'timestamp' => $future + HOUR_IN_SECONDS,
				'schedule'  => 'hourly',
			),
			array( 'user_id' => $admin_id )
		);

		// List jobs.
		$list_tool = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$result    = $list_tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertCount( 2, $result['jobs'] );

		// Check that jobs have required fields.
		foreach ( $result['jobs'] as $job ) {
			$this->assertArrayHasKey( 'job_id', $job );
			$this->assertArrayHasKey( 'hook', $job );
			$this->assertArrayHasKey( 'schedule', $job );
			$this->assertArrayHasKey( 'args', $job );
			$this->assertArrayHasKey( 'creator', $job );
			$this->assertArrayHasKey( 'next_run', $job );
			$this->assertArrayHasKey( 'next_run_formatted', $job );
		}

		// Verify hooks.
		$hooks = array_column( $result['jobs'], 'hook' );
		$this->assertContains( $hook1, $hooks );
		$this->assertContains( $hook2, $hooks );
	}

	/**
	 * Test get_cron_job requires proper permissions.
	 */
	public function test_get_cron_job_requires_permission() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Get_Cron_Job();
		$result = $tool->execute(
			array( 'job_id' => 'fake-job-id' ),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test get_cron_job requires a job ID.
	 */
	public function test_get_cron_job_requires_job_id() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Get_Cron_Job();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_job_id', $result->get_error_code() );
	}

	/**
	 * Test get_cron_job returns error for non-existent job.
	 */
	public function test_get_cron_job_not_found() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Get_Cron_Job();
		$result = $tool->execute(
			array( 'job_id' => 'non-existent-job-id' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_job_not_found', $result->get_error_code() );
	}

	/**
	 * Test get_cron_job returns detailed job information.
	 */
	public function test_get_cron_job_returns_details() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_detailed_job';
		$args     = array( 'key' => 'value' );
		$future   = time() + HOUR_IN_SECONDS;

		// Create a job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$created     = $create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'schedule'  => 'hourly',
				'args'      => $args,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $created );

		// Get the job ID from the cron manager.
		$jobs   = WP_MCP_AI_Cron_Manager::get_jobs();
		$job    = array_shift( $jobs );
		$job_id = $job['job_id'];

		// Get job details.
		$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
		$result   = $get_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $job_id, $result['job_id'] );
		$this->assertSame( $hook, $result['hook'] );
		$this->assertSame( 'hourly', $result['schedule'] );
		$this->assertSame( array( $args ), $result['args'] );
		$this->assertArrayHasKey( 'next_run', $result );
		$this->assertArrayHasKey( 'next_run_formatted', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertArrayHasKey( 'schedule_display', $result );
		$this->assertArrayHasKey( 'schedule_interval', $result );
	}

	/**
	 * Test delete_cron_job requires proper permissions.
	 */
	public function test_delete_cron_job_requires_permission() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$result = $tool->execute(
			array( 'job_id' => 'fake-job-id' ),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test delete_cron_job requires a job ID.
	 */
	public function test_delete_cron_job_requires_job_id() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_job_id', $result->get_error_code() );
	}

	/**
	 * Test delete_cron_job returns error for non-existent job.
	 */
	public function test_delete_cron_job_not_found() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$result = $tool->execute(
			array( 'job_id' => 'non-existent-job-id' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_job_not_found', $result->get_error_code() );
	}

	/**
	 * Test delete_cron_job successfully removes a job.
	 */
	public function test_delete_cron_job_removes_job() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_deletable_job';
		$future   = time() + HOUR_IN_SECONDS;

		// Create a job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$created     = $create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $created );

		// Verify job exists.
		$jobs_before = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs_before );
		$job    = array_shift( $jobs_before );
		$job_id = $job['job_id'];

		// Verify it's scheduled in WP-Cron.
		$scheduled = wp_next_scheduled( $hook, array() );
		$this->assertNotFalse( $scheduled );

		// Delete the job.
		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$result      = $delete_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $job_id, $result['job_id'] );
		$this->assertSame( $hook, $result['hook'] );

		// Verify job is removed from manager.
		$jobs_after = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertEmpty( $jobs_after );

		// Verify it's unscheduled from WP-Cron.
		$scheduled_after = wp_next_scheduled( $hook, array() );
		$this->assertFalse( $scheduled_after );
	}

	/**
	 * Test complete workflow: create, list, get, delete.
	 */
	public function test_complete_cron_workflow() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_workflow_job';
		$future   = time() + HOUR_IN_SECONDS;

		// 1. Create a job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$created     = $create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $created );
		$this->assertSame( $hook, $created['hook'] );

		// 2. List jobs and verify it appears.
		$list_tool = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$listed    = $list_tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $listed );
		$this->assertSame( 1, $listed['count'] );
		$job_id = $listed['jobs'][0]['job_id'];
		$this->assertSame( $hook, $listed['jobs'][0]['hook'] );

		// 3. Get job details.
		$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
		$details  = $get_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $details );
		$this->assertSame( $job_id, $details['job_id'] );
		$this->assertSame( $hook, $details['hook'] );
		$this->assertSame( 'scheduled', $details['status'] );

		// 4. Delete the job.
		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$deleted     = $delete_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $deleted );
		$this->assertSame( $job_id, $deleted['job_id'] );

		// 5. Verify list is empty.
		$listed_after = $list_tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $listed_after );
		$this->assertSame( 0, $listed_after['count'] );
		$this->assertEmpty( $listed_after['jobs'] );
	}

	/**
	 * Test list_cron_jobs includes creator information.
	 */
	public function test_list_cron_jobs_includes_creator() {
		$admin_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test Admin',
			)
		);
		$hook     = 'wp_mcp_ai_creator_test';
		$future   = time() + HOUR_IN_SECONDS;

		// Create a job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
			),
			array( 'user_id' => $admin_id )
		);

		// List jobs.
		$list_tool = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$result    = $list_tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['jobs'] );
		$this->assertSame( 'Test Admin', $result['jobs'][0]['creator'] );
	}

	/**
	 * Test delete_cron_job removes recurring events properly.
	 */
	public function test_delete_recurring_cron_job() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$hook     = 'wp_mcp_ai_recurring_delete';
		$future   = time() + HOUR_IN_SECONDS;

		// Create a recurring job.
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$create_tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $future,
				'schedule'  => 'hourly',
			),
			array( 'user_id' => $admin_id )
		);

		// Verify it's scheduled.
		$scheduled = wp_next_scheduled( $hook, array() );
		$this->assertNotFalse( $scheduled );

		// Get job ID.
		$jobs   = WP_MCP_AI_Cron_Manager::get_jobs();
		$job    = array_shift( $jobs );
		$job_id = $job['job_id'];

		// Delete the job.
		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$result      = $delete_tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );

		// Verify it's completely removed from WP-Cron.
		$scheduled_after = wp_next_scheduled( $hook, array() );
		$this->assertFalse( $scheduled_after );
	}

	/**
	 * Test list_cron_jobs schema encodes properties as empty object not array.
	 *
	 * This test ensures the fix for the chat UI schema validation error where
	 * an empty PHP array() was being encoded as JSON [] instead of {}.
	 */
	public function test_list_cron_jobs_schema_encodes_properties_as_object() {
		$tool   = new WP_MCP_AI_Tool_List_Cron_Jobs();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );

		// Encode the schema to JSON.
		$json = wp_json_encode( $schema );

		// Verify properties is encoded as {} not []
		$this->assertStringContainsString( '"properties":{}', $json );
		$this->assertStringNotContainsString( '"properties":[]', $json );

		// Also verify when decoded and re-encoded it stays as object.
		$decoded   = json_decode( $json );
		$reencoded = wp_json_encode( $decoded );
		$this->assertStringContainsString( '"properties":{}', $reencoded );
	}
}
