<?php
/**
 * Test async video generation functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test async video generation with cron fallback.
 */
class Test_Veo_Async_Video_Generation extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-video-status.php';

		// Initialize service hooks.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
	}

	/**
	 * Test that service registers cron hook.
	 */
	public function test_service_registers_cron_hook() {
		global $wp_filter;

		$hook = WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK;
		$this->assertTrue( isset( $wp_filter[ $hook ] ), 'Cron hook should be registered' );
	}

	/**
	 * Test async job queueing.
	 */
	public function test_async_job_queueing() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$args = array(
			'prompt'  => 'Test video generation',
			'async'   => true,
			'user_id' => 1,
		);

		// Mock the submit_generation_request to avoid actual API calls.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$operation = array(
			'operation_name' => 'operations/test-operation-123',
			'metadata'       => array(),
		);

		$result = $method->invoke( $service, $operation, $args );

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertEquals( 'pending', $result['status'] );
		$this->assertStringStartsWith( 'veo_', $result['job_id'] );

		// Verify transient was created.
		$job_id   = $result['job_id'];
		$metadata = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );

		$this->assertIsArray( $metadata );
		$this->assertEquals( $job_id, $metadata['job_id'] );
		$this->assertEquals( 'pending', $metadata['status'] );
		$this->assertEquals( $operation['operation_name'], $metadata['operation_name'] );
		$this->assertEquals( 0, $metadata['poll_attempt'] );
	}

	/**
	 * Test get async status for pending job.
	 */
	public function test_get_async_status_pending() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create a mock job.
		$job_id   = 'veo_test123';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test' ),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		$status = $service->get_async_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertEquals( 'pending', $status['status'] );
		$this->assertEquals( $job_id, $status['job_id'] );
		$this->assertEquals( 0, $status['poll_attempt'] );
		$this->assertEquals( 60, $status['max_attempts'] );
	}

	/**
	 * Test get async status for non-existent job.
	 */
	public function test_get_async_status_not_found() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$status  = $service->get_async_status( 'nonexistent_job' );

		$this->assertWPError( $status );
		$this->assertEquals( 'wp_mcp_ai_job_not_found', $status->get_error_code() );
	}

	/**
	 * Test video generation tool with async mode.
	 */
	public function test_tool_async_mode() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Test that should_use_async returns true by default.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, array() );
		$this->assertTrue( $result, 'Should use async mode by default' );

		// Test explicit async parameter.
		$result = $method->invoke( $tool, array( 'async' => false ) );
		$this->assertFalse( $result, 'Should respect explicit async=false' );

		$result = $method->invoke( $tool, array( 'async' => true ) );
		$this->assertTrue( $result, 'Should respect explicit async=true' );
	}

	/**
	 * Test check video status tool.
	 */
	public function test_check_video_status_tool() {
		$tool = new WP_MCP_AI_Tool_Check_Video_Status();

		// Test tool metadata.
		$this->assertEquals( 'check_video_status', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		// Test schema.
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'job_id', $schema['properties'] );
		$this->assertContains( 'job_id', $schema['required'] );
	}

	/**
	 * Test check video status tool execution.
	 */
	public function test_check_video_status_execution() {
		$tool = new WP_MCP_AI_Tool_Check_Video_Status();

		// Create a mock completed job.
		$job_id   = 'veo_test456';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test video' ),
			'status'         => 'completed',
			'queued_at'      => time() - 120,
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
			'result'         => array(
				'attachment_id' => 123,
				'url'           => 'http://example.com/video.mp4',
				'prompt'        => 'Test video',
			),
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		$result = $tool->execute(
			array( 'job_id' => $job_id ),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( $job_id, $result['job_id'] );
		$this->assertEquals( 123, $result['attachment_id'] );
	}

	/**
	 * Test check video status with missing job ID.
	 */
	public function test_check_video_status_missing_job_id() {
		$tool   = new WP_MCP_AI_Tool_Check_Video_Status();
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_job_id', $result->get_error_code() );
	}

	/**
	 * Test scheduling next poll.
	 */
	public function test_schedule_next_poll() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$job_id   = 'veo_test789';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test', 'user_id' => 1 ),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 1,
			'max_attempts'   => 60,
		);

		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );

		$method->invoke( $service, $job_id, $metadata );

		// Verify metadata was updated.
		$updated = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		$this->assertIsArray( $updated );
		$this->assertEquals( 'polling', $updated['status'] );

		// Verify cron job was scheduled.
		$hook      = WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK;
		$scheduled = wp_next_scheduled( $hook, array( $job_id ) );
		$this->assertNotFalse( $scheduled, 'Cron job should be scheduled' );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'long-running', $flags );
		$this->assertContains( 'may-timeout', $flags );
	}

	/**
	 * Test check video status capability flags.
	 */
	public function test_check_status_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Check_Video_Status();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read', $flags );
		$this->assertContains( 'requires-credentials', $flags );
	}
}
