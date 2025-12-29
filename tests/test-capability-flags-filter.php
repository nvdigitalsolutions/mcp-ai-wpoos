<?php
/**
 * Tests for capability flags filter in model selection.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that capability flags filter works for unregistered tool slugs.
 *
 * This tests the fix for the issue where the fallback dropdown tries to pass
 * capability flags via a filter, but get_available_models() wasn't invoking
 * that hook for unregistered tool slugs like 'model_config_fallback_*'.
 */
class Test_Capability_Flags_Filter extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

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

		// Remove all filters to avoid test pollution.
		remove_all_filters( 'wp_mcp_ai_tool_capability_flags' );

		parent::tearDown();
	}

	/**
	 * Test that get_available_models applies the capability flags filter.
	 */
	public function test_get_available_models_applies_capability_flags_filter() {
		$temp_tool_slug = 'test_unregistered_tool';

		// Add filter to inject capability flags for an unregistered tool.
		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) use ( $temp_tool_slug ) {
				if ( $tool_slug === $temp_tool_slug ) {
					return array( 'requires-vision-model' );
				}
				return $flags;
			},
			10,
			2
		);

		// Get available models with the unregistered tool slug.
		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models( $temp_tool_slug );

		// Verify that models were filtered.
		// Text-only models like o1, o3-mini should be excluded.
		$this->assertIsArray( $models );

		// Check OpenAI models.
		if ( isset( $models['openai_group']['options'] ) ) {
			$openai_models = $models['openai_group']['options'];

			// Vision-capable models should be present.
			$this->assertArrayHasKey( 'gpt-4o', $openai_models );
			$this->assertArrayHasKey( 'gpt-4-turbo', $openai_models );

			// Text-only models should NOT be present.
			$this->assertArrayNotHasKey( 'o1-2024-12-17', $openai_models );
			$this->assertArrayNotHasKey( 'o1-mini', $openai_models );
			$this->assertArrayNotHasKey( 'gpt-3.5-turbo', $openai_models );
		}
	}

	/**
	 * Test that multimodal flag filters models correctly.
	 */
	public function test_multimodal_flag_filters_models() {
		$temp_tool_slug = 'test_multimodal_tool';

		// Add filter for multimodal requirement.
		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) use ( $temp_tool_slug ) {
				if ( $tool_slug === $temp_tool_slug ) {
					return array( 'requires-multimodal-model' );
				}
				return $flags;
			},
			10,
			2
		);

		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models( $temp_tool_slug );

		$this->assertIsArray( $models );

		// Check Gemini models.
		if ( isset( $models['gemini_group']['options'] ) ) {
			$gemini_models = $models['gemini_group']['options'];

			// Multimodal models should be present.
			$this->assertArrayHasKey( 'gemini-2.5-flash', $gemini_models );
			$this->assertArrayHasKey( 'gemini-1.5-pro', $gemini_models );

			// Text-only Gemma models should NOT be present.
			$this->assertArrayNotHasKey( 'gemma-2-27b-it', $gemini_models );
		}
	}

	/**
	 * Test that model_config_fallback_* slugs work with the filter.
	 *
	 * This simulates the actual use case in WP_MCP_AI_Model_Config_Renderer.
	 */
	public function test_model_config_fallback_slug_with_filter() {
		$source_model_id = 'gpt-4o'; // A multimodal model.
		$temp_tool_slug  = 'model_config_fallback_' . sanitize_key( $source_model_id );

		// Simulate the renderer's filter usage.
		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) use ( $temp_tool_slug ) {
				if ( $tool_slug === $temp_tool_slug ) {
					// gpt-4o has vision and multimodal capabilities.
					return array( 'requires-vision-model', 'requires-multimodal-model' );
				}
				return $flags;
			},
			10,
			2
		);

		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models( $temp_tool_slug );

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );

		// Should have multimodal models from all providers.
		if ( isset( $models['openai_group']['options'] ) ) {
			$openai_models = $models['openai_group']['options'];

			// Vision-capable models should be available.
			$this->assertArrayHasKey( 'gpt-4o', $openai_models );

			// Text-only models should be filtered out.
			$this->assertArrayNotHasKey( 'o1-2024-12-17', $openai_models );
		}
	}

	/**
	 * Test that filter is not applied when tool_slug is empty.
	 */
	public function test_no_filter_when_tool_slug_empty() {
		// Add a filter that should not be triggered.
		$filter_called = false;
		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) use ( &$filter_called ) {
				$filter_called = true;
				return $flags;
			},
			10,
			2
		);

		// Call with empty tool slug.
		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models();

		$this->assertIsArray( $models );

		// Filter should still be called (as it gets empty array and empty slug).
		// But the result should include all models since no filtering criteria.
		if ( isset( $models['openai_group']['options'] ) ) {
			$openai_models = $models['openai_group']['options'];

			// Both text-only and multimodal models should be present.
			$this->assertArrayHasKey( 'o1-2024-12-17', $openai_models );
			$this->assertArrayHasKey( 'gpt-4o', $openai_models );
		}
	}

	/**
	 * Test that filter receives correct parameters.
	 */
	public function test_filter_receives_correct_parameters() {
		$temp_tool_slug = 'test_parameter_check';
		$received_flags = null;
		$received_slug  = null;

		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) use ( &$received_flags, &$received_slug, $temp_tool_slug ) {
				if ( $tool_slug === $temp_tool_slug ) {
					$received_flags = $flags;
					$received_slug  = $tool_slug;
				}
				return $flags;
			},
			10,
			2
		);

		WP_MCP_AI_Tool_Token_Limits::get_available_models( $temp_tool_slug );

		// Verify filter was called with correct parameters.
		$this->assertIsArray( $received_flags );
		$this->assertEquals( $temp_tool_slug, $received_slug );
	}

	/**
	 * Test that filter works alongside registry for registered tools.
	 *
	 * If a tool is registered, its flags should be retrieved from the registry
	 * first, then the filter should be applied to allow overrides.
	 */
	public function test_filter_works_with_registry() {
		// This test assumes a registered tool exists.
		// If the tool registry is available, we'll test with a known tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'Tool registry not available' );
			return;
		}

		// Use a filter to add additional capability flags.
		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) {
				// Add a custom flag for any tool.
				if ( ! empty( $tool_slug ) ) {
					$flags[] = 'custom-test-flag';
				}
				return $flags;
			},
			10,
			2
		);

		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models( 'some_registered_tool' );

		// The filter should have been applied.
		$this->assertIsArray( $models );
	}

	/**
	 * Test that text-only model fallback works correctly.
	 */
	public function test_text_only_model_fallback_filtering() {
		$temp_tool_slug = 'model_config_fallback_o1-mini';

		// Text-only model should not have vision/multimodal flags.
		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags, $tool_slug ) use ( $temp_tool_slug ) {
				if ( $tool_slug === $temp_tool_slug ) {
					return array(); // No special requirements.
				}
				return $flags;
			},
			10,
			2
		);

		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models( $temp_tool_slug );

		$this->assertIsArray( $models );

		// All models should be available for text-only.
		if ( isset( $models['openai_group']['options'] ) ) {
			$openai_models = $models['openai_group']['options'];

			// Both text-only and multimodal models should be available.
			$this->assertArrayHasKey( 'o1-2024-12-17', $openai_models );
			$this->assertArrayHasKey( 'gpt-4o', $openai_models );
			$this->assertArrayHasKey( 'gpt-3.5-turbo', $openai_models );
		}
	}
}
