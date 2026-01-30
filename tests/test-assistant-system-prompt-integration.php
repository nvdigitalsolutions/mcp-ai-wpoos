<?php
/**
 * Integration test for assistant system prompt propagation to embedded and server-side providers.
 *
 * Verifies that system prompts from assistant configuration are correctly
 * passed through the entire stack to both embedded (WebLLM) and server-side (Ollama) providers.
 *
 * @package WP_MCP_AI
 */

/**
 * Test assistant system prompt propagation.
 */
class WP_MCP_AI_Assistant_System_Prompt_Integration_Test extends WP_UnitTestCase {

	/**
	 * Assistant post ID for testing.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_REST_Validator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		}

		// Create a test assistant with system prompt.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Add system prompt meta.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_system_prompt',
			'You are a helpful AI assistant specialized in WordPress development. Always provide accurate, well-documented code examples.'
		);

		// Add provider meta.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'ollama' );

		// Add model meta.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'llama2' );

		// Add temperature meta.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_temperature', 0.7 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}
		parent::tearDown();
	}

	/**
	 * Test that assistant configuration includes system prompt.
	 */
	public function test_assistant_configuration_includes_system_prompt() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertIsArray( $config, 'Configuration should be an array' );
		$this->assertArrayHasKey( 'system_prompt', $config, 'Configuration should have system_prompt key' );
		$this->assertNotEmpty( $config['system_prompt'], 'System prompt should not be empty' );
		$this->assertStringContainsString( 'WordPress development', $config['system_prompt'], 'System prompt should contain expected text' );
	}

	/**
	 * Test that REST validator merges system prompt from config into options.
	 */
	public function test_rest_validator_merges_system_prompt() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		// Simulate request with empty options (should use assistant defaults).
		$request_options = array();

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		$this->assertIsArray( $sanitized, 'Sanitized options should be an array' );
		$this->assertArrayHasKey( 'system_prompt', $sanitized, 'Options should have system_prompt key' );
		$this->assertEquals( $config['system_prompt'], $sanitized['system_prompt'], 'System prompt should be from config' );
	}

	/**
	 * Test that REST validator preserves request system prompt when provided.
	 */
	public function test_rest_validator_preserves_request_system_prompt() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		// Simulate request with custom system prompt (should override assistant default).
		$request_options = array(
			'system_prompt' => 'You are a specialized debugging assistant.',
		);

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		$this->assertIsArray( $sanitized, 'Sanitized options should be an array' );
		$this->assertArrayHasKey( 'system_prompt', $sanitized, 'Options should have system_prompt key' );
		$this->assertEquals( 'You are a specialized debugging assistant.', $sanitized['system_prompt'], 'System prompt should be from request' );
		$this->assertNotEquals( $config['system_prompt'], $sanitized['system_prompt'], 'Should not use config when request has value' );
	}

	/**
	 * Test that REST validator merges provider from config.
	 */
	public function test_rest_validator_merges_provider() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$request_options = array();

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		$this->assertArrayHasKey( 'provider', $sanitized, 'Options should have provider key' );
		$this->assertEquals( 'ollama', $sanitized['provider'], 'Provider should be from config' );
	}

	/**
	 * Test that REST validator merges model from config.
	 */
	public function test_rest_validator_merges_model() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$request_options = array();

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		$this->assertArrayHasKey( 'model', $sanitized, 'Options should have model key' );
		$this->assertEquals( 'llama2', $sanitized['model'], 'Model should be from config' );
	}

	/**
	 * Test that REST validator merges temperature from config.
	 */
	public function test_rest_validator_merges_temperature() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$request_options = array();

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		$this->assertArrayHasKey( 'temperature', $sanitized, 'Options should have temperature key' );
		$this->assertEquals( 0.7, $sanitized['temperature'], 'Temperature should be from config' );
	}

	/**
	 * Test that options contain all required fields for LLM call.
	 */
	public function test_options_contain_all_required_fields() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$request_options = array();

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		// Verify all assistant defaults are in options.
		$this->assertArrayHasKey( 'system_prompt', $sanitized, 'Should have system_prompt' );
		$this->assertArrayHasKey( 'provider', $sanitized, 'Should have provider' );
		$this->assertArrayHasKey( 'model', $sanitized, 'Should have model' );
		$this->assertArrayHasKey( 'temperature', $sanitized, 'Should have temperature' );

		// Verify values are correct.
		$this->assertNotEmpty( $sanitized['system_prompt'], 'System prompt should not be empty' );
		$this->assertEquals( 'ollama', $sanitized['provider'], 'Provider should be ollama' );
		$this->assertEquals( 'llama2', $sanitized['model'], 'Model should be llama2' );
		$this->assertEquals( 0.7, $sanitized['temperature'], 'Temperature should be 0.7' );
	}

	/**
	 * Test system prompt with special characters is properly sanitized.
	 */
	public function test_system_prompt_sanitization() {
		// Add system prompt with special characters.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_system_prompt',
			'You are a "helpful" assistant with <special> & characters.'
		);

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$request_options = array();

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_options( $request_options, $config );

		$this->assertArrayHasKey( 'system_prompt', $sanitized, 'Should have system_prompt' );
		// wp_kses_post() is applied, which allows some HTML tags.
		$this->assertIsString( $sanitized['system_prompt'], 'System prompt should be string' );
		$this->assertNotEmpty( $sanitized['system_prompt'], 'System prompt should not be empty after sanitization' );
	}

	/**
	 * Test embedded provider configuration (shortcode scenario).
	 *
	 * This simulates how the shortcode passes configuration to the frontend.
	 */
	public function test_embedded_provider_config_for_shortcode() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		// Simulate shortcode building config for frontend.
		$shortcode_config = array();

		if ( ! empty( $config['system_prompt'] ) ) {
			$shortcode_config['systemPrompt'] = $config['system_prompt'];
		}

		if ( ! empty( $config['provider'] ) ) {
			$shortcode_config['provider'] = $config['provider'];
		}

		if ( ! empty( $config['model'] ) ) {
			$shortcode_config['model'] = $config['model'];
		}

		// Verify config is ready for frontend.
		$this->assertArrayHasKey( 'systemPrompt', $shortcode_config, 'Should have systemPrompt for frontend' );
		$this->assertNotEmpty( $shortcode_config['systemPrompt'], 'systemPrompt should not be empty' );
		$this->assertStringContainsString( 'WordPress development', $shortcode_config['systemPrompt'], 'Should contain expected text' );
	}
}
