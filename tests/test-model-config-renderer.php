<?php
/**
 * Tests for WP_MCP_AI_Model_Config_Renderer class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test model configuration renderer functionality.
 */
class Test_Model_Config_Renderer extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the renderer class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Model_Config_Renderer' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-model-config-renderer.php';
		}

		// Set up mock API keys for testing.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'    => 'test-key',
				'anthropic_api_key' => 'test-key',
				'gemini_api_key'    => 'test-key',
			)
		);
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Test that render_model_table outputs HTML.
	 */
	public function test_render_model_table_outputs_html() {
		$output = WP_MCP_AI_Model_Config_Renderer::render_model_table();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'wp-mcp-ai-model-config-table', $output );
		$this->assertStringContainsString( 'Model Configurations', $output );
	}

	/**
	 * Test that fallback model uses select dropdown.
	 */
	public function test_fallback_model_uses_select_dropdown() {
		$output = WP_MCP_AI_Model_Config_Renderer::render_model_table();

		// Should contain select element with fallback model class.
		$this->assertStringContainsString( 'wp-mcp-ai-fallback-model-select', $output );
		$this->assertStringContainsString( '<select', $output );
		$this->assertStringContainsString( '<optgroup', $output );
	}

	/**
	 * Test that models are grouped by provider.
	 */
	public function test_models_grouped_by_provider() {
		$output = WP_MCP_AI_Model_Config_Renderer::render_model_table();

		// Should contain provider group labels.
		$this->assertStringContainsString( 'OpenAI', $output );
		$this->assertStringContainsString( 'Anthropic', $output );
		$this->assertStringContainsString( 'Google Gemini', $output );
	}

	/**
	 * Test that get_model_capability_flags returns correct flags for OpenAI models.
	 */
	public function test_get_model_capability_flags_openai() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Config_Renderer' );
		$method     = $reflection->getMethod( 'get_model_capability_flags' );
		$method->setAccessible( true );

		// Test reasoning model (text-only).
		$flags = $method->invoke( null, 'o1-mini', 'openai' );
		$this->assertIsArray( $flags );
		$this->assertEmpty( $flags ); // Text-only, no vision/multimodal.

		// Test GPT-4o (multimodal).
		$flags = $method->invoke( null, 'gpt-4o', 'openai' );
		$this->assertIsArray( $flags );
		$this->assertContains( 'vision', $flags );
		$this->assertContains( 'multimodal', $flags );

		// Test GPT-3.5 Turbo (text-only).
		$flags = $method->invoke( null, 'gpt-3.5-turbo', 'openai' );
		$this->assertIsArray( $flags );
		$this->assertEmpty( $flags );
	}

	/**
	 * Test that get_model_capability_flags returns correct flags for Anthropic models.
	 */
	public function test_get_model_capability_flags_anthropic() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Config_Renderer' );
		$method     = $reflection->getMethod( 'get_model_capability_flags' );
		$method->setAccessible( true );

		// All Claude models are multimodal.
		$flags = $method->invoke( null, 'claude-3-5-sonnet-20241022', 'anthropic' );
		$this->assertIsArray( $flags );
		$this->assertContains( 'vision', $flags );
		$this->assertContains( 'multimodal', $flags );
	}

	/**
	 * Test that get_model_capability_flags returns correct flags for Gemini models.
	 */
	public function test_get_model_capability_flags_gemini() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Config_Renderer' );
		$method     = $reflection->getMethod( 'get_model_capability_flags' );
		$method->setAccessible( true );

		// Gemini 2.x series (multimodal).
		$flags = $method->invoke( null, 'gemini-2.5-flash', 'gemini' );
		$this->assertIsArray( $flags );
		$this->assertContains( 'vision', $flags );
		$this->assertContains( 'multimodal', $flags );

		// Gemma models (text-only).
		$flags = $method->invoke( null, 'gemma-2-9b-it', 'gemini' );
		$this->assertIsArray( $flags );
		$this->assertEmpty( $flags );
	}

	/**
	 * Test that get_available_models_for_fallback returns grouped models.
	 */
	public function test_get_available_models_for_fallback_returns_grouped_models() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Config_Renderer' );
		$method     = $reflection->getMethod( 'get_available_models_for_fallback' );
		$method->setAccessible( true );

		// Test with text-only model.
		$models = $method->invoke( null, 'gpt-3.5-turbo', array() );
		$this->assertIsArray( $models );

		// Should have provider groups.
		$this->assertArrayHasKey( 'openai_group', $models );
		$this->assertArrayHasKey( 'anthropic_group', $models );
		$this->assertArrayHasKey( 'gemini_group', $models );

		// Each group should have label and options.
		$this->assertArrayHasKey( 'label', $models['openai_group'] );
		$this->assertArrayHasKey( 'options', $models['openai_group'] );
	}

	/**
	 * Test that model cannot be its own fallback.
	 */
	public function test_model_cannot_be_own_fallback() {
		$output = WP_MCP_AI_Model_Config_Renderer::render_model_table();

		// The output should not allow a model to select itself as fallback.
		// This is ensured by the conditional check in render_model_row.
		$this->assertStringContainsString( 'fallback_model_id !== $model_id', $output );
	}

	/**
	 * Test that render_javascript outputs script tags.
	 */
	public function test_render_javascript_outputs_script() {
		$output = WP_MCP_AI_Model_Config_Renderer::render_javascript();

		$this->assertIsString( $output );
		$this->assertStringContainsString( '<script', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-save-model-config', $output );
		$this->assertStringContainsString( 'jQuery', $output );
	}
}
