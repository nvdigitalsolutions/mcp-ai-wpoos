<?php
/**
 * Integration tests for Kimi provider.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integration test cases for Kimi provider.
 *
 * Tests the complete integration of Kimi with the NV oOS system,
 * including settings, provider registration, and chat flows.
 */
class Test_Kimi_Integration extends WP_UnitTestCase {

	/**
	 * Kimi client instance.
	 *
	 * @var WP_MCP_AI_Kimi_Client
	 */
	private $client;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-kimi-client.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Model_Config' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-config.php';
		}

		$this->client = new WP_MCP_AI_Kimi_Client();

		// Clear settings.
		delete_option( 'wp_mcp_ai_settings' );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Kimi Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test assistant for Kimi integration',
			)
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up assistant.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		// Clean up settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Test Kimi provider appears in available providers when enabled.
	 */
	public function test_kimi_appears_in_available_providers_when_enabled() {
		// Configure Kimi with enable flag and API key.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_kimi'  => true,
				'kimi_api_key' => 'test-api-key',
				'kimi_model'   => 'kimi-k2.6',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();

		$this->assertArrayHasKey( 'kimi', $providers );
		$this->assertEquals( 'Kimi (Moonshot AI)', $providers['kimi'] );
	}

	/**
	 * Test Kimi provider does not appear when disabled.
	 */
	public function test_kimi_not_in_providers_when_disabled() {
		// Configure Kimi as disabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_kimi'  => false,
				'kimi_api_key' => 'test-api-key',
				'kimi_model'   => 'kimi-k2.6',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();

		$this->assertArrayNotHasKey( 'kimi', $providers );
	}

	/**
	 * Test Kimi provider does not appear when API key missing.
	 */
	public function test_kimi_not_in_providers_when_api_key_missing() {
		// Configure Kimi without API key.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_kimi' => true,
				'kimi_model'  => 'kimi-k2.6',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();

		$this->assertArrayNotHasKey( 'kimi', $providers );
	}

	/**
	 * Test Kimi settings save correctly.
	 */
	public function test_kimi_settings_save_correctly() {
		$settings = array(
			'enable_kimi'      => true,
			'kimi_api_key'     => 'sk-test123',
			'kimi_model'       => 'kimi-k2.6',
			'kimi_base_url'    => 'https://custom.kimi.com/v1',
			'kimi_timeout'     => 120,
			'kimi_temperature' => 0.8,
			'kimi_max_tokens'  => 2048,
		);

		update_option( 'wp_mcp_ai_settings', $settings );

		$saved_settings = get_option( 'wp_mcp_ai_settings' );

		$this->assertTrue( $saved_settings['enable_kimi'] );
		$this->assertEquals( 'sk-test123', $saved_settings['kimi_api_key'] );
		$this->assertEquals( 'kimi-k2.6', $saved_settings['kimi_model'] );
		$this->assertEquals( 'https://custom.kimi.com/v1', $saved_settings['kimi_base_url'] );
		$this->assertEquals( 120, $saved_settings['kimi_timeout'] );
		$this->assertEquals( 0.8, $saved_settings['kimi_temperature'] );
		$this->assertEquals( 2048, $saved_settings['kimi_max_tokens'] );
	}

	/**
	 * Test assistant can be configured with Kimi provider.
	 */
	public function test_assistant_can_use_kimi_provider() {
		// Enable Kimi.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_kimi'  => true,
				'kimi_api_key' => 'test-api-key',
				'kimi_model'   => 'kimi-k2.6',
			)
		);

		// Set assistant provider to Kimi.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'kimi' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'kimi-k2.6' );

		$provider = get_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', true );
		$model    = get_post_meta( $this->assistant_id, '_wp_mcp_ai_model', true );

		$this->assertEquals( 'kimi', $provider );
		$this->assertEquals( 'kimi-k2.6', $model );
	}

	/**
	 * Test Kimi client respects custom base URL.
	 */
	public function test_kimi_client_uses_custom_base_url() {
		$custom_url = 'https://proxy.example.com/kimi/v1';

		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_api_key'  => 'test-key',
				'kimi_base_url' => $custom_url,
			)
		);

		$base_url = $this->client->get_base_url();

		$this->assertEquals( $custom_url, $base_url );
	}

	/**
	 * Test Kimi client uses default base URL when not configured.
	 */
	public function test_kimi_client_uses_default_base_url() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_api_key' => 'test-key',
			)
		);

		$base_url = $this->client->get_base_url();

		$this->assertEquals( 'https://api.moonshot.ai/v1', $base_url );
	}

	/**
	 * Test model configuration is respected.
	 */
	public function test_model_configuration_is_respected() {
		$models = array(
			'kimi-k2.6',
			'kimi-k2.5',
			'kimi-k2',
			'kimi-k2-thinking',
		);

		foreach ( $models as $model ) {
			update_option(
				'wp_mcp_ai_settings',
				array(
					'enable_kimi'  => true,
					'kimi_api_key' => 'test-key',
					'kimi_model'   => $model,
				)
			);

			$configured_model = $this->client->get_model();
			$this->assertEquals( $model, $configured_model );
		}
	}

	/**
	 * Test tool support detection for different models.
	 */
	public function test_tool_support_detection_per_model() {
		// Models that support tools.
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2.7-code' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2.6' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2.5' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2' ) );

		// Models that don't support tools.
		$this->assertFalse( $this->client->model_supports_tools( 'kimi-k2-thinking' ) );
	}

	/**
	 * Test context window sizes are correct.
	 */
	public function test_context_window_sizes() {
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2.7-code' ) );
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2.6' ) );
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2.5' ) );
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2' ) );
		$this->assertEquals( 131072, $this->client->get_context_window( 'moonshot-v1' ) );
	}

	/**
	 * Test Kimi appears in admin settings connector definitions.
	 */
	public function test_kimi_in_connector_definitions() {
		$settings_class = new ReflectionClass( 'WP_MCP_AI_Admin_Settings' );
		$method         = $settings_class->getMethod( 'get_connector_definitions' );
		$method->setAccessible( true );

		$definitions = $method->invoke( null );

		$this->assertArrayHasKey( 'kimi', $definitions );
		$this->assertEquals( 'Kimi (Moonshot AI)', $definitions['kimi']['label'] );
		$this->assertContains( 'kimi_api_key', $definitions['kimi']['required_options'] );
	}

	/**
	 * Test sanitization of Kimi settings.
	 */
	public function test_kimi_settings_sanitization() {
		$section = new WP_MCP_AI_Section_Kimi();

		$input = array(
			'enable_kimi'      => '1',
			'kimi_api_key'     => '  sk-test123  ',
			'kimi_model'       => 'kimi-k2.6',
			'kimi_timeout'     => '120',
			'kimi_temperature' => '0.85',
			'kimi_max_tokens'  => '4096',
		);

		$sanitized = $section->sanitize_settings( $input );

		$this->assertTrue( $sanitized['enable_kimi'] );
		$this->assertEquals( 'sk-test123', $sanitized['kimi_api_key'] );
		$this->assertEquals( 'kimi-k2.6', $sanitized['kimi_model'] );
		$this->assertEquals( 120, $sanitized['kimi_timeout'] );
		$this->assertEquals( 0.85, $sanitized['kimi_temperature'] );
		$this->assertEquals( 4096, $sanitized['kimi_max_tokens'] );
	}

	/**
	 * Test invalid model is sanitized to default.
	 */
	public function test_invalid_model_sanitized_to_default() {
		$section = new WP_MCP_AI_Section_Kimi();

		$input = array(
			'kimi_model' => 'invalid-model',
		);

		$sanitized = $section->sanitize_settings( $input );

		$this->assertEquals( 'kimi-k3', $sanitized['kimi_model'] );
	}

	/**
	 * Test timeout boundaries are enforced.
	 */
	public function test_timeout_boundaries() {
		$section = new WP_MCP_AI_Section_Kimi();

		// Too low.
		$input     = array( 'kimi_timeout' => '1' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 5, $sanitized['kimi_timeout'] );

		// Too high.
		$input     = array( 'kimi_timeout' => '500' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 300, $sanitized['kimi_timeout'] );

		// Valid.
		$input     = array( 'kimi_timeout' => '60' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 60, $sanitized['kimi_timeout'] );
	}

	/**
	 * Test temperature boundaries are enforced.
	 */
	public function test_temperature_boundaries() {
		$section = new WP_MCP_AI_Section_Kimi();

		// Too low.
		$input     = array( 'kimi_temperature' => '-0.5' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 0.0, $sanitized['kimi_temperature'] );

		// Too high.
		$input     = array( 'kimi_temperature' => '3.0' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 2.0, $sanitized['kimi_temperature'] );

		// Valid.
		$input     = array( 'kimi_temperature' => '0.7' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 0.7, $sanitized['kimi_temperature'] );
	}

	/**
	 * Test max tokens boundaries are enforced.
	 */
	public function test_max_tokens_boundaries() {
		$section = new WP_MCP_AI_Section_Kimi();

		// Too low.
		$input     = array( 'kimi_max_tokens' => '0' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 1, $sanitized['kimi_max_tokens'] );

		// Too high.
		$input     = array( 'kimi_max_tokens' => '10000' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 8192, $sanitized['kimi_max_tokens'] );

		// Valid.
		$input     = array( 'kimi_max_tokens' => '4096' );
		$sanitized = $section->sanitize_settings( $input );
		$this->assertEquals( 4096, $sanitized['kimi_max_tokens'] );
	}

	/**
	 * Test available models list is correct.
	 */
	public function test_available_models_list() {
		$models = WP_MCP_AI_Section_Kimi::get_available_models();

		$this->assertArrayHasKey( 'kimi-k2.7-code', $models );
		$this->assertArrayHasKey( 'kimi-k2.6', $models );
		$this->assertArrayHasKey( 'kimi-k2.5', $models );
		$this->assertArrayHasKey( 'kimi-k2', $models );
		$this->assertArrayHasKey( 'kimi-k2-thinking', $models );

		$this->assertStringContainsString( 'K2.7', $models['kimi-k2.7-code'] );
		$this->assertStringContainsString( '256K', $models['kimi-k2.7-code'] );
	}

	/**
	 * Test payload builder includes all options.
	 */
	public function test_payload_builder_includes_all_options() {
		$settings_class = new ReflectionClass( 'WP_MCP_AI_Kimi_Client' );
		$method         = $settings_class->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$options = array(
			'temperature'           => 0.8,
			'max_completion_tokens' => 100,
			'top_p'                 => 0.9,
			'stop'                  => array( 'STOP' ),
			'response_format'       => array( 'type' => 'json_object' ),
			'stream'                => true,
			'prompt_cache_key'      => 'test-cache-key',
			'safety_identifier'     => 'user-123',
		);

		$payload = $method->invoke( $this->client, $messages, $options, 'kimi-k2.6' );

		$this->assertEquals( 'kimi-k2.6', $payload['model'] );
		$this->assertEquals( $messages, $payload['messages'] );
		$this->assertEquals( 0.8, $payload['temperature'] );
		$this->assertEquals( 100, $payload['max_completion_tokens'] );
		$this->assertEquals( 0.9, $payload['top_p'] );
		$this->assertEquals( array( 'STOP' ), $payload['stop'] );
		$this->assertEquals( array( 'type' => 'json_object' ), $payload['response_format'] );
		$this->assertTrue( $payload['stream'] );
		$this->assertEquals( 'test-cache-key', $payload['prompt_cache_key'] );
		$this->assertEquals( 'user-123', $payload['safety_identifier'] );
	}

	/**
	 * Test payload builder excludes tools for unsupported models.
	 */
	public function test_payload_excludes_tools_for_unsupported_models() {
		$settings_class = new ReflectionClass( 'WP_MCP_AI_Kimi_Client' );
		$method         = $settings_class->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'test_function',
						'description' => 'Test function',
					),
				),
			),
		);

		// Tools should be included for K2.6.
		$payload = $method->invoke( $this->client, $messages, $options, 'kimi-k2.6' );
		$this->assertArrayHasKey( 'tools', $payload );

		// Tools should be excluded for thinking model.
		$payload = $method->invoke( $this->client, $messages, $options, 'kimi-k2-thinking' );
		$this->assertArrayNotHasKey( 'tools', $payload );
	}

	/**
	 * Test response normalization.
	 */
	public function test_response_normalization() {
		$settings_class = new ReflectionClass( 'WP_MCP_AI_Kimi_Client' );
		$method         = $settings_class->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$api_response = array(
			'id'      => 'test-id-123',
			'object'  => 'chat.completion',
			'created' => 1234567890,
			'model'   => 'kimi-k2.6',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello!',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
				'total_tokens'      => 15,
			),
		);

		$normalized = $method->invoke( $this->client, $api_response );

		$this->assertEquals( 'Hello!', $normalized['content'] );
		$this->assertEquals( 'stop', $normalized['finish_reason'] );
		$this->assertEquals( 'kimi-k2.6', $normalized['model'] );
		$this->assertEquals( 'kimi', $normalized['provider'] );
		$this->assertArrayHasKey( 'raw', $normalized );
		$this->assertEquals( 'test-id-123', $normalized['raw']['id'] );
		$this->assertEquals( 'chat.completion', $normalized['raw']['object'] );
		$this->assertEquals( 1234567890, $normalized['raw']['created'] );
		$this->assertCount( 1, $normalized['choices'] );
		$this->assertEquals( 'assistant', $normalized['choices'][0]['message']['role'] );
		$this->assertEquals( 'Hello!', $normalized['choices'][0]['message']['content'] );
		$this->assertArrayHasKey( 'usage', $normalized );
		$this->assertEquals( 10, $normalized['usage']['prompt_tokens'] );
		$this->assertEquals( 5, $normalized['usage']['completion_tokens'] );
		$this->assertEquals( 15, $normalized['usage']['total_tokens'] );
	}
}
