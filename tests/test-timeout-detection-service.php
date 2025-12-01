<?php
/**
 * Test Timeout Detection Service
 *
 * Tests the reusable timeout detection service that all long-running tools can use.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for timeout detection service
 */
class Test_Timeout_Detection_Service extends WP_UnitTestCase {

	/**
	 * Test basic timeout detection initialization
	 */
	public function test_timeout_detector_initialization() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		// Set max_execution_time to known value.
		ini_set( 'max_execution_time', '30' );

		$detector = new WP_MCP_AI_Timeout_Detection_Service();

		// Verify max_execution_time is read correctly.
		$this->assertEquals( 30, $detector->get_max_execution_time() );

		// Verify threshold is calculated correctly (30 - 10 = 20).
		$this->assertEquals( 20, $detector->get_timeout_threshold() );

		// Verify elapsed time is very small at start.
		$this->assertLessThan( 1, $detector->get_elapsed_time() );

		// Verify not approaching timeout initially.
		$this->assertFalse( $detector->is_approaching_timeout() );
	}

	/**
	 * Test custom safety buffer
	 */
	public function test_custom_safety_buffer() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		ini_set( 'max_execution_time', '60' );

		// Use 15 second safety buffer instead of default 10.
		$detector = new WP_MCP_AI_Timeout_Detection_Service( 15 );

		$this->assertEquals( 60, $detector->get_max_execution_time() );
		$this->assertEquals( 45, $detector->get_timeout_threshold(), 'Threshold should be 60 - 15 = 45' );
	}

	/**
	 * Test minimum threshold enforcement
	 */
	public function test_minimum_threshold_enforcement() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		// Set very short max_execution_time.
		ini_set( 'max_execution_time', '5' );

		// With default 10 second buffer, threshold would be 5 - 10 = -5.
		// Should enforce minimum of 5 seconds.
		$detector = new WP_MCP_AI_Timeout_Detection_Service();

		$this->assertEquals( 5, $detector->get_timeout_threshold(), 'Should use minimum threshold of 5' );
	}

	/**
	 * Test timeout approaching detection
	 */
	public function test_timeout_approaching_detection() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		// Set very short timeout for testing.
		ini_set( 'max_execution_time', '2' );

		$detector = new WP_MCP_AI_Timeout_Detection_Service( 1 ); // 1 second buffer.

		// Should be threshold of 1 second (2 - 1).
		$this->assertEquals( 1, $detector->get_timeout_threshold() );

		// Not approaching timeout yet.
		$this->assertFalse( $detector->is_approaching_timeout() );

		// Wait 1.1 seconds to exceed threshold (shorter than original 1.5s for faster tests).
		usleep( 1100000 ); // 1.1 seconds in microseconds.

		// Should now be approaching timeout.
		$this->assertTrue( $detector->is_approaching_timeout() );
		$this->assertGreaterThan( 1, $detector->get_elapsed_time() );
		$this->assertLessThan( 0, $detector->get_remaining_time() );
	}

	/**
	 * Test metadata for logging
	 */
	public function test_metadata_generation() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		ini_set( 'max_execution_time', '30' );
		$detector = new WP_MCP_AI_Timeout_Detection_Service();

		$metadata = $detector->get_metadata();

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'elapsed_time', $metadata );
		$this->assertArrayHasKey( 'remaining_time', $metadata );
		$this->assertArrayHasKey( 'timeout_threshold', $metadata );
		$this->assertArrayHasKey( 'max_execution_time', $metadata );
		$this->assertArrayHasKey( 'approaching_timeout', $metadata );

		$this->assertEquals( 30, $metadata['max_execution_time'] );
		$this->assertEquals( 20, $metadata['timeout_threshold'] );
		$this->assertFalse( $metadata['approaching_timeout'] );
	}

	/**
	 * Test should_use_timeout_detection with various flags
	 */
	public function test_should_use_timeout_detection() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		// Test with may-timeout flag.
		$flags = array( 'may-timeout', 'write', 'external-api' );
		$this->assertTrue(
			WP_MCP_AI_Timeout_Detection_Service::should_use_timeout_detection( $flags ),
			'Should use timeout detection for may-timeout flag'
		);

		// Test with long-running flag.
		$flags = array( 'long-running', 'write' );
		$this->assertTrue(
			WP_MCP_AI_Timeout_Detection_Service::should_use_timeout_detection( $flags ),
			'Should use timeout detection for long-running flag'
		);

		// Test with async flag.
		$flags = array( 'async', 'external-api' );
		$this->assertTrue(
			WP_MCP_AI_Timeout_Detection_Service::should_use_timeout_detection( $flags ),
			'Should use timeout detection for async flag'
		);

		// Test without any timeout flags.
		$flags = array( 'write', 'external-api' );
		$this->assertFalse(
			WP_MCP_AI_Timeout_Detection_Service::should_use_timeout_detection( $flags ),
			'Should not use timeout detection without timeout flags'
		);

		// Test with empty flags.
		$flags = array();
		$this->assertFalse(
			WP_MCP_AI_Timeout_Detection_Service::should_use_timeout_detection( $flags ),
			'Should not use timeout detection with empty flags'
		);
	}

	/**
	 * Test create_if_applicable factory method
	 */
	public function test_create_if_applicable() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		// Test with applicable flags.
		$flags    = array( 'may-timeout', 'write' );
		$detector = WP_MCP_AI_Timeout_Detection_Service::create_if_applicable( $flags );

		$this->assertInstanceOf(
			'WP_MCP_AI_Timeout_Detection_Service',
			$detector,
			'Should create detector for applicable flags'
		);

		// Test with non-applicable flags.
		$flags    = array( 'write', 'read' );
		$detector = WP_MCP_AI_Timeout_Detection_Service::create_if_applicable( $flags );

		$this->assertNull(
			$detector,
			'Should return null for non-applicable flags'
		);

		// Test with custom safety buffer.
		$flags    = array( 'long-running' );
		$detector = WP_MCP_AI_Timeout_Detection_Service::create_if_applicable( $flags, 15 );

		$this->assertInstanceOf( 'WP_MCP_AI_Timeout_Detection_Service', $detector );
		ini_set( 'max_execution_time', '60' );
		$detector2 = WP_MCP_AI_Timeout_Detection_Service::create_if_applicable( $flags, 15 );
		$this->assertEquals( 45, $detector2->get_timeout_threshold(), 'Should use custom buffer' );
	}

	/**
	 * Test integration with Veo video generation
	 */
	public function test_veo_tool_integration() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		$tool  = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$flags = $tool->get_capability_flags();

		// Verify Veo tool should use timeout detection.
		$this->assertTrue(
			WP_MCP_AI_Timeout_Detection_Service::should_use_timeout_detection( $flags ),
			'Veo tool should use timeout detection'
		);

		// Verify detector can be created for Veo tool.
		$detector = WP_MCP_AI_Timeout_Detection_Service::create_if_applicable( $flags );
		$this->assertInstanceOf( 'WP_MCP_AI_Timeout_Detection_Service', $detector );
	}

	/**
	 * Test unlimited max_execution_time handling
	 */
	public function test_unlimited_execution_time() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		// Set max_execution_time to 0 (unlimited).
		ini_set( 'max_execution_time', '0' );

		$detector = new WP_MCP_AI_Timeout_Detection_Service();

		// Should default to 30 seconds when unlimited.
		$this->assertEquals( 30, $detector->get_max_execution_time() );
		$this->assertEquals( 20, $detector->get_timeout_threshold() );
	}

	/**
	 * Test elapsed time tracking
	 */
	public function test_elapsed_time_tracking() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';

		$detector = new WP_MCP_AI_Timeout_Detection_Service();

		// Initial elapsed time should be near zero.
		$elapsed1 = $detector->get_elapsed_time();
		$this->assertLessThan( 0.1, $elapsed1 );

		// Wait a bit.
		usleep( 500000 ); // 0.5 seconds.

		// Elapsed time should increase.
		$elapsed2 = $detector->get_elapsed_time();
		$this->assertGreaterThan( $elapsed1, $elapsed2 );
		$this->assertGreaterThan( 0.4, $elapsed2 ); // At least 0.4 seconds.
		$this->assertLessThan( 1, $elapsed2 ); // Less than 1 second.
	}
}
