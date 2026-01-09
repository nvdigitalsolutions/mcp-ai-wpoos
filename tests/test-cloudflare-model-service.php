<?php
/**
 * Tests for Cloudflare Model Service
 *
 * @package WP_MCP_AI
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
			'enable_cloudflare'      => true,
			'cloudflare_api_token'   => 'test_token_12345',
			'cloudflare_account_id'  => 'test_account_id',
			'cloudflare_model'       => '@cf/meta/llama-3.1-8b-instruct',
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
		
		// Verify expected models are present.
		$this->assertArrayHasKey( '@cf/meta/llama-3.1-8b-instruct', $models );
		$this->assertArrayHasKey( '@cf/meta/llama-3.1-70b-instruct', $models );
		$this->assertArrayHasKey( '@cf/meta/llama-3.2-1b-instruct', $models );
		$this->assertArrayHasKey( '@cf/meta/llama-3.2-3b-instruct', $models );
		$this->assertArrayHasKey( '@cf/mistral/mistral-7b-instruct-v0.1', $models );
		$this->assertArrayHasKey( '@cf/qwen/qwen1.5-7b-chat-awq', $models );
		$this->assertArrayHasKey( '@cf/qwen/qwen1.5-14b-chat-awq', $models );
	}

	/**
	 * Test that no models are returned when provider is not enabled.
	 */
	public function test_cloudflare_models_empty_when_not_enabled() {
		// Set up Cloudflare settings without enable flag.
		$settings = array(
			'enable_cloudflare'      => false, // Disabled
			'cloudflare_api_token'   => 'test_token_12345',
			'cloudflare_account_id'  => 'test_account_id',
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
			'enable_cloudflare'      => true,
			'cloudflare_api_token'   => '', // Missing
			'cloudflare_account_id'  => 'test_account_id',
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
			'enable_cloudflare'      => true,
			'cloudflare_api_token'   => 'test_token_12345',
			'cloudflare_account_id'  => '', // Missing
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
			'enable_cloudflare'      => true,
			'cloudflare_api_token'   => 'test_token_12345',
			'cloudflare_account_id'  => 'test_account_id',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load model service.
		if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
		}

		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'cloudflare' );

		// Verify model labels.
		$this->assertEquals( 'Llama 3.1 8B Instruct', $models['@cf/meta/llama-3.1-8b-instruct'] );
		$this->assertEquals( 'Llama 3.1 70B Instruct', $models['@cf/meta/llama-3.1-70b-instruct'] );
		$this->assertEquals( 'Mistral 7B Instruct v0.1', $models['@cf/mistral/mistral-7b-instruct-v0.1'] );
	}
}
