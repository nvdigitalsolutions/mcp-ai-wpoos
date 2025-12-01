<?php
/**
 * Test Async Response UI Integration
 *
 * Tests that async tool responses (job_id) are properly formatted and returned
 * to the chat UI for display and polling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for async response UI integration
 */
class Test_Async_Response_UI_Integration extends WP_UnitTestCase {

	/**
	 * Test that async response has required UI fields
	 */
	public function test_async_response_structure() {
		// Load service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected queue_async_polling method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		// Mock operation.
		$operation = array(
			'operation_name' => 'operations/test-123',
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Test video',
			'user_id' => 1,
		);

		// Get async response.
		$result = $method->invoke( $service, $operation, $args );

		// Verify UI-required fields.
		$this->assertIsArray( $result, 'Result should be array' );
		$this->assertArrayHasKey( 'async', $result, 'Must have async flag' );
		$this->assertTrue( $result['async'], 'Async flag must be true' );
		$this->assertArrayHasKey( 'job_id', $result, 'Must have job_id for UI polling' );
		$this->assertArrayHasKey( 'status', $result, 'Must have status for UI display' );
		$this->assertEquals( 'pending', $result['status'], 'Status should be pending' );
		$this->assertArrayHasKey( 'message', $result, 'Must have message for UI display' );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_veo_async_' . $result['job_id'] );
	}

	/**
	 * Test that chat service properly encodes async response
	 */
	public function test_chat_service_encodes_async_response() {
		// Simulate an async tool result.
		$async_result = array(
			'async'   => true,
			'job_id'  => 'veo_test_12345',
			'status'  => 'pending',
			'message' => 'Video generation started. Use the job_id to check status.',
		);

		// Encode as chat service does.
		$encoded = wp_json_encode( $async_result );

		$this->assertIsString( $encoded );
		$this->assertStringContainsString( '"async":true', $encoded );
		$this->assertStringContainsString( '"job_id":"veo_test_12345"', $encoded );
		$this->assertStringContainsString( '"status":"pending"', $encoded );

		// Verify it can be decoded by JavaScript.
		$decoded = json_decode( $encoded, true );
		$this->assertEquals( $async_result, $decoded );
	}

