<?php
/**
 * Test Embedded Client Constant Fix
 *
 * Tests that the embedded client doesn't use undefined constants.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded client constant fix.
 */
class Test_Embedded_Client_Constant_Fix extends WP_UnitTestCase {

	/**
	 * Test that WP_MCP_AI_PATH constant is defined.
	 */
	public function test_path_constant_defined() {
		$this->assertTrue( defined( 'WP_MCP_AI_PATH' ), 'WP_MCP_AI_PATH should be defined' );
	}

	/**
	 * Test that embedded client uses correct constant.
	 */
	public function test_embedded_client_uses_correct_constant() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Check that the class exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Embedded_Client' ), 'WP_MCP_AI_Embedded_Client class should exist' );

		// Create client instance without errors.
		$client = new WP_MCP_AI_Embedded_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedded_Client', $client );

		// Get models directory should not throw an error.
		$models_dir = $client->get_models_directory();
		$this->assertIsString( $models_dir );
		$this->assertStringContainsString( 'mcp-ai-wpoos/models', $models_dir );
	}

	/**
	 * Test that get_inference_binary doesn't throw fatal error.
	 */
	public function test_get_inference_binary_handles_missing_binary() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Create a test assistant settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded'  => true,
				'embedded_model'   => 'qwen2-0.5b-instruct',
			)
		);

		$client = new WP_MCP_AI_Embedded_Client();
		$result = $client->test_connection();

		// Should return WP_Error (binary not found) but not throw fatal error.
		$this->assertTrue( is_wp_error( $result ) || is_array( $result ), 'test_connection should return WP_Error or array, not throw fatal error' );

		// If it's an error, check it's the expected type.
		if ( is_wp_error( $result ) ) {
			$error_codes = array( 'wp_mcp_ai_model_not_downloaded', 'wp_mcp_ai_no_inference_binary', 'wp_mcp_ai_no_model_selected' );
			$this->assertContains( $result->get_error_code(), $error_codes, 'Error should be one of the expected error codes' );
		}
	}

	/**
	 * Test shell_exec disabled detection.
	 */
	public function test_shell_exec_disabled_detection() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		$client = new WP_MCP_AI_Embedded_Client();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'is_shell_exec_disabled' );
		$method->setAccessible( true );

		$result = $method->invoke( $client );

		// Should return a boolean.
		$this->assertIsBool( $result, 'is_shell_exec_disabled should return a boolean' );
	}
}
