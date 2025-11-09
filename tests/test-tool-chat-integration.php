<?php
/**
 * Integration tests for tool execution in chat context.
 *
 * Ensures tool errors are properly handled in the chat flow and formatted
 * in a way that AI assistants can understand and act upon.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-chat-integration
 * @group tool-robustness
 */
class WP_MCP_AI_Tool_Chat_Integration_Tests extends WP_UnitTestCase {

	/**
	 * Test that chat service properly formats tool errors for AI consumption.
	 */
	public function test_chat_service_formats_tool_errors() {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Chat_Service not available' );
		}

		// This test verifies the error formatting mechanism.
		// Actual chat service testing requires mocking LLM providers.

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );

		// Create mock tool that returns error.
		if ( ! class_exists( 'WP_MCP_AI_Mock_Error_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$property->setValue( $registry, array() );
		$tool = new WP_MCP_AI_Mock_Error_Tool();
		$registry->register_tool( $tool );

		// Execute tool and verify error format.
		$result = $registry->execute_tool( 'mock_error', array() );

		$this->assertInstanceOf( WP_Error::class, $result );

		// Simulate what chat service does - JSON encode the error.
		$formatted_result = is_wp_error( $result )
			? wp_json_encode( array( 'error' => $result->get_error_message() ) )
			: wp_json_encode( $result );

		$this->assertNotFalse( $formatted_result, 'Error should be JSON encodable' );

		$decoded = json_decode( $formatted_result, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'error', $decoded );
		$this->assertIsString( $decoded['error'] );
		$this->assertNotEmpty( $decoded['error'] );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test that missing plugin errors provide actionable information.
	 */
	public function test_missing_plugin_errors_are_actionable() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is active, cannot test missing plugin error' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_Woo_Orders' ) ) {
			$this->markTestSkipped( 'WooCommerce tools not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );

		$message = $result->get_error_message();

		// Error should mention what's missing.
		$this->assertStringContainsString( 'WooCommerce', $message );

		// Error should indicate the plugin is not available/active.
		$contains_status = stripos( $message, 'not active' ) !== false ||
						   stripos( $message, 'not available' ) !== false ||
						   stripos( $message, 'not installed' ) !== false ||
						   stripos( $message, 'must be' ) !== false;

		$this->assertTrue(
			$contains_status,
			'Error message should indicate the plugin status: ' . $message
		);
	}

	/**
	 * Test that missing credentials errors are informative.
	 */
	public function test_missing_credentials_errors_are_informative() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Send_Mailjet_Email' ) ) {
			$this->markTestSkipped( 'Mailjet tool not available' );
		}

		// Clear Mailjet credentials.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$original_settings = $settings;
		$settings['mailjet_api_key']    = '';
		$settings['mailjet_api_secret'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool = new WP_MCP_AI_Tool_Send_Mailjet_Email();

		$result = $tool->execute(
			array(
				'subject' => 'Test',
				'to'      => array( 'test@example.com' ),
			),
			array( 'user_id' => 1 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );

		$message = $result->get_error_message();

		// Error should mention credentials.
		$contains_credentials = stripos( $message, 'credentials' ) !== false ||
								stripos( $message, 'api key' ) !== false ||
								stripos( $message, 'configured' ) !== false;

		$this->assertTrue(
			$contains_credentials,
			'Error message should mention credentials: ' . $message
		);

		// Check if error data contains actionable information.
		$error_data = $result->get_error_data();
		if ( is_array( $error_data ) && isset( $error_data['actions'] ) ) {
			$this->assertIsArray( $error_data['actions'] );
			$this->assertNotEmpty( $error_data['actions'] );
		}

		// Restore settings.
		update_option( 'wp_mcp_ai_settings', $original_settings );
	}

	/**
	 * Test that tool execution context is properly passed.
	 */
	public function test_tool_execution_context_passing() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );

		if ( ! class_exists( 'WP_MCP_AI_Mock_Success_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$property->setValue( $registry, array() );
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$registry->register_tool( $tool );

		$context = array(
			'assistant_id'     => 123,
			'user_id'          => 1,
			'assistant_config' => array( 'model' => 'gpt-4' ),
		);

		$result = $registry->execute_tool( 'mock_success', array( 'test' => 'value' ), $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'context', $result );
		$this->assertEquals( 123, $result['context']['assistant_id'] );
		$this->assertEquals( 1, $result['context']['user_id'] );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test that multiple tool executions don't interfere with each other.
	 */
	public function test_multiple_tool_executions_are_isolated() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$old_tools = $property->getValue( $registry );

		if ( ! class_exists( 'WP_MCP_AI_Mock_Success_Tool' ) || ! class_exists( 'WP_MCP_AI_Mock_Error_Tool' ) ) {
			require_once __DIR__ . '/test-tool-registry-robustness.php';
		}

		$property->setValue( $registry, array() );

		$tool1 = new WP_MCP_AI_Mock_Success_Tool();
		$tool2 = new WP_MCP_AI_Mock_Error_Tool();

		$registry->register_tool( $tool1 );
		$registry->register_tool( $tool2 );

		// Execute first tool - should succeed.
		$result1 = $registry->execute_tool( 'mock_success', array( 'param1' => 'value1' ) );

		// Execute second tool - should error.
		$result2 = $registry->execute_tool( 'mock_error', array() );

		// Execute first tool again - should still succeed.
		$result3 = $registry->execute_tool( 'mock_success', array( 'param2' => 'value2' ) );

		// Verify results are independent.
		$this->assertIsArray( $result1 );
		$this->assertTrue( $result1['success'] );
		$this->assertEquals( 'value1', $result1['args']['param1'] );

		$this->assertInstanceOf( WP_Error::class, $result2 );

		$this->assertIsArray( $result3 );
		$this->assertTrue( $result3['success'] );
		$this->assertEquals( 'value2', $result3['args']['param2'] );

		// Restore tools.
		$property->setValue( $registry, $old_tools );
	}

	/**
	 * Test that tool errors include HTTP status codes for API responses.
	 */
	public function test_tool_errors_include_status_codes() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test non-existent tool - should be 404.
		$result = $registry->execute_tool( 'nonexistent_tool_12345', array() );

		$this->assertInstanceOf( WP_Error::class, $result );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertEquals( 404, $error_data['status'] );
	}

	/**
	 * Test that tool service validates tool exists before execution.
	 */
	public function test_tool_service_validates_tool_existence() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Service not available' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$service  = new WP_MCP_AI_Tool_Service( $registry );

		$result = $service->execute_tool( 'totally_fake_tool_xyz', array(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
	}
}
