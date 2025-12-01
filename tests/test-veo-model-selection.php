<?php
/**
 * Tests for Veo model selection from settings.
 *
 * Verifies that the gemini_video_model setting is properly honored
 * and that both short form (veo-2.0) and full form (veo-2.0-generate-001)
 * model identifiers are correctly detected.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

/**
 * Test class for Veo model selection.
 */
class WP_MCP_AI_Veo_Model_Selection_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that full Veo 2 model ID forces Veo 2 usage.
	 */
	public function test_full_veo_2_model_id_forces_veo_2() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_model = null;

		// Mock HTTP requests to capture which model is being called.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_model ) {
				// Extract model from URL.
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
						$captured_model = 'veo-3.1-generate-preview';
					} elseif ( strpos( $url, 'veo-2.0-generate-001' ) !== false ) {
						$captured_model = 'veo-2.0-generate-001';
					}

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op',
								'done' => false,
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Call with full Veo 2 model ID (from settings).
		$service->generate_video(
			array(
				'prompt' => 'Test video',
				'model'  => 'veo-2.0-generate-001',
				'async'  => false,
			)
		);

		// Verify Veo 2 was called, not Veo 3.1.
		$this->assertEquals( 'veo-2.0-generate-001', $captured_model, 'Full Veo 2 model ID should force Veo 2 usage' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that short Veo 2 model ID forces Veo 2 usage.
	 */
	public function test_short_veo_2_model_id_forces_veo_2() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_model = null;

		// Mock HTTP requests to capture which model is being called.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_model ) {
				// Extract model from URL.
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
						$captured_model = 'veo-3.1-generate-preview';
					} elseif ( strpos( $url, 'veo-2.0-generate-001' ) !== false ) {
						$captured_model = 'veo-2.0-generate-001';
					}

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op',
								'done' => false,
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Call with short Veo 2 model ID (from tool parameter).
		$service->generate_video(
			array(
				'prompt' => 'Test video',
				'model'  => 'veo-2.0',
				'async'  => false,
			)
		);

		// Verify Veo 2 was called, not Veo 3.1.
		$this->assertEquals( 'veo-2.0-generate-001', $captured_model, 'Short Veo 2 model ID should force Veo 2 usage' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that Veo 3.1 model ID uses Veo 3.1.
	 */
	public function test_veo_3_model_id_uses_veo_3() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_model = null;

		// Mock HTTP requests to capture which model is being called.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_model ) {
				// Extract model from URL.
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
						$captured_model = 'veo-3.1-generate-preview';
					} elseif ( strpos( $url, 'veo-2.0-generate-001' ) !== false ) {
						$captured_model = 'veo-2.0-generate-001';
					}

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op',
								'done' => false,
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Call with full Veo 3.1 model ID (from settings).
		$service->generate_video(
			array(
				'prompt' => 'Test video',
				'model'  => 'veo-3.1-generate-preview',
				'async'  => false,
			)
		);

		// Verify Veo 3.1 was called.
		$this->assertEquals( 'veo-3.1-generate-preview', $captured_model, 'Veo 3.1 model ID should use Veo 3.1' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that settings default to Veo 2 is honored by tool.
	 */
	public function test_settings_veo_2_default_honored() {
		// Configure settings to use Veo 2.
		$settings = array(
			'gemini_api_key'     => 'test-api-key-12345',
			'gemini_video_model' => 'veo-2.0-generate-001',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_default_video_settings' );
		$method->setAccessible( true );

		$defaults = $method->invoke( $tool );

		// Verify Veo 2 is the default model.
		$this->assertEquals( 'veo-2.0-generate-001', $defaults['model'], 'Settings should return Veo 2 as default' );
	}

	/**
	 * Test that tool passes model to service correctly.
	 */
	public function test_tool_passes_model_to_service() {
		// Configure settings to use Veo 2.
		$settings = array(
			'gemini_api_key'     => 'test-api-key-12345',
			'gemini_video_model' => 'veo-2.0-generate-001',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$captured_model = null;

		// Mock HTTP requests to capture which model is being called.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_model ) {
				// Extract model from URL.
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
						$captured_model = 'veo-3.1';
					} elseif ( strpos( $url, 'veo-2.0-generate-001' ) !== false ) {
						$captured_model = 'veo-2.0';
					}

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op',
								'done' => false,
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Create a test user with upload permissions.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Execute tool without specifying model (should use settings default).
		$tool->execute(
			array(
				'prompt'   => 'Test video',
				'duration' => 5,
			),
			array( 'user_id' => $user_id )
		);

		// Verify Veo 2 was called (from settings default).
		$this->assertEquals( 'veo-2.0', $captured_model, 'Tool should honor Veo 2 setting from admin panel' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}
}
