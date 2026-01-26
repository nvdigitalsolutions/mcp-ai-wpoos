<?php
/**
 * Tests for Embedded Model Service
 *
 * @package WP_MCP_AI
 */

/**
 * Test Embedded Model Service functionality.
 */
class Test_Embedded_Model_Service extends WP_UnitTestCase {

	/**
	 * Test that all 7 embedded models are returned when provider is enabled.
	 */
	public function test_all_embedded_models_returned_when_enabled() {
		// Set up embedded settings.
		$settings = array(
			'enable_embedded' => true,
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'embedded' );

		// Verify models are returned.
		$this->assertNotEmpty( $models, 'Embedded models should be returned when provider is enabled' );
		$this->assertIsArray( $models, 'Models should be an array' );

		// Verify we have exactly 7 models (all available embedded models).
		$this->assertEquals( 7, count( $models ), 'Should have exactly 7 embedded models' );

		// Verify specific models exist.
		$expected_models = array(
			'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC',
			'Qwen2.5-7B-Instruct-q4f16_1-MLC',
			'Phi-3.5-mini-instruct-q4f16_1-MLC',
			'Llama-3.2-3B-Instruct-q4f16_1-MLC',
			'Qwen2.5-1.5B-Instruct-q4f16_1-MLC',
			'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			'Qwen2.5-0.5B-Instruct-q4f16_1-MLC',
		);

		foreach ( $expected_models as $model_id ) {
			$this->assertArrayHasKey( $model_id, $models, "Model {$model_id} should be in the list" );
		}
	}

	/**
	 * Test that function calling support is indicated correctly.
	 */
	public function test_function_calling_support_indicated() {
		// Set up embedded settings.
		$settings = array(
			'enable_embedded' => true,
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'embedded' );

		// Models with function calling support (should have * suffix).
		$function_calling_models = array(
			'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC',
			'Qwen2.5-7B-Instruct-q4f16_1-MLC',
			'Phi-3.5-mini-instruct-q4f16_1-MLC',
			'Qwen2.5-1.5B-Instruct-q4f16_1-MLC',
		);

		foreach ( $function_calling_models as $model_id ) {
			$this->assertArrayHasKey( $model_id, $models, "Function calling model {$model_id} should exist" );
			$this->assertStringContainsString( '*', $models[ $model_id ], "Model {$model_id} should be marked with * for function calling support" );
		}

		// Models without function calling support (should NOT have * suffix).
		$non_function_calling_models = array(
			'Llama-3.2-3B-Instruct-q4f16_1-MLC',
			'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			'Qwen2.5-0.5B-Instruct-q4f16_1-MLC',
		);

		foreach ( $non_function_calling_models as $model_id ) {
			$this->assertArrayHasKey( $model_id, $models, "Non-function calling model {$model_id} should exist" );
			$this->assertStringNotContainsString( '*', $models[ $model_id ], "Model {$model_id} should NOT be marked with * (no function calling support)" );
		}
	}

	/**
	 * Test that no models are returned when provider is disabled.
	 */
	public function test_no_models_returned_when_disabled() {
		// Set up embedded settings with provider disabled.
		$settings = array(
			'enable_embedded' => false,
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'embedded' );

		// Verify no models are returned.
		$this->assertEmpty( $models, 'No embedded models should be returned when provider is disabled' );
	}

	/**
	 * Test that no models are returned in base version.
	 */
	public function test_no_models_returned_in_base_version() {
		// Enable the provider.
		$settings = array(
			'enable_embedded' => true,
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Simulate base version.
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', true );
		}

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'embedded' );

		// Verify no models are returned in base version.
		$this->assertEmpty( $models, 'No embedded models should be returned in base version' );
	}
}
