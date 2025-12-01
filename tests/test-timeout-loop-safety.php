<?php
/**
 * Tests for timeout and loop safety in async/streaming operations.
 *
 * Verifies that:
 * - SSE streams don't hang indefinitely
 * - Connection abortion is detected properly
 * - Maximum iteration limits prevent infinite loops
 * - Ollama/local LLM timeouts work correctly
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sse-stream.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';

/**
 * Test timeout and loop safety.
 */
class Test_Timeout_Loop_Safety extends WP_UnitTestCase {

	/**
	 * Test SSE stream has maximum duration limit.
	 */
	public function test_sse_stream_max_duration_limit() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'build_sse_stream' );
		$method->setAccessible( true );

		// Set very short max duration for testing.
		$max_duration  = 2; // 2 seconds.
		$poll_interval = 1;

		$start_time = time();
		$stream     = $method->invokeArgs( null, array( 'test_job_id', $max_duration, $poll_interval ) );
		$end_time   = time();

		$duration = $end_time - $start_time;

		// Should not exceed max_duration significantly (allow 1 second margin for processing).
		$this->assertLessThanOrEqual(
			$max_duration + 1,
			$duration,
			'SSE stream should respect maximum duration limit'
		);

		// Stream should contain timeout message.
		$this->assertStringContainsString( 'timeout', $stream );
	}

	/**
	 * Test SSE stream iteration limit prevents infinite loops.
	 */
	public function test_sse_stream_iteration_limit() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'build_sse_stream' );
		$method->setAccessible( true );

		// Set parameters that could cause many iterations.
		$max_duration  = 10; // 10 seconds.
		$poll_interval = 1; // Poll every second.

		// Expected max iterations = ceil(10/1) + 10 = 20.
		// Even if time() manipulation fails, iteration count should limit loops.

		$start_time = microtime( true );
		$stream     = $method->invokeArgs( null, array( 'test_job_id', $max_duration, $poll_interval ) );
		$end_time   = microtime( true );

		$duration = $end_time - $start_time;

		// Should not hang indefinitely.
		$this->assertLessThan(
			15, // Should finish well before 15 seconds.
			$duration,
			'SSE stream should have iteration limit to prevent infinite loops'
		);
	}

	/**
	 * Test SSE stream handles connection abortion gracefully.
	 */
	public function test_sse_stream_connection_abortion() {
		// This test verifies the code checks connection_aborted().
		// We can't actually abort a connection in unit tests, but we can verify.
		// the code path exists.

		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'build_sse_stream' );
		$method->setAccessible( true );

		// Get source code to verify connection_aborted check exists.
		$filename = $reflection->getFileName();
		$source   = file_get_contents( $filename );

		$this->assertStringContainsString(
			'connection_aborted',
			$source,
			'SSE stream should check for connection abortion'
		);

		// Run actual stream to ensure no syntax errors.
		$stream = $method->invokeArgs( null, array( 'test_job_id', 2, 1 ) );
		$this->assertIsString( $stream );
	}

	/**
	 * Test Ollama client has reasonable timeout.
	 */
	public function test_ollama_client_timeout() {
		$client = new WP_MCP_AI_Ollama_Client();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'resolve_timeout' );
		$method->setAccessible( true );

		// Test default timeout.
		$timeout = $method->invoke( $client, array() );
		$this->assertGreaterThan( 0, $timeout, 'Ollama timeout should be positive' );
		$this->assertLessThanOrEqual( 600, $timeout, 'Ollama timeout should not exceed 10 minutes' );

		// Test with explicit timeout option.
		$custom_timeout = $method->invoke( $client, array( 'timeout' => 60 ) );
		$this->assertEquals( 60, $custom_timeout, 'Custom timeout should be respected' );

		// Test minimum timeout enforced.
		$min_timeout = $method->invoke( $client, array( 'timeout' => 1 ) );
		$this->assertGreaterThanOrEqual( 5, $min_timeout, 'Minimum timeout of 5 seconds should be enforced' );
	}

	/**
	 * Test async executor doesn't loop forever on missing tool.
	 */
	public function test_async_executor_missing_tool_timeout() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Queue a non-existent tool.
		$job_id = $executor->queue_tool( 'nonexistent_tool', array(), array( 'user_id' => 1 ) );

		// Execute it.
		$start_time = microtime( true );
		do_action( WP_MCP_AI_Tool_Async_Executor::CRON_HOOK, $job_id );
		$end_time = microtime( true );

		$duration = $end_time - $start_time;

		// Should fail quickly, not hang.
		$this->assertLessThan( 5, $duration, 'Missing tool should fail quickly, not hang' );

		// Verify it failed.
		$result = $executor->get_result( $job_id );
		$this->assertEquals( 'failed', $result['status'] );
	}

	/**
	 * Test SSE stream parameters are validated.
	 */
	public function test_sse_stream_parameter_validation() {
		// Test that parameters are bounded.
		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'stream_job_status' );
		$method->setAccessible( true );

		// Test with extreme values.
		$response = $method->invokeArgs( null, array( 'test_job', 9999, 100 ) );

		// Response should be created without errors.
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		// Headers should be set correctly.
		$headers = $response->get_headers();
		$this->assertEquals( 'text/event-stream; charset=UTF-8', $headers['Content-Type'] );
	}

	/**
	 * Test that very short poll interval doesn't cause issues.
	 */
	public function test_sse_stream_short_poll_interval() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'build_sse_stream' );
		$method->setAccessible( true );

		// Use minimum poll interval.
		$max_duration  = 2;
		$poll_interval = 1; // Minimum is 1 second.

		$start_time = time();
		$stream     = $method->invokeArgs( null, array( 'test_job', $max_duration, $poll_interval ) );
		$end_time   = time();

		// Should complete without hanging.
		$this->assertLessThan( 5, $end_time - $start_time );
		$this->assertIsString( $stream );
	}

	/**
	 * Test connection headers prevent browser caching.
	 */
	public function test_sse_stream_no_cache_headers() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'stream_job_status' );
		$method->setAccessible( true );

		$response = $method->invokeArgs( null, array( 'test_job', 2, 1 ) );
		$headers  = $response->get_headers();

		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
		$this->assertEquals( 'no', $headers['X-Accel-Buffering'] );
	}

	/**
	 * Test heartbeat prevents connection timeout.
	 */
	public function test_sse_stream_heartbeat() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SSE_Stream' );
		$method     = $reflection->getMethod( 'build_sse_stream' );
		$method->setAccessible( true );

		// Run for long enough to generate heartbeat.
		$stream = $method->invokeArgs( null, array( 'test_job', 5, 1 ) );

		// Should contain heartbeat comments.
		$this->assertStringContainsString( ': heartbeat', $stream, 'Stream should include heartbeat comments' );
	}

	/**
	 * Test that timeout safety doesn't break normal operation.
	 */
	public function test_timeout_safety_preserves_functionality() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Queue a normal job.
		$job_id = $executor->queue_tool( 'test_tool', array( 'param' => 'value' ), array( 'user_id' => 1 ) );

		// Should succeed.
		$this->assertIsString( $job_id );

		// Result should be retrievable.
		$result = $executor->get_result( $job_id );
		$this->assertIsArray( $result );
		$this->assertEquals( 'pending', $result['status'] );
	}
}
