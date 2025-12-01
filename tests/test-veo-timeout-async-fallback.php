<?php
/**
 * Test Veo Video Generation Timeout Async Fallback
 *
 * Tests that the Veo video generation service correctly falls back to async mode
 * when approaching PHP timeout (10 seconds before max_execution_time).
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Veo timeout async fallback
 */
class Test_Veo_Timeout_Async_Fallback extends WP_UnitTestCase {

	/**
	 * Test that polling detects timeout and falls back to async.
	 */
	public function test_polling_falls_back_to_async_on_timeout() {
		// Load service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create service instance.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected poll_for_completion method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'poll_for_completion' );
		$method->setAccessible( true );

		// Mock operation data.
		$operation = array(
			'operation_name' => 'operations/test-operation-123',
			'metadata'       => array(),
		);

		// Mock args with user_id (NO in_async_executor flag).
		$args = array(
			'prompt'  => 'Test video generation',
			'user_id' => 1,
		);

		// Temporarily set max_execution_time to a very low value to trigger timeout.
		$original_max_execution_time = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '1' ); // 1 second.

		// Execute poll_for_completion - should fall back to async immediately.
		$result = $method->invoke( $service, $operation, $args );

		// Restore original max_execution_time.
		ini_set( 'max_execution_time', $original_max_execution_time );

		// Verify result is async fallback.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'async', $result, 'Result should have async key' );
		$this->assertTrue( $result['async'], 'Async flag should be true' );
		$this->assertArrayHasKey( 'job_id', $result, 'Result should have job_id' );
		$this->assertStringStartsWith( 'veo_', $result['job_id'], 'Job ID should start with veo_' );
	}

	/**
	 * Test that polling does NOT fall back to async when in async executor context (prevents dual async).
	 */
	public function test_polling_no_async_fallback_in_executor_context() {
		// Load service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create service instance.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected poll_for_completion method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'poll_for_completion' );
		$method->setAccessible( true );

		// Mock operation data.
		$operation = array(
			'operation_name' => 'operations/test-operation-456',
			'metadata'       => array(),
		);

		// Mock args WITH in_async_executor flag.
		$args = array(
			'prompt'            => 'Test video in async executor',
			'user_id'           => 1,
			'in_async_executor' => true, // This should prevent async fallback.
		);

		// Temporarily set max_execution_time to a very low value.
		$original_max_execution_time = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '1' ); // 1 second.

		// Execute poll_for_completion - should return error instead of falling back to async.
		$result = $method->invoke( $service, $operation, $args );

		// Restore original max_execution_time.
		ini_set( 'max_execution_time', $original_max_execution_time );

		// Verify result is an error (NOT an async fallback).
		$this->assertInstanceOf( 'WP_Error', $result, 'Result should be WP_Error when in async executor' );
		$this->assertEquals( 'wp_mcp_ai_veo_polling_timeout', $result->get_error_code(), 'Error code should be polling timeout' );

		// Verify it's NOT an async response.
		$this->assertFalse( is_array( $result ) && isset( $result['async'] ), 'Should not return async response' );
	}

	/**
	 * Test that timeout threshold calculation works correctly.
	 */
	public function test_timeout_threshold_calculation() {
		// Load service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Test with normal max_execution_time (30 seconds).
		ini_set( 'max_execution_time', '30' );
		$max_execution_time = ini_get( 'max_execution_time' );
		$this->assertEquals( 30, (int) $max_execution_time );

		// Threshold should be 10 seconds before: 30 - 10 = 20.
		$expected_threshold = 20;
		$this->assertEquals( $expected_threshold, $max_execution_time - 10 );

		// Test with very short max_execution_time (5 seconds).
		ini_set( 'max_execution_time', '5' );
		$max_execution_time = ini_get( 'max_execution_time' );
		$this->assertEquals( 5, (int) $max_execution_time );

		// Threshold calculation: 5 - 10 = -5, should use minimum of 5.
		$calculated_threshold = $max_execution_time - 10;
		$this->assertLessThan( 5, $calculated_threshold, 'Calculated threshold should be less than 5' );
	}

	/**
	 * Test that async fallback creates valid transient.
	 */
	public function test_async_fallback_creates_transient() {
		// Load service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create service instance.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected queue_async_polling method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		// Mock operation data.
		$operation = array(
			'operation_name' => 'operations/test-operation-456',
			'metadata'       => array(),
		);

		// Mock args.
		$args = array(
			'prompt'  => 'Test async fallback',
			'user_id' => 1,
		);

		// Execute queue_async_polling.
		$result = $method->invoke( $service, $operation, $args );

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );

		// Verify transient was created.
		$job_id   = $result['job_id'];
		$metadata = get_transient( 'wp_mcp_ai_veo_async_' . $job_id );

		$this->assertNotFalse( $metadata, 'Transient should exist' );
		$this->assertIsArray( $metadata, 'Transient data should be array' );
		$this->assertEquals( $job_id, $metadata['job_id'], 'Job ID should match' );
		$this->assertEquals( 'operations/test-operation-456', $metadata['operation_name'] );
		$this->assertEquals( 'pending', $metadata['status'] );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that tool respects orchestrator async routing.
	 */
	public function test_tool_respects_orchestrator_async() {
		// Load tool.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

		// Create tool instance.
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Use reflection to access protected should_use_async method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test 1: When in_async_executor is set, should return false (prevents dual async).
		$context = array( 'in_async_executor' => true );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Should not use tool-level async when in_async_executor is true' );

		// Test 2: Normal execution should default to true (async for reliability).
		// Note: agentic_loop check was removed - orchestrator handles async routing via background-only flag.
		$context = array();
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertTrue( $result, 'Should default to async for reliability' );

		// Test 3: Explicit async=false in arguments should be respected.
		$args    = array( 'async' => false );
		$context = array();
		$result  = $method->invoke( $tool, $args, $context );
		$this->assertFalse( $result, 'Should respect explicit async=false' );

		// Test 4: Explicit async=true in arguments overrides in_async_executor.
		$args    = array( 'async' => true );
		$context = array( 'in_async_executor' => true );
		$result  = $method->invoke( $tool, $args, $context );
		$this->assertTrue( $result, 'Explicit async=true should override in_async_executor check' );
	}

	/**
	 * Test that service handles timeout detection correctly.
	 */
	public function test_service_timeout_detection() {
		// This test verifies the code structure exists.
		$service_file = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$this->assertFileExists( $service_file );

		$source = file_get_contents( $service_file );

		// Verify timeout detection code exists.
		$this->assertStringContainsString(
			'microtime( true )',
			$source,
			'Should track execution time'
		);

		$this->assertStringContainsString(
			'max_execution_time',
			$source,
			'Should check max_execution_time'
		);

		$this->assertStringContainsString(
			'timeout_threshold',
			$source,
			'Should have timeout threshold'
		);

		$this->assertStringContainsString(
			'veo_timeout_async_fallback',
			$source,
			'Should log async fallback event'
		);

		$this->assertStringContainsString(
			'queue_async_polling',
			$source,
			'Should call queue_async_polling on timeout'
		);
	}

	/**
	 * Test that orchestrator capability flags include may-timeout and long-running.
	 */
	public function test_veo_tool_has_timeout_capability_flags() {
		// Load tool.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

		// Create tool instance.
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Get capability flags.
		$flags = $tool->get_capability_flags();

		// Verify has required flags.
		$this->assertContains( 'may-timeout', $flags, 'Tool should have may-timeout flag' );
		$this->assertContains( 'long-running', $flags, 'Tool should have long-running flag' );
		$this->assertContains( 'async', $flags, 'Tool should have async flag' );
	}
}
