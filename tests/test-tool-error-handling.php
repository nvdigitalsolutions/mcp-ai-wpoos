<?php
/**
 * Tests for tool error handling in chat sessions.
 *
 * Ensures that tool failures are properly formatted and don't break chat sessions.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-error-handling
 * @group tool-robustness
 */
class WP_MCP_AI_Tool_Error_Handling_Tests extends WP_UnitTestCase {

	/**
	 * Test that WP_Error from tool is properly formatted for AI consumption.
	 */
	public function test_tool_error_is_formatted_for_ai() {
		// Create a mock tool that returns an error.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );
		$property->setValue( $registry, array() );

		// Register a mock error tool.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Error_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$tool = new WP_MCP_AI_Mock_Error_Tool();
		$registry->register_tool( $tool );

		// Execute the tool.
		$result = $registry->execute_tool( 'mock_error', array() );

		$this->assertInstanceOf( WP_Error::class, $result );

		// Verify error structure.
		$this->assertNotEmpty( $result->get_error_code() );
		$this->assertNotEmpty( $result->get_error_message() );

		// Error message should be a string.
		$this->assertIsString( $result->get_error_message() );

		// JSON encode error message to simulate what would be sent to AI.
		$json_error = wp_json_encode(
			array(
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
				'details' => $result->get_error_data(),
			)
		);

		$this->assertNotFalse( $json_error, 'Error should be JSON encodable' );

		$decoded = json_decode( $json_error, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'error', $decoded );
		$this->assertArrayHasKey( 'code', $decoded );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test that exceptions are caught and converted to WP_Error.
	 */
	public function test_tool_exceptions_are_caught() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );
		$property->setValue( $registry, array() );

		// Register a mock exception tool.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Exception_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$tool = new WP_MCP_AI_Mock_Exception_Tool();
		$registry->register_tool( $tool );

		// Execute the tool - should not throw exception.
		$result = $registry->execute_tool( 'mock_exception', array() );

		// Should return WP_Error instead of throwing.
		$this->assertInstanceOf( WP_Error::class, $result );

		// Error code should indicate exception.
		$this->assertEquals( 'wp_mcp_ai_tool_execution_exception', $result->get_error_code() );

		// Error message should contain exception message.
		$this->assertStringContainsString( 'exception', $result->get_error_message() );

		// Error data should contain exception details.
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'exception', $error_data );
		$this->assertArrayHasKey( 'trace', $error_data );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test that tool service properly handles tool errors.
	 */
	public function test_tool_service_handles_errors() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Service not available' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );
		$property->setValue( $registry, array() );

		// Register a mock error tool.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Error_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$tool = new WP_MCP_AI_Mock_Error_Tool();
		$registry->register_tool( $tool );

		// Create tool service.
		$service = new WP_MCP_AI_Tool_Service( $registry );

		// Execute tool through service.
		$result = $service->execute_tool( 'mock_error', array(), array() );

		// Should return WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test that tool not found error is properly formatted.
	 */
	public function test_tool_not_found_error_formatting() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$result = $registry->execute_tool( 'definitely_nonexistent_tool_12345', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'definitely_nonexistent_tool_12345', $message );
		$this->assertStringContainsString( 'not found', strtolower( $message ) );

		// Error data should include status code.
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertEquals( 404, $error_data['status'] );
	}

	/**
	 * Test that error messages don't contain sensitive information.
	 */
	public function test_error_messages_dont_leak_sensitive_info() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );
		$property->setValue( $registry, array() );

		// Register a mock exception tool.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Exception_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$tool = new WP_MCP_AI_Mock_Exception_Tool();
		$registry->register_tool( $tool );

		$result = $registry->execute_tool( 'mock_exception', array() );

		$message = $result->get_error_message();

		// Should not contain absolute paths in the public message.
		// Note: trace might be in error_data which is OK for debugging.
		$this->assertStringNotContainsString( ABSPATH, $message );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test error consistency across different tool types.
	 */
	public function test_error_consistency() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );
		$property->setValue( $registry, array() );

		// Test 1: Non-existent tool.
		$result1 = $registry->execute_tool( 'nonexistent', array() );

		// Test 2: Tool that returns error.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Error_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$tool2 = new WP_MCP_AI_Mock_Error_Tool();
		$registry->register_tool( $tool2 );
		$result2 = $registry->execute_tool( 'mock_error', array() );

		// Test 3: Tool that throws exception.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Exception_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$tool3 = new WP_MCP_AI_Mock_Exception_Tool();
		$registry->register_tool( $tool3 );
		$result3 = $registry->execute_tool( 'mock_exception', array() );

		// All should be WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result1 );
		$this->assertInstanceOf( WP_Error::class, $result2 );
		$this->assertInstanceOf( WP_Error::class, $result3 );

		// All should have error codes.
		$this->assertNotEmpty( $result1->get_error_code() );
		$this->assertNotEmpty( $result2->get_error_code() );
		$this->assertNotEmpty( $result3->get_error_code() );

		// All should have messages.
		$this->assertNotEmpty( $result1->get_error_message() );
		$this->assertNotEmpty( $result2->get_error_message() );
		$this->assertNotEmpty( $result3->get_error_message() );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}
}
