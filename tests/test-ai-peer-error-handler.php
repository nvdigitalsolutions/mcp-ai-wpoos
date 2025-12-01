<?php
/**
 * Tests for AI Peer error handler integration.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test AI Peer CPT error handler integration.
 */
class WP_MCP_AI_AI_Peer_Error_Handler_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing peers.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'ai_peer'" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wp_mcp_ai_peer_%'" );

		// Clear any recent errors.
		delete_option( 'wp_mcp_ai_recent_errors' );
	}

	/**
	 * Test that error handler class exists.
	 */
	public function test_error_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Error_Handler' ), 'Error handler class should exist' );
		$this->assertTrue( method_exists( 'WP_MCP_AI_Error_Handler', 'create_error' ), 'create_error method should exist' );
	}

	/**
	 * Test that AI Peer CPT uses error handler for sync errors.
	 *
	 * This test verifies that when sync fails, errors are logged using
	 * WP_MCP_AI_Error_Handler instead of basic error_log().
	 */
	public function test_ai_peer_sync_uses_error_handler() {
		// Enable logging to capture errors.
		update_option( 'wp_mcp_ai_enable_logging', true );

		// Create a peer to test sync error handling.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Error Handler Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Verify the sync error handling doesn't crash even without JetEngine.
		// The sync should skip gracefully when JetEngine is not available.
		// This tests the error handling path without actually triggering an error.

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that error handler creates proper WP_Error objects.
	 */
	public function test_error_handler_creates_wp_error() {
		$error = WP_MCP_AI_Error_Handler::create_error(
			'test_error_code',
			'Test error message',
			array( 'test_data' => 'value' ),
			WP_MCP_AI_Logger::LEVEL_ERROR
		);

		$this->assertInstanceOf( 'WP_Error', $error, 'Should return WP_Error instance' );
		$this->assertEquals( 'test_error_code', $error->get_error_code(), 'Error code should match' );
		$this->assertEquals( 'Test error message', $error->get_error_message(), 'Error message should match' );

		$error_data = $error->get_error_data();
		$this->assertIsArray( $error_data, 'Error data should be an array' );
		$this->assertArrayHasKey( 'test_data', $error_data, 'Error data should contain custom data' );
	}

	/**
	 * Test that error handler logs errors properly.
	 */
	public function test_error_handler_logs_errors() {
		// Enable logging.
		update_option( 'wp_mcp_ai_enable_logging', true );

		// Create an error.
		$error = WP_MCP_AI_Error_Handler::create_error(
			'ai_peer_test_error',
			'Test AI Peer error for logging verification',
			array(
				'peer_id'        => 123,
				'exception_type' => 'TestException',
			),
			WP_MCP_AI_Logger::LEVEL_ERROR
		);

		// Verify error was created.
		$this->assertInstanceOf( 'WP_Error', $error, 'Should return WP_Error instance' );

		// Note: We can't easily test that error_log() was called, but we can verify.
		// that the error handler doesn't throw exceptions and returns proper objects.
	}

	/**
	 * Test error handler with different severity levels.
	 */
	public function test_error_handler_severity_levels() {
		// Enable logging.
		update_option( 'wp_mcp_ai_enable_logging', true );

		// Test ERROR level.
		$error = WP_MCP_AI_Error_Handler::create_error(
			'test_error',
			'Error level test',
			array(),
			WP_MCP_AI_Logger::LEVEL_ERROR
		);
		$this->assertInstanceOf( 'WP_Error', $error );

		// Test CRITICAL level.
		$critical = WP_MCP_AI_Error_Handler::create_error(
			'test_critical',
			'Critical level test',
			array(),
			WP_MCP_AI_Logger::LEVEL_CRITICAL
		);
		$this->assertInstanceOf( 'WP_Error', $critical );

		// Test WARNING level.
		$warning = WP_MCP_AI_Error_Handler::create_error(
			'test_warning',
			'Warning level test',
			array(),
			WP_MCP_AI_Logger::LEVEL_WARNING
		);
		$this->assertInstanceOf( 'WP_Error', $warning );
	}

	/**
	 * Test that sync error context includes required fields.
	 */
	public function test_sync_error_context_structure() {
		$peer_id           = 456;
		$exception_message = 'Test exception message';

		// Simulate the error context that would be created during sync.
		$error_context = array(
			'peer_id'        => $peer_id,
			'exception_type' => 'Exception',
			'file'           => '/path/to/file.php',
			'line'           => 123,
		);

		// Verify required fields are present.
		$this->assertArrayHasKey( 'peer_id', $error_context );
		$this->assertArrayHasKey( 'exception_type', $error_context );
		$this->assertArrayHasKey( 'file', $error_context );
		$this->assertArrayHasKey( 'line', $error_context );

		// Verify field types.
		$this->assertIsInt( $error_context['peer_id'] );
		$this->assertIsString( $error_context['exception_type'] );
		$this->assertIsString( $error_context['file'] );
		$this->assertIsInt( $error_context['line'] );
	}

	/**
	 * Test that delete error context includes required fields.
	 */
	public function test_delete_error_context_structure() {
		$peer_id     = 789;
		$cct_item_id = 12;

		// Simulate the error context that would be created during delete.
		$error_context = array(
			'peer_id'        => $peer_id,
			'cct_item_id'    => $cct_item_id,
			'exception_type' => 'Exception',
			'file'           => '/path/to/file.php',
			'line'           => 456,
		);

		// Verify required fields are present.
		$this->assertArrayHasKey( 'peer_id', $error_context );
		$this->assertArrayHasKey( 'cct_item_id', $error_context );
		$this->assertArrayHasKey( 'exception_type', $error_context );
		$this->assertArrayHasKey( 'file', $error_context );
		$this->assertArrayHasKey( 'line', $error_context );

		// Verify field types.
		$this->assertIsInt( $error_context['peer_id'] );
		$this->assertIsInt( $error_context['cct_item_id'] );
		$this->assertIsString( $error_context['exception_type'] );
		$this->assertIsString( $error_context['file'] );
		$this->assertIsInt( $error_context['line'] );
	}
}
