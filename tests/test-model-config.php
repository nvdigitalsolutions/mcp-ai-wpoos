<?php
/**
 * Tests for WP_MCP_AI_Model_Config class.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test model configuration functionality.
 */
class Test_Model_Config extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing model configurations.
		delete_option( WP_MCP_AI_Model_Config::CONFIGS_OPTION );

		// Clear cache.
		wp_cache_flush();
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Model_Config::CONFIGS_OPTION );
		wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * Test get_all_configs returns default configurations.
	 */
	public function test_get_all_configs_returns_defaults() {
		$configs = WP_MCP_AI_Model_Config::get_all_configs();

		$this->assertIsArray( $configs );
		$this->assertNotEmpty( $configs );

		// Check for known 2025 models.
		$this->assertArrayHasKey( 'gpt-5.1', $configs );
		$this->assertArrayHasKey( 'gpt-5', $configs );
		$this->assertArrayHasKey( 'gpt-4o', $configs );
		$this->assertArrayHasKey( 'claude-sonnet-4.5', $configs );
		$this->assertArrayHasKey( 'claude-3-5-sonnet-20241022', $configs );
		$this->assertArrayHasKey( 'gemini-3-pro-preview', $configs );
		$this->assertArrayHasKey( 'gemini-2.5-flash', $configs );
		$this->assertArrayHasKey( 'qwen/qwen3-coder-30b', $configs );
	}

	/**
	 * Test get_model_config returns specific model configuration.
	 */
	public function test_get_model_config_returns_specific_config() {
		$config = WP_MCP_AI_Model_Config::get_model_config( 'gpt-5.1' );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'name', $config );
		$this->assertArrayHasKey( 'provider', $config );
		$this->assertArrayHasKey( 'tpm', $config );
		$this->assertArrayHasKey( 'rpm', $config );
		$this->assertArrayHasKey( 'context_window', $config );

		$this->assertEquals( 'GPT-5.1 (Flagship)', $config['name'] );
		$this->assertEquals( 'openai', $config['provider'] );
	}

	/**
	 * Test set_model_config saves configuration.
	 */
	public function test_set_model_config_saves() {
		$model  = 'test-model';
		$config = array(
			'name'           => 'Test Model',
			'provider'       => 'test',
			'tpm'            => 1000,
			'rpm'            => 100,
			'context_window' => 4096,
			'fallback_model' => 'gpt-3.5-turbo',
			'cost_per_1k'    => 0.001,
			'status'         => 'active',
		);

		$result = WP_MCP_AI_Model_Config::set_model_config( $model, $config );

		$this->assertTrue( $result );

		// Verify it was saved.
		$retrieved = WP_MCP_AI_Model_Config::get_model_config( $model );

		$this->assertIsArray( $retrieved );
		$this->assertEquals( 'Test Model', $retrieved['name'] );
		$this->assertEquals( 'test', $retrieved['provider'] );
		$this->assertEquals( 1000, $retrieved['tpm'] );
	}

	/**
	 * Test updating existing model configuration.
	 */
	public function test_update_model_config() {
		$model          = 'gpt-4o';
		$initial_config = WP_MCP_AI_Model_Config::get_model_config( $model );

		// Update TPM.
		$updated_config        = $initial_config;
		$updated_config['tpm'] = 50000;

		$result = WP_MCP_AI_Model_Config::set_model_config( $model, $updated_config );

		$this->assertTrue( $result );

		// Verify update.
		$retrieved = WP_MCP_AI_Model_Config::get_model_config( $model );

		$this->assertEquals( 50000, $retrieved['tpm'] );

		// Other fields should remain unchanged.
		$this->assertEquals( $initial_config['provider'], $retrieved['provider'] );
		$this->assertEquals( $initial_config['name'], $retrieved['name'] );
	}

	/**
	 * Test delete_model_config removes configuration.
	 */
	public function test_delete_model_config() {
		$model  = 'test-model';
		$config = array(
			'name'     => 'Test Model',
			'provider' => 'test',
		);

		// Save first.
		WP_MCP_AI_Model_Config::set_model_config( $model, $config );

		// Verify it exists.
		$retrieved = WP_MCP_AI_Model_Config::get_model_config( $model );
		$this->assertNotNull( $retrieved );

		// Delete it.
		$result = WP_MCP_AI_Model_Config::delete_model_config( $model );
		$this->assertTrue( $result );

		// Clear cache to ensure we're reading from storage.
		wp_cache_flush();

		// Verify it's deleted (should return default if it matches a known model, or null).
		$retrieved_after = WP_MCP_AI_Model_Config::get_model_config( $model );

		// For custom models, should be null.
		$this->assertNull( $retrieved_after );
	}

	/**
	 * Test prefix matching for model families.
	 */
	public function test_prefix_matching_for_model_families() {
		// Set config for base model.
		$base_model = 'gpt-5';
		$config     = array(
			'name'           => 'GPT-5 Base',
			'provider'       => 'openai',
			'tpm'            => 100000,
			'rpm'            => 1000,
			'context_window' => 256000,
		);

		WP_MCP_AI_Model_Config::set_model_config( $base_model, $config );

		// Request variant model.
		$variant_config = WP_MCP_AI_Model_Config::get_model_config( 'gpt-5-2025-08-07' );

		$this->assertIsArray( $variant_config );
		$this->assertEquals( 'GPT-5 Base', $variant_config['name'] );
		$this->assertEquals( 100000, $variant_config['tpm'] );
	}

	/**
	 * Test longest prefix match wins.
	 */
	public function test_longest_prefix_match() {
		// Set configs for base and variant.
		WP_MCP_AI_Model_Config::set_model_config(
			'gpt-5',
			array(
				'name' => 'GPT-5 Base',
				'tpm'  => 50000,
			)
		);

		WP_MCP_AI_Model_Config::set_model_config(
			'gpt-5-nano',
			array(
				'name' => 'GPT-5 Nano',
				'tpm'  => 100000,
			)
		);

		// Request variant that should match nano.
		$config = WP_MCP_AI_Model_Config::get_model_config( 'gpt-5-nano-2025-01-15' );

		$this->assertIsArray( $config );
		$this->assertEquals( 'GPT-5 Nano', $config['name'] );
		$this->assertEquals( 100000, $config['tpm'] );
	}

	/**
	 * Test config sanitization.
	 */
	public function test_config_sanitization() {
		$model  = 'test-model';
		$config = array(
			'name'           => '<script>alert("xss")</script>Test',
			'tpm'            => '5000',  // String instead of int.
			'rpm'            => '500',   // String instead of int.
			'cost_per_1k'    => '0.05',  // String instead of float.
			'context_window' => 'not a number', // Invalid.
		);

		WP_MCP_AI_Model_Config::set_model_config( $model, $config );

		$retrieved = WP_MCP_AI_Model_Config::get_model_config( $model );

		// Name should be sanitized.
		$this->assertStringNotContainsString( '<script>', $retrieved['name'] );
		$this->assertStringNotContainsString( 'alert', $retrieved['name'] );

		// Numbers should be converted.
		$this->assertSame( 5000, $retrieved['tpm'] );
		$this->assertSame( 500, $retrieved['rpm'] );
		$this->assertEquals( 0.05, $retrieved['cost_per_1k'] );

		// Invalid number should become 0.
		$this->assertSame( 0, $retrieved['context_window'] );
	}

	/**
	 * Test get_available_providers returns configured providers.
	 */
	public function test_get_available_providers() {
		// Set up some API keys.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'    => 'test-key',
				'anthropic_api_key' => 'test-key',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();

		$this->assertIsArray( $providers );
		$this->assertArrayHasKey( 'openai', $providers );
		$this->assertArrayHasKey( 'anthropic', $providers );

		// Gemini should not be present (no API key).
		$this->assertArrayNotHasKey( 'gemini', $providers );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test LM Studio models have correct configuration.
	 */
	public function test_lm_studio_models_configuration() {
		$lm_studio_models = array(
			'qwen/qwen2.5-7b',
			'meta-llama/llama-3.1-8b-instruct',
			'mistralai/mistral-7b-instruct-v0.3',
			'deepseek-ai/deepseek-coder-33b-instruct',
			'microsoft/phi-3.5-mini-instruct',
			'google/gemma-2-9b-it',
		);

		foreach ( $lm_studio_models as $model_id ) {
			$config = WP_MCP_AI_Model_Config::get_model_config( $model_id );

			$this->assertIsArray( $config, "Model $model_id should have a configuration" );
			$this->assertEquals( 'lm_studio', $config['provider'], "Model $model_id should have lm_studio provider" );
			$this->assertEquals( 0.0, $config['cost_per_1k'], "Model $model_id should have zero cost (local)" );
			$this->assertEquals( 'active', $config['status'], "Model $model_id should be active" );
			$this->assertGreaterThan( 0, $config['context_window'], "Model $model_id should have context window" );
			$this->assertGreaterThan( 0, $config['tpm'], "Model $model_id should have TPM limit" );
			$this->assertGreaterThan( 0, $config['rpm'], "Model $model_id should have RPM limit" );
		}
	}

	/**
	 * Test LM Studio provider availability.
	 */
	public function test_lm_studio_provider_availability() {
		// Set up LM Studio endpoint.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();

		$this->assertArrayHasKey( 'lm_studio', $providers );
		$this->assertEquals( 'LM Studio (Local)', $providers['lm_studio'] );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that action hook fires on update.
	 */
	public function test_action_hook_fires_on_update() {
		$hook_fired = false;
		$hook_model = null;

		add_action(
			'wp_mcp_ai_model_config_updated',
			function ( $model, $config ) use ( &$hook_fired, &$hook_model ) {
				$hook_fired = true;
				$hook_model = $model;
			},
			10,
			2
		);

		$model  = 'test-model';
		$config = array( 'name' => 'Test' );

		WP_MCP_AI_Model_Config::set_model_config( $model, $config );

		$this->assertTrue( $hook_fired );
		$this->assertEquals( $model, $hook_model );
	}

	/**
	 * Test persistence across multiple get/set operations.
	 */
	public function test_persistence() {
		$model  = 'persistent-model';
		$config = array(
			'name'     => 'Persistent Model',
			'provider' => 'test',
			'tpm'      => 10000,
		);

		// Set config.
		WP_MCP_AI_Model_Config::set_model_config( $model, $config );

		// Get config multiple times to ensure it persists.
		for ( $i = 0; $i < 5; $i++ ) {
			$retrieved = WP_MCP_AI_Model_Config::get_model_config( $model );
			$this->assertEquals( 'Persistent Model', $retrieved['name'] );
			$this->assertEquals( 10000, $retrieved['tpm'] );
		}

		// Verify it's actually stored in the database.
		$stored_configs = get_option( WP_MCP_AI_Model_Config::CONFIGS_OPTION );
		$this->assertArrayHasKey( $model, $stored_configs );
		$this->assertEquals( 'Persistent Model', $stored_configs[ $model ]['name'] );
	}

	/**
	 * Test caching works properly.
	 */
	public function test_caching() {
		$model  = 'cached-model';
		$config = array(
			'name' => 'Cached Model',
			'tpm'  => 5000,
		);

		WP_MCP_AI_Model_Config::set_model_config( $model, $config );

		// First call should cache.
		$first_call = WP_MCP_AI_Model_Config::get_model_config( $model );

		// Verify cache was set.
		$cache_key    = 'model_' . md5( $model );
		$cached_value = wp_cache_get( $cache_key, WP_MCP_AI_Model_Config::CACHE_GROUP );

		$this->assertNotFalse( $cached_value );
		$this->assertEquals( $first_call, $cached_value );
	}

	/**
	 * Test empty model ID returns null.
	 */
	public function test_empty_model_id_returns_null() {
		$config = WP_MCP_AI_Model_Config::get_model_config( '' );
		$this->assertNull( $config );
	}

	/**
	 * Test invalid config data returns false on set.
	 */
	public function test_invalid_config_returns_false() {
		$result = WP_MCP_AI_Model_Config::set_model_config( 'test', 'not an array' );
		$this->assertFalse( $result );

		$result = WP_MCP_AI_Model_Config::set_model_config( '', array( 'name' => 'Test' ) );
		$this->assertFalse( $result );
	}
}
