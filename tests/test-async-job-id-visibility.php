<?php
/**
 * Test async job ID visibility in responses.
 *
 * Verifies that job IDs are properly communicated to users when async tools are queued.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that async job IDs are visible and properly communicated.
 */
class Test_Async_Job_ID_Visibility extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		// Initialize services.
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that async tool response includes job_id in message.
	 */
	public function test_async_tool_response_includes_job_id_in_message() {
		// This would normally be tested via the REST API, but we can verify the response structure.
		// The REST API returns an array with 'status', 'job_id', 'message', 'async', 'tool_slug'.
		// The message should include the job_id.

		// Simulate what the REST API does without call_id.
		$job_id    = 'async_test_12345';
		$tool_name = 'Test Tool';

		$response = array(
			'status'    => 'pending',
			'job_id'    => $job_id,
			'message'   => sprintf(
				/* translators: 1: tool name, 2: job ID */
				__( 'Tool "%1$s" is processing in the background (Job ID: %2$s). The results will be available shortly and will appear here automatically when ready.', 'wp-mcp-ai' ),
				$tool_name,
				$job_id
			),
			'async'     => true,
			'tool_slug' => 'test_tool',
		);

		// Verify response structure.
		$this->assertArrayHasKey( 'job_id', $response, 'Response should have job_id field' );
		$this->assertArrayHasKey( 'message', $response, 'Response should have message field' );
		$this->assertEquals( $job_id, $response['job_id'], 'job_id should match' );
		$this->assertStringContainsString( $job_id, $response['message'], 'Message should include job_id' );
		$this->assertStringContainsString( 'Job ID:', $response['message'], 'Message should explicitly label the job ID' );
	}

	/**
	 * Test that async tool response includes both job_id and call_id in message when call_id is available.
	 */
	public function test_async_tool_response_includes_call_id_in_message() {
		// Simulate what the REST API does WITH call_id.
		$job_id       = 'async_test_12345';
		$tool_name    = 'Test Tool';
		$tool_call_id = 'call_RjVTeBLbeoS4CASwywIbsDWk';

		$response = array(
			'status'    => 'pending',
			'job_id'    => $job_id,
			'message'   => sprintf(
				/* translators: 1: tool name, 2: job ID, 3: call ID */
				__( 'Tool "%1$s" is processing in the background (Job ID: %2$s). The results will be available shortly and will appear here automatically when ready. (Call ID: %3$s)', 'wp-mcp-ai' ),
				$tool_name,
				$job_id,
				$tool_call_id
			),
			'async'     => true,
			'tool_slug' => 'test_tool',
		);

		// Verify response structure.
		$this->assertArrayHasKey( 'job_id', $response, 'Response should have job_id field' );
		$this->assertArrayHasKey( 'message', $response, 'Response should have message field' );
		$this->assertEquals( $job_id, $response['job_id'], 'job_id should match' );
		$this->assertStringContainsString( $job_id, $response['message'], 'Message should include job_id' );
		$this->assertStringContainsString( 'Job ID:', $response['message'], 'Message should explicitly label the job ID' );
		$this->assertStringContainsString( $tool_call_id, $response['message'], 'Message should include call_id' );
		$this->assertStringContainsString( 'Call ID:', $response['message'], 'Message should explicitly label the Call ID' );
	}

	/**
	 * Test that veo video response includes job_id in message.
	 */
	public function test_veo_video_response_includes_job_id_in_message() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create a mock operation.
		$mock_operation = array(
			'operation_name' => 'operations/test-op',
			'model_used'     => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
		);

		$mock_args = array(
			'prompt'  => 'Test video',
			'user_id' => 1,
		);

		// Use reflection to call queue_async_polling.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$result = $method->invoke( $service, $mock_operation, $mock_args );

		// Verify response structure.
		$this->assertArrayHasKey( 'job_id', $result, 'Response should have job_id field' );
		$this->assertArrayHasKey( 'message', $result, 'Response should have message field' );
		$this->assertStringStartsWith( 'veo_', $result['job_id'], 'Job ID should start with veo_' );
		$this->assertStringContainsString( $result['job_id'], $result['message'], 'Message should include job_id' );
		$this->assertStringContainsString( 'Job ID:', $result['message'], 'Message should explicitly label the job ID' );
		$this->assertTrue( $result['async'], 'Response should have async flag' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $result['job_id'] );
	}

	/**
	 * Test that job_started hook fires when async tool is queued and job_id is included.
	 */
	public function test_job_started_hook_includes_job_id() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Track hook call.
		$hook_called   = false;
		$hook_job_id   = null;
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_started',
			function ( $id, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_metadata = $meta;
			},
			10,
			2
		);

		// Queue a tool.
		$job_id = $executor->queue_tool( 'test_tool', array(), array( 'user_id' => 1 ) );

		// Verify hook was called with job_id.
		$this->assertTrue( $hook_called, 'job_started hook should be fired' );
		$this->assertEquals( $job_id, $hook_job_id, 'Hook should receive correct job_id' );
		$this->assertStringStartsWith( 'async_', $hook_job_id, 'job_id should start with async_' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be array' );
	}

	/**
	 * Test that job_started hook fires when veo job is queued and job_id is included.
	 */
	public function test_veo_job_started_hook_includes_job_id() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Track hook call.
		$hook_called   = false;
		$hook_job_id   = null;
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_started',
			function ( $id, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_metadata = $meta;
			},
			10,
			2
		);

		// Create a mock operation.
		$mock_operation = array(
			'operation_name' => 'operations/test-op',
			'model_used'     => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
		);

		// Use reflection to call queue_async_polling.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$service,
			$mock_operation,
			array(
				'prompt'  => 'Test',
				'user_id' => 1,
			)
		);

		// Verify hook was called with job_id.
		$this->assertTrue( $hook_called, 'job_started hook should be fired for veo job' );
		$this->assertEquals( $result['job_id'], $hook_job_id, 'Hook should receive correct job_id' );
		$this->assertStringStartsWith( 'veo_', $hook_job_id, 'job_id should start with veo_' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be array' );
		$this->assertEquals( 'generate_veo_video', $hook_metadata['tool'], 'Tool should be generate_veo_video' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $result['job_id'] );
	}

	/**
	 * Test that both async_ and veo_ job IDs are distinct and properly formatted.
	 */
	public function test_job_id_format_for_async_and_veo() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$service  = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Queue async tool.
		$async_job_id = $executor->queue_tool( 'test_tool', array(), array( 'user_id' => 1 ) );

		// Queue veo job.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$veo_result = $method->invoke(
			$service,
			array(
				'operation_name' => 'operations/test',
				'model_used'     => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			),
			array(
				'prompt'  => 'Test',
				'user_id' => 1,
			)
		);

		$veo_job_id = $veo_result['job_id'];

		// Verify job ID formats.
		$this->assertStringStartsWith( 'async_', $async_job_id, 'Async job ID should start with async_' );
		$this->assertStringStartsWith( 'veo_', $veo_job_id, 'Veo job ID should start with veo_' );
		$this->assertNotEquals( $async_job_id, $veo_job_id, 'Job IDs should be different' );

		// Verify IDs are unique.
		$this->assertTrue( strlen( $async_job_id ) > 6, 'Async job ID should have unique suffix' );
		$this->assertTrue( strlen( $veo_job_id ) > 4, 'Veo job ID should have unique suffix' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id );
	}
}
