<?php
/**
 * Tests for Veo Video Generation Model Name Validation
 *
 * Verifies that:
 * 1. Invalid model names (e.g., assistant's chat model) are filtered out
 * 2. Valid Veo model names (both simplified and full API names) are accepted
 * 3. Default model fallback works correctly
 *
 * @package WP_MCP_AI
 */

class Test_Veo_Model_Name_Validation extends WP_UnitTestCase {

	/**
	 * Test that invalid model names are filtered out from tool arguments.
	 */
	public function test_invalid_model_name_filtered() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-capability-flags-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-model-requirements-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Create a test user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Mock arguments with an invalid model name (like an assistant's chat model).
		$arguments = array(
			'prompt' => 'Test video prompt',
			'model'  => 'gemini-2.0-flash-exp', // Invalid: This is a chat model, not a Veo model.
		);

		$context = array(
			'user_id' => $user_id,
		);

		// Mock the service to prevent actual API calls.
		// We'll use a filter to intercept the service call.
		add_filter( 'pre_http_request', array( $this, 'mock_veo_api_response' ), 10, 3 );

		// Track logged events to verify filtering.
		$logged_events = array();
		add_action( 'wp_mcp_ai_log_event', function( $event, $message, $data ) use ( &$logged_events ) {
			if ( 'veo_invalid_model_filtered' === $event ) {
				$logged_events[] = $data;
			}
		}, 10, 3 );

		// Execute the tool - it should filter out the invalid model name.
		// Note: This will fail because we need Gemini API key, but we're testing the filtering logic.
		$result = $tool->execute( $arguments, $context );

		// Clean up.
		remove_filter( 'pre_http_request', array( $this, 'mock_veo_api_response' ) );

		// Verify that the invalid model was logged.
		$this->assertNotEmpty( $logged_events, 'Invalid model should be logged' );
		$this->assertEquals( 'gemini-2.0-flash-exp', $logged_events[0]['invalid_model'] );
	}

	/**
	 * Test that valid simplified model names are accepted.
	 */
	public function test_valid_simplified_model_names_accepted() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Test that simplified names are converted correctly.
		// We'll use reflection to test the protected method.
		$reflection = new ReflectionClass( $service );
		$method = $reflection->getMethod( 'get_default_model' );
		$method->setAccessible( true );

		// Mock settings with veo-2.0.
		update_option( 'wp_mcp_ai_settings', array( 'default_gemini_video_model' => 'veo-2.0' ) );
		$default_model = $method->invoke( $service );
		$this->assertEquals( 'veo-2.0-generate-001', $default_model, 'veo-2.0 should map to veo-2.0-generate-001' );

		// Mock settings with veo-3.1.
		update_option( 'wp_mcp_ai_settings', array( 'default_gemini_video_model' => 'veo-3.1' ) );
		$default_model = $method->invoke( $service );
		$this->assertEquals( 'veo-3.1-generate-preview', $default_model, 'veo-3.1 should map to veo-3.1-generate-preview' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that both simplified and full API model names are accepted.
	 */
	public function test_both_model_name_formats_accepted() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-capability-flags-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-model-requirements-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Get the parameter schema.
		$schema = $tool->get_parameters_schema();

		// Verify that the schema accepts all valid model name formats.
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['model'] );

		$valid_values = $schema['properties']['model']['enum'];
		$expected_values = array( 'veo-2.0', 'veo-3.1', 'veo-2.0-generate-001', 'veo-3.1-generate-preview' );

		$this->assertEquals( $expected_values, $valid_values, 'Schema should accept both simplified and full API model names' );
	}

	/**
	 * Test default model fallback when no setting is configured.
	 */
	public function test_default_model_fallback() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $service );
		$method = $reflection->getMethod( 'get_default_model' );
		$method->setAccessible( true );

		// Clear any existing settings.
		delete_option( 'wp_mcp_ai_settings' );

		// Get default model - should fall back to veo-2.0.
		$default_model = $method->invoke( $service );
		$this->assertEquals( 'veo-2.0-generate-001', $default_model, 'Should default to veo-2.0-generate-001 when no setting is configured' );
	}

	/**
	 * Mock API response for Veo generation.
	 *
	 * @param false|array|WP_Error $preempt Response to return instead of making HTTP request.
	 * @param array                $args    HTTP request arguments.
	 * @param string               $url     The request URL.
	 * @return array Mocked response.
	 */
	public function mock_veo_api_response( $preempt, $args, $url ) {
		// Only mock Veo API calls.
		if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			return $preempt;
		}

		// Return a mocked async operation response.
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'name'     => 'operations/test-operation-id',
					'metadata' => array(
						'@type' => 'type.googleapis.com/google.cloud.aiplatform.v1beta1.CreateVideoOperationMetadata',
					),
				)
			),
		);
	}
}
