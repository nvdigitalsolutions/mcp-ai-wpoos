<?php
/**
 * Tests for Veo video job permissions with context.user_id
 *
 * Verifies that permission checks work correctly when jobs reuse parent job IDs
 * and user_id is stored in the context field instead of args field.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';

/**
 * Class Test_Veo_Job_Permission_Context
 */
class Test_Veo_Job_Permission_Context extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Another test user ID.
	 *
	 * @var int
	 */
	protected $other_user_id;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Cron status service instance.
	 *
	 * @var WP_MCP_AI_Cron_Status_Service
	 */
	protected $status_service;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test users.
		$this->user_id       = $this->factory->user->create();
		$this->other_user_id = $this->factory->user->create();
		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Initialize cron status service.
		$this->status_service = new WP_MCP_AI_Cron_Status_Service();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up any transients created during tests.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wp_mcp_ai_veo_async_' ) . '%'
			)
		);

		parent::tearDown();
	}

	/**
	 * Test that job with user_id in args field allows owner access.
	 *
	 * This tests the traditional case where video jobs store user_id in args.
	 */
	public function test_job_permission_with_args_user_id() {
		$job_id = 'veo_test_' . uniqid();

		// Create job metadata with user_id in args (traditional structure).
		$metadata = array(
			'job_id'            => $job_id,
			'operation_name'    => 'operations/test',
			'model'             => 'veo-3.1-generate-preview',
			'args'              => array(
				'user_id' => $this->user_id,
				'prompt'  => 'Test video',
			),
			'status'            => 'polling',
			'queued_at'         => time(),
			'poll_attempt'      => 0,
			'max_attempts'      => 60,
			'expected_filename' => 'veo-video-' . $job_id . '.mp4',
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Test that job owner can view the job.
		$result = $this->status_service->get_job_details( $job_id, $this->user_id );
		$this->assertNotWPError( $result );
		$this->assertEquals( $job_id, $result['job_id'] );
		$this->assertEquals( 'polling', $result['status'] );

		// Test that another user cannot view the job.
		$result = $this->status_service->get_job_details( $job_id, $this->other_user_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Test that admin can view any job.
		$result = $this->status_service->get_job_details( $job_id, $this->admin_id );
		$this->assertNotWPError( $result );
		$this->assertEquals( $job_id, $result['job_id'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that job with user_id in context field allows owner access.
	 *
	 * This tests the case when a job reuses parent job ID and has context.user_id
	 * instead of args.user_id (from async executor).
	 */
	public function test_job_permission_with_context_user_id() {
		$job_id = 'veo_test_' . uniqid();

		// Create job metadata with user_id in context field (async executor structure).
		// This simulates the case when a job reuses parent ID and merges metadata.
		$metadata = array(
			'job_id'            => $job_id,
			'operation_name'    => 'operations/test',
			'model'             => 'veo-3.1-generate-preview',
			'context'           => array(
				'user_id'      => $this->user_id,
				'assistant_id' => 123,
			),
			'args'              => array(
				'prompt' => 'Test video',
				// Note: args does NOT have user_id when job reuses parent ID.
			),
			'status'            => 'polling',
			'queued_at'         => time(),
			'poll_attempt'      => 0,
			'max_attempts'      => 60,
			'expected_filename' => 'veo-video-' . $job_id . '.mp4',
			'use_parent_job'    => true,
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Test that job owner can view the job even with user_id in context.
		$result = $this->status_service->get_job_details( $job_id, $this->user_id );
		$this->assertNotWPError( $result, 'Job owner should be able to view job with context.user_id' );
		$this->assertEquals( $job_id, $result['job_id'] );
		$this->assertEquals( 'polling', $result['status'] );

		// Test that another user cannot view the job.
		$result = $this->status_service->get_job_details( $job_id, $this->other_user_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Test that admin can view any job.
		$result = $this->status_service->get_job_details( $job_id, $this->admin_id );
		$this->assertNotWPError( $result );
		$this->assertEquals( $job_id, $result['job_id'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that job with user_id in both args and context prefers args.
	 *
	 * When both fields exist, args.user_id should take precedence.
	 */
	public function test_job_permission_with_both_args_and_context() {
		$job_id = 'veo_test_' . uniqid();

		// Create job metadata with user_id in both args and context.
		// This could happen if the job was created with args and then merged with context.
		$metadata = array(
			'job_id'            => $job_id,
			'operation_name'    => 'operations/test',
			'model'             => 'veo-3.1-generate-preview',
			'args'              => array(
				'user_id' => $this->user_id,
				'prompt'  => 'Test video',
			),
			'context'           => array(
				'user_id'      => $this->other_user_id, // Different user in context.
				'assistant_id' => 123,
			),
			'status'            => 'polling',
			'queued_at'         => time(),
			'poll_attempt'      => 0,
			'max_attempts'      => 60,
			'expected_filename' => 'veo-video-' . $job_id . '.mp4',
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Test that args.user_id takes precedence - owner from args can view.
		$result = $this->status_service->get_job_details( $job_id, $this->user_id );
		$this->assertNotWPError( $result, 'User from args.user_id should be able to view job' );
		$this->assertEquals( $job_id, $result['job_id'] );

		// Test that user from context.user_id cannot view (args takes precedence).
		$result = $this->status_service->get_job_details( $job_id, $this->other_user_id );
		$this->assertWPError( $result, 'User from context.user_id should NOT be able to view when args.user_id differs' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that get_async_status includes context field in response.
	 *
	 * Verifies that the context field is returned by get_async_status when present.
	 */
	public function test_get_async_status_includes_context() {
		$job_id = 'veo_test_' . uniqid();

		// Create job metadata with context field.
		$metadata = array(
			'job_id'            => $job_id,
			'operation_name'    => 'operations/test',
			'model'             => 'veo-3.1-generate-preview',
			'context'           => array(
				'user_id'      => $this->user_id,
				'assistant_id' => 123,
				'tool_call_id' => 'call_xyz',
			),
			'args'              => array(
				'prompt' => 'Test video',
			),
			'status'            => 'polling',
			'queued_at'         => time(),
			'poll_attempt'      => 0,
			'max_attempts'      => 60,
			'expected_filename' => 'veo-video-' . $job_id . '.mp4',
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Get status from service.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$result  = $service->get_async_status( $job_id );

		// Verify context field is included in response.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'context', $result, 'get_async_status should include context field' );
		$this->assertEquals( $this->user_id, $result['context']['user_id'] );
		$this->assertEquals( 123, $result['context']['assistant_id'] );
		$this->assertEquals( 'call_xyz', $result['context']['tool_call_id'] );

		// Verify args field is also included.
		$this->assertArrayHasKey( 'args', $result );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test permission check with no user_id in metadata.
	 *
	 * Edge case: job metadata has neither args.user_id nor context.user_id.
	 * Should deny access to non-admin users.
	 */
	public function test_job_permission_with_no_user_id() {
		$job_id = 'veo_test_' . uniqid();

		// Create job metadata without any user_id.
		$metadata = array(
			'job_id'            => $job_id,
			'operation_name'    => 'operations/test',
			'model'             => 'veo-3.1-generate-preview',
			'args'              => array(
				'prompt' => 'Test video',
			),
			'status'            => 'polling',
			'queued_at'         => time(),
			'poll_attempt'      => 0,
			'max_attempts'      => 60,
			'expected_filename' => 'veo-video-' . $job_id . '.mp4',
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Test that non-admin user cannot view job without user_id.
		$result = $this->status_service->get_job_details( $job_id, $this->user_id );
		$this->assertWPError( $result, 'Non-admin should not be able to view job without user_id' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Test that admin can still view the job.
		$result = $this->status_service->get_job_details( $job_id, $this->admin_id );
		$this->assertNotWPError( $result, 'Admin should be able to view job without user_id' );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}
}