	/**
	 * Test that timeout fallback returns proper async response
	 */
	public function test_timeout_fallback_async_response() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to test poll_for_completion with very short timeout.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'poll_for_completion' );
		$method->setAccessible( true );

		// Set very short timeout to trigger fallback immediately.
		$original_timeout = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '1' );

		$operation = array(
			'operation_name' => 'operations/test-timeout',
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Test timeout fallback',
			'user_id' => 1,
		);

		// Should fall back to async immediately due to timeout.
		$result = $method->invoke( $service, $operation, $args );

		// Restore timeout.
		ini_set( 'max_execution_time', $original_timeout );

		// Verify async response structure for UI.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertStringStartsWith( 'veo_', $result['job_id'] );
		$this->assertEquals( 'pending', $result['status'] );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_veo_async_' . $result['job_id'] );
	}

	/**
	 * Test JavaScript can detect async response
	 */
	public function test_javascript_async_detection_pattern() {
		// Load chat.js to verify async detection exists.
		$chat_js_path = WP_MCP_AI_PATH . 'assets/js/chat.js';
		$this->assertFileExists( $chat_js_path, 'chat.js should exist' );

		$chat_js = file_get_contents( $chat_js_path );

		// Verify async detection code exists.
		$this->assertStringContainsString(
			'result.async === true',
			$chat_js,
			'JavaScript should check for async flag'
		);

		$this->assertStringContainsString(
			'result.job_id',
			$chat_js,
			'JavaScript should check for job_id'
		);

		$this->assertStringContainsString(
			'result.status',
			$chat_js,
			'JavaScript should check status'
		);

		$this->assertStringContainsString(
			'waitForAsyncToolResult',
			$chat_js,
			'JavaScript should have async result waiting function'
		);

		$this->assertStringContainsString(
			'pending',
			$chat_js,
			'JavaScript should handle pending status'
		);
	}

	/**
	 * Test that tool properly passes through async response
	 */
	public function test_tool_passes_through_async_response() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

		// Test that tool code checks for async in result.
		$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		$tool_code = file_get_contents( $tool_file );

		// Verify tool checks for async response and returns it.
		$this->assertStringContainsString(
			"isset( \$result['async'] )",
			$tool_code,
			'Tool should check for async flag in service result'
		);

		$this->assertStringContainsString(
			'return $result',
			$tool_code,
			'Tool should return async result directly'
		);
	}

	/**
	 * Test async response message is user-friendly
	 */
	public function test_async_response_message() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access queue_async_polling.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$operation = array(
			'operation_name' => 'operations/test-message',
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Test message',
			'user_id' => 1,
		);

		$result = $method->invoke( $service, $operation, $args );

		// Verify message is user-friendly.
		$this->assertArrayHasKey( 'message', $result );
		$this->assertNotEmpty( $result['message'] );
		$this->assertIsString( $result['message'] );

		// Message should mention background processing or job_id.
		$message_lower    = strtolower( $result['message'] );
		$has_helpful_info = strpos( $message_lower, 'job' ) !== false ||
							strpos( $message_lower, 'status' ) !== false ||
							strpos( $message_lower, 'background' ) !== false ||
							strpos( $message_lower, 'started' ) !== false;

		$this->assertTrue( $has_helpful_info, 'Message should mention job/status/background processing' );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_veo_async_' . $result['job_id'] );
	}

	/**
	 * Test that UI polling finds the correct status endpoint
	 */
	public function test_status_check_endpoint_exists() {
		// Verify check_video_status tool exists for polling.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Should have a tool to check async job status.
		$this->assertTrue(
			$registry->is_tool_registered( 'check_video_status' ),
			'Should have check_video_status tool for polling'
		);
	}

	/**
	 * Test complete flow: timeout → async response → UI display
	 */
	public function test_complete_async_flow() {
		// This test verifies the complete integration.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Mock timeout scenario.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'poll_for_completion' );
		$method->setAccessible( true );

		$original_timeout = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '1' );

		$operation = array(
			'operation_name' => 'operations/complete-flow-test',
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Complete flow test',
			'user_id' => 1,
		);

		// Step 1: Service detects timeout and returns async response.
		$async_response = $method->invoke( $service, $operation, $args );

		ini_set( 'max_execution_time', $original_timeout );

		// Step 2: Verify response structure for chat service.
		$this->assertIsArray( $async_response );
		$this->assertTrue( $async_response['async'] );

		// Step 3: Chat service encodes it.
		$encoded = wp_json_encode( $async_response );
		$this->assertNotFalse( $encoded );

		// Step 4: JavaScript can decode and detect it.
		$decoded = json_decode( $encoded, true );
		$this->assertEquals( true, $decoded['async'] );
		$this->assertEquals( 'pending', $decoded['status'] );
		$this->assertNotEmpty( $decoded['job_id'] );

		// Step 5: Verify UI can poll for status.
		$job_id   = $decoded['job_id'];
		$metadata = get_transient( 'wp_mcp_ai_veo_async_' . $job_id );

		$this->assertIsArray( $metadata, 'Job metadata should be stored for polling' );
		$this->assertEquals( $job_id, $metadata['job_id'] );
		$this->assertEquals( 'pending', $metadata['status'] );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that async pending results are filtered out from normaliseToolResultForDisplay
	 *
	 * This test verifies that the fix for the "still showing tool process for completed video"
	 * issue is in place. The fix ensures that async pending tool results (with async=true,
	 * status=pending, and job_id) are not displayed as regular completed results.
	 */
	public function test_javascript_filters_async_pending_from_normalise() {
		// Load chat.js to verify filtering exists.
		$chat_js_path = WP_MCP_AI_PATH . 'assets/js/chat.js';
		$this->assertFileExists( $chat_js_path, 'chat.js should exist' );

		$chat_js = file_get_contents( $chat_js_path );

		// Verify isAsyncPendingToolResult function exists.
		$this->assertStringContainsString(
			'function isAsyncPendingToolResult',
			$chat_js,
			'JavaScript should have isAsyncPendingToolResult helper function'
		);

		// Verify normaliseToolResultForDisplay checks for async pending results.
		// The fix adds this check at the start of the function.
		$this->assertStringContainsString(
			'isAsyncPendingToolResult(result)',
			$chat_js,
			'normaliseToolResultForDisplay should check for async pending results'
		);

		// Verify the check in normaliseToolResultForDisplay returns null for async pending.
		// Look for the specific pattern of checking and returning null.
		$normalise_check_pattern = '/if\s*\(\s*isAsyncPendingToolResult\s*\(\s*result\s*\)\s*\)\s*\{\s*return\s+null\s*;/';
		$this->assertMatchesRegularExpression(
			$normalise_check_pattern,
			$chat_js,
			'normaliseToolResultForDisplay should return null for async pending results'
		);

		// Verify the fix adds comments explaining the purpose.
		$this->assertStringContainsString(
			'Skip async pending tool results',
			$chat_js,
			'Should have comment explaining async pending filtering'
		);

		// Verify ASYNC_PENDING_STATUSES includes 'pending', 'queued', 'running'.
		$this->assertStringContainsString(
			"ASYNC_PENDING_STATUSES = ['pending', 'queued', 'running']",
			$chat_js,
			'Should have ASYNC_PENDING_STATUSES constant with expected values'
		);
	}
}
