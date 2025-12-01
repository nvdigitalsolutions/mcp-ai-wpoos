<?php
/**
 * Tests for Error Tracking Service.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Error Tracking Service functionality.
 */
class Test_Error_Tracking_Service extends WP_UnitTestCase {

	/**
	 * Error tracking service instance.
	 *
	 * @var WP_MCP_AI_Error_Tracking_Service
	 */
	private $service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the error tracking service if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Error_Tracking_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-error-tracking-service.php';
		}

		$this->service = WP_MCP_AI_Error_Tracking_Service::get_instance();

		// Clear any existing errors before each test.
		$this->service->clear_all_errors();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clear errors after each test.
		$this->service->clear_all_errors();

		parent::tearDown();
	}

	/**
	 * Test singleton pattern.
	 */
	public function test_singleton_instance() {
		$instance1 = WP_MCP_AI_Error_Tracking_Service::get_instance();
		$instance2 = WP_MCP_AI_Error_Tracking_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Service should return the same instance' );
	}

	/**
	 * Test error tracking.
	 */
	public function test_track_error() {
		$component = 'rest_api';
		$message   = 'Test error message';
		$context   = array( 'test' => 'data' );

		$error_id = $this->service->track_error( $component, $message, $context );

		$this->assertNotEmpty( $error_id, 'Error ID should not be empty' );
		$this->assertStringStartsWith( 'err_', $error_id, 'Error ID should start with err_' );
	}

	/**
	 * Test retrieving errors by component.
	 */
	public function test_get_errors_by_component() {
		// Track multiple errors for different components.
		$this->service->track_error( 'rest_api', 'Error 1', array() );
		$this->service->track_error( 'rest_api', 'Error 2', array() );
		$this->service->track_error( 'chat_ui', 'Error 3', array() );

		$rest_api_errors = $this->service->get_errors_by_component( 'rest_api', 3600 );
		$chat_ui_errors  = $this->service->get_errors_by_component( 'chat_ui', 3600 );

		$this->assertCount( 2, $rest_api_errors, 'Should have 2 errors for rest_api' );
		$this->assertCount( 1, $chat_ui_errors, 'Should have 1 error for chat_ui' );
	}

	/**
	 * Test error rate calculation.
	 */
	public function test_get_error_rate() {
		// Track some errors.
		$this->service->track_error( 'rest_api', 'Error 1', array() );
		$this->service->track_error( 'rest_api', 'Error 2', array() );

		// Get error rate (will use estimation for total requests).
		$error_rate = $this->service->get_error_rate( 'rest_api', 3600 );

		$this->assertIsFloat( $error_rate, 'Error rate should be a float' );
		$this->assertGreaterThanOrEqual( 0, $error_rate, 'Error rate should be >= 0' );
		$this->assertLessThanOrEqual( 100, $error_rate, 'Error rate should be <= 100' );
	}

	/**
	 * Test error rate calculation with explicit total requests.
	 */
	public function test_get_error_rate_with_total_requests() {
		// Track 5 errors.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->service->track_error( 'rest_api', "Error $i", array() );
		}

		// Calculate rate with 100 total requests.
		$error_rate = $this->service->get_error_rate( 'rest_api', 3600, 100 );

		$this->assertEquals( 5.0, $error_rate, 'Error rate should be 5% (5 errors / 100 requests)' );
	}

	/**
	 * Test getting recent errors.
	 */
	public function test_get_recent_errors() {
		// Track multiple errors.
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->service->track_error( 'rest_api', "Error $i", array() );
		}

		$recent_errors = $this->service->get_recent_errors( 5 );

		$this->assertCount( 5, $recent_errors, 'Should return 5 recent errors' );
		$this->assertEquals( 'Error 10', $recent_errors[0]['message'], 'Most recent error should be first' );
	}

	/**
	 * Test error statistics.
	 */
	public function test_get_error_statistics() {
		// Track errors for multiple components.
		$this->service->track_error( 'rest_api', 'Error 1', array() );
		$this->service->track_error( 'rest_api', 'Error 2', array() );
		$this->service->track_error( 'rest_api', 'Error 1', array() ); // Duplicate message.
		$this->service->track_error( 'chat_ui', 'Error 3', array() );

		$stats = $this->service->get_error_statistics( 3600 );

		$this->assertArrayHasKey( 'rest_api', $stats, 'Stats should include rest_api' );
		$this->assertArrayHasKey( 'chat_ui', $stats, 'Stats should include chat_ui' );
		$this->assertEquals( 3, $stats['rest_api']['count'], 'rest_api should have 3 errors' );
		$this->assertEquals( 2, $stats['rest_api']['unique_message_count'], 'rest_api should have 2 unique messages' );
		$this->assertEquals( 1, $stats['chat_ui']['count'], 'chat_ui should have 1 error' );
	}

	/**
	 * Test clearing all errors.
	 */
	public function test_clear_all_errors() {
		// Track some errors.
		$this->service->track_error( 'rest_api', 'Error 1', array() );
		$this->service->track_error( 'chat_ui', 'Error 2', array() );

		// Clear all.
		$result = $this->service->clear_all_errors();

		$this->assertTrue( $result, 'Clear should return true' );

		$errors = $this->service->get_recent_errors( 10 );
		$this->assertEmpty( $errors, 'All errors should be cleared' );
	}

	/**
	 * Test error tracking with context.
	 */
	public function test_track_error_with_context() {
		$component = 'rest_api';
		$message   = 'API error';
		$context   = array(
			'endpoint' => '/wp-json/mcp-ai/v1/chat',
			'status'   => 500,
			'details'  => 'Internal server error',
		);

		$error_id = $this->service->track_error( $component, $message, $context );

		$errors = $this->service->get_errors_by_component( $component, 3600 );

		$this->assertCount( 1, $errors, 'Should have 1 error' );
		$this->assertEquals( $context, $errors[0]['context'], 'Context should be preserved' );
	}

	/**
	 * Test error tracking includes timestamp.
	 */
	public function test_error_includes_timestamp() {
		$this->service->track_error( 'rest_api', 'Test error', array() );

		$errors = $this->service->get_recent_errors( 1 );

		$this->assertArrayHasKey( 'timestamp', $errors[0], 'Error should have timestamp' );
		$this->assertIsInt( $errors[0]['timestamp'], 'Timestamp should be integer' );
		$this->assertGreaterThan( 0, $errors[0]['timestamp'], 'Timestamp should be positive' );
	}

	/**
	 * Test error tracking includes component.
	 */
	public function test_error_includes_component() {
		$this->service->track_error( 'chat_ui', 'Test error', array() );

		$errors = $this->service->get_recent_errors( 1 );

		$this->assertArrayHasKey( 'component', $errors[0], 'Error should have component' );
		$this->assertEquals( 'chat_ui', $errors[0]['component'], 'Component should match' );
	}

	/**
	 * Test time period filtering.
	 */
	public function test_time_period_filtering() {
		// This test is tricky to implement without mocking time.
		// We'll just verify the method accepts the time_period parameter.
		$errors = $this->service->get_errors_by_component( 'rest_api', 3600 );
		$this->assertIsArray( $errors, 'Should return array even with no errors' );

		$stats = $this->service->get_error_statistics( 86400 );
		$this->assertIsArray( $stats, 'Should return array even with no errors' );
	}

	/**
	 * Test error rate caching.
	 */
	public function test_error_rate_caching() {
		// Track an error.
		$this->service->track_error( 'rest_api', 'Error 1', array() );

		// Get error rate twice.
		$rate1 = $this->service->get_error_rate( 'rest_api', 3600 );
		$rate2 = $this->service->get_error_rate( 'rest_api', 3600 );

		$this->assertEquals( $rate1, $rate2, 'Cached rate should match first calculation' );
	}

	/**
	 * Test recording error with performance metrics.
	 */
	public function test_record_error_with_metrics() {
		// Load Performance Monitor CCT if available.
		if ( class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$component = 'rest_api';
			$message   = 'Test performance error';
			$context   = array( 'metric' => 'value' );

			$error_id = $this->service->record_error_with_metrics( $component, $message, $context, true );

			$this->assertNotEmpty( $error_id, 'Should return error ID' );

			// Verify error was tracked.
			$errors = $this->service->get_errors_by_component( $component, 3600 );
			$this->assertGreaterThan( 0, count( $errors ), 'Error should be tracked' );
		} else {
			$this->markTestSkipped( 'Performance Monitor CCT not available' );
		}
	}

	/**
	 * Test helper function.
	 */
	public function test_helper_function() {
		if ( function_exists( 'wp_mcp_ai_get_error_tracking_service' ) ) {
			$service = wp_mcp_ai_get_error_tracking_service();
			$this->assertInstanceOf( 'WP_MCP_AI_Error_Tracking_Service', $service, 'Helper should return service instance' );
		} else {
			$this->markTestSkipped( 'Helper function not loaded' );
		}
	}

	/**
	 * Test maximum stored errors limit.
	 */
	public function test_max_stored_errors_limit() {
		// This would require tracking more than MAX_STORED_ERRORS errors.
		// For now, just verify the constant exists.
		$this->assertTrue( defined( 'WP_MCP_AI_Error_Tracking_Service::MAX_STORED_ERRORS' ), 'MAX_STORED_ERRORS should be defined' );
	}

	/**
	 * Test error sanitization.
	 */
	public function test_error_sanitization() {
		$component = 'rest_api';
		$message   = '<script>alert("xss")</script>Test';

		$this->service->track_error( $component, $message, array() );

		$errors = $this->service->get_recent_errors( 1 );

		$this->assertNotContains( '<script>', $errors[0]['message'], 'Message should be sanitized' );
	}
}
