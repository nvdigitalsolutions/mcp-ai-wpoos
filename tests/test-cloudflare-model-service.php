<?php
/**
 * Tests for Cloudflare Model Service
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Cloudflare Model Service functionality.
 */
class Test_Cloudflare_Model_Service extends WP_UnitTestCase {

	/**
	 * Test that Cloudflare models are returned when provider is enabled.
	 */
	public function test_cloudflare_models_returned_when_enabled() {
		// Set up Cloudflare settings with correct field name.
		$settings = array(
			'enable_cloudflare'     => true,
			'cloudflare_api_token'  => 'test_token_12345',
			'cloudflare_account_id' => 'test_account_id',
			'cloudflare_model'      => '@cf/meta/llama-3.1-8b-instruct',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'cloudflare' );

		// Verify models are returned.
		$this->assertNotEmpty( $models, 'Cloudflare models should be returned when provider is enabled' );
		$this->assertIsArray( $models, 'Models should be an array' );

		// Verify we have a reasonable number of models (at least 5).
		$this->assertGreaterThanOrEqual( 5, count( $models ), 'Should have at least 5 Cloudflare models' );

		// Verify we have models from each family (Llama, Mistral, Qwen).
		$has_llama   = false;
		$has_mistral = false;
		$has_qwen    = false;

		foreach ( array_keys( $models ) as $model_id ) {
			if ( strpos( $model_id, '@cf/meta/llama' ) === 0 ) {
				$has_llama = true;
			}
			if ( strpos( $model_id, '@cf/mistralai/' ) === 0 ) {
				$has_mistral = true;
			}
			if ( strpos( $model_id, '@cf/qwen/' ) === 0 ) {
				$has_qwen = true;
			}
		}

		$this->assertTrue( $has_llama, 'Should have at least one Llama model' );
		$this->assertTrue( $has_mistral, 'Should have at least one Mistral model' );
		$this->assertTrue( $has_qwen, 'Should have at least one Qwen model' );
	}

	/**
	 * Test that no models are returned when provider is not enabled.
	 */
	public function test_cloudflare_models_empty_when_not_enabled() {
		// Set up Cloudflare settings without enable flag.
		$settings = array(
			'enable_cloudflare'     => false, // Disabled.
			'cloudflare_api_token'  => 'test_token_12345',
			'cloudflare_account_id' => 'test_account_id',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'cloudflare' );

		// Verify no models are returned.
		$this->assertEmpty( $models, 'Cloudflare models should be empty when provider is not enabled' );
	}

	/**
	 * Test that no models are returned when API token is missing.
	 */
	public function test_cloudflare_models_empty_when_api_token_missing() {
		// Set up Cloudflare settings without API token.
		$settings = array(
			'enable_cloudflare'     => true,
			'cloudflare_api_token'  => '', // Missing.
			'cloudflare_account_id' => 'test_account_id',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'cloudflare' );

		// Verify no models are returned.
		$this->assertEmpty( $models, 'Cloudflare models should be empty when API token is missing' );
	}

	/**
	 * Test that no models are returned when account ID is missing.
	 */
	public function test_cloudflare_models_empty_when_account_id_missing() {
		// Set up Cloudflare settings without account ID.
		$settings = array(
			'enable_cloudflare'     => true,
			'cloudflare_api_token'  => 'test_token_12345',
			'cloudflare_account_id' => '', // Missing.
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'cloudflare' );

		// Verify no models are returned.
		$this->assertEmpty( $models, 'Cloudflare models should be empty when account ID is missing' );
	}

	/**
	 * Test that model labels are correct.
	 */
	public function test_cloudflare_model_labels() {
		// Set up Cloudflare settings.
		$settings = array(
			'enable_cloudflare'     => true,
			'cloudflare_api_token'  => 'test_token_12345',
			'cloudflare_account_id' => 'test_account_id',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'cloudflare' );

		// Verify model labels against the current static Cloudflare catalog.
		$this->assertEquals( 'Llama 4 Scout 17B 16E Instruct (Multimodal)', $models['@cf/meta/llama-4-scout-17b-16e-instruct'] );
		$this->assertEquals( 'Llama 4 Maverick 17B 128E Instruct', $models['@cf/meta/llama-4-maverick-17b-128e-instruct'] );
		$this->assertEquals( 'Qwen 3 30B A3B FP8', $models['@cf/qwen/qwen3-30b-a3b-fp8'] );
	}
}
