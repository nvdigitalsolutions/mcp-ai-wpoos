<?php
/**
 * Test that generate_image() and edit_image() use consistent patterns.
 *
 * This test ensures that both methods use the same image model and API configuration,
 * preventing accidental divergence in future modifications.
 *
 * @package WP_MCP_AI
 */

class Test_Gemini_Image_Pattern_Consistency extends WP_UnitTestCase {
	/**
	 * Test that both tools use the same default model.
	 */
	public function test_both_tools_use_same_default_model() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access the DEFAULT_MODEL constants.
		$generate_reflection = new ReflectionClass( $generate_tool );
		$edit_reflection     = new ReflectionClass( $edit_tool );

		$generate_model = $generate_reflection->getConstant( 'DEFAULT_MODEL' );
		$edit_model     = $edit_reflection->getConstant( 'DEFAULT_MODEL' );

		$this->assertEquals(
			$generate_model,
			$edit_model,
			'Both tools should use the same default image model'
		);

		// Verify it's an image model.
		$this->assertStringContainsString(
			'image',
			strtolower( $generate_model ),
			'Default model should be an image model'
		);
	}

	/**
	 * Test that both tools use the same default MIME type.
	 */
	public function test_both_tools_use_same_default_mime_type() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access the DEFAULT_MIME_TYPE constants.
		$generate_reflection = new ReflectionClass( $generate_tool );
		$edit_reflection     = new ReflectionClass( $edit_tool );

		$generate_mime = $generate_reflection->getConstant( 'DEFAULT_MIME_TYPE' );
		$edit_mime     = $edit_reflection->getConstant( 'DEFAULT_MIME_TYPE' );

		$this->assertEquals(
			$generate_mime,
			$edit_mime,
			'Both tools should use the same default MIME type'
		);
	}

	/**
	 * Test that both tools use the same default aspect ratio.
	 */
	public function test_both_tools_use_same_default_aspect_ratio() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access the DEFAULT_ASPECT_RATIO constants.
		$generate_reflection = new ReflectionClass( $generate_tool );
		$edit_reflection     = new ReflectionClass( $edit_tool );

		$generate_aspect = $generate_reflection->getConstant( 'DEFAULT_ASPECT_RATIO' );
		$edit_aspect     = $edit_reflection->getConstant( 'DEFAULT_ASPECT_RATIO' );

		$this->assertEquals(
			$generate_aspect,
			$edit_aspect,
			'Both tools should use the same default aspect ratio'
		);
	}

	/**
	 * Test that both tools have consistent parameter schemas.
	 */
	public function test_both_tools_have_consistent_parameter_schemas() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$generate_schema = $generate_tool->get_parameters_schema();
		$edit_schema     = $edit_tool->get_parameters_schema();

		// Both should have model parameter.
		$this->assertArrayHasKey( 'properties', $generate_schema );
		$this->assertArrayHasKey( 'properties', $edit_schema );
		$this->assertArrayHasKey( 'model', $generate_schema['properties'] );
		$this->assertArrayHasKey( 'model', $edit_schema['properties'] );

		// Both should have aspect_ratio parameter.
		$this->assertArrayHasKey( 'aspect_ratio', $generate_schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $edit_schema['properties'] );

		// Both should have mime_type parameter.
		$this->assertArrayHasKey( 'mime_type', $generate_schema['properties'] );
		$this->assertArrayHasKey( 'mime_type', $edit_schema['properties'] );

		// Both should have prompt as required.
		$this->assertContains( 'prompt', $generate_schema['required'] );
		$this->assertContains( 'prompt', $edit_schema['required'] );
	}

	/**
	 * Test that both tools specify the same capability flags.
	 */
	public function test_both_tools_use_consistent_capability_flags() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$generate_flags = $generate_tool->get_capability_flags();
		$edit_flags     = $edit_tool->get_capability_flags();

		// Key flags that should be identical.
		$critical_flags = array(
			'requires-credentials',
			'requires-capability',
			'write',
			'async',
			'rate-limited',
			'requires-model',
			'consumes-tokens',
			'model-dependent',
		);

		foreach ( $critical_flags as $flag ) {
			$this->assertContains(
				$flag,
				$generate_flags,
				"Generate tool should have {$flag} capability flag"
			);
			$this->assertContains(
				$flag,
				$edit_flags,
				"Edit tool should have {$flag} capability flag"
			);
		}
	}

	/**
	 * Test that both tools specify compatible model requirements.
	 */
	public function test_both_tools_have_model_requirements() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$generate_requirements = $generate_tool->get_model_requirements();
		$edit_requirements     = $edit_tool->get_model_requirements();

		$this->assertNotEmpty( $generate_requirements, 'Generate tool should have model requirements' );
		$this->assertNotEmpty( $edit_requirements, 'Edit tool should have model requirements' );

		// Both should require image capabilities.
		$this->assertTrue(
			in_array( 'image-generation', $generate_requirements, true ) ||
			in_array( 'image-editing', $generate_requirements, true ),
			'Generate tool should require image capabilities'
		);

		$this->assertTrue(
			in_array( 'image-generation', $edit_requirements, true ) ||
			in_array( 'image-editing', $edit_requirements, true ),
			'Edit tool should require image capabilities'
		);
	}

	/**
	 * Test that both tools have consistent rate limiting rules.
	 */
	public function test_both_tools_have_consistent_rate_limits() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$generate_rules = $generate_tool->get_tool_rules();
		$edit_rules     = $edit_tool->get_tool_rules();

		// Both should have rate limits defined.
		$this->assertArrayHasKey( 'rate_limits', $generate_rules );
		$this->assertArrayHasKey( 'rate_limits', $edit_rules );

		// Rate limits should be identical since they hit the same API.
		$this->assertEquals(
			$generate_rules['rate_limits'],
			$edit_rules['rate_limits'],
			'Both tools should have identical rate limits'
		);
	}

	/**
	 * Test that both tools require the same Gemini providers/models.
	 */
	public function test_both_tools_require_gemini_provider() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$generate_rules = $generate_tool->get_tool_rules();
		$edit_rules     = $edit_tool->get_tool_rules();

		// Both should require Gemini provider.
		$this->assertArrayHasKey( 'model_requirements', $generate_rules );
		$this->assertArrayHasKey( 'model_requirements', $edit_rules );

		$this->assertContains(
			'gemini',
			$generate_rules['model_requirements']['providers'],
			'Generate tool should require Gemini provider'
		);

		$this->assertContains(
			'gemini',
			$edit_rules['model_requirements']['providers'],
			'Edit tool should require Gemini provider'
		);

		// Both should support the same image models.
		$generate_models = $generate_rules['model_requirements']['models'];
		$edit_models     = $edit_rules['model_requirements']['models'];

		$this->assertEquals(
			$generate_models,
			$edit_models,
			'Both tools should support the same Gemini image models'
		);
	}

	/**
	 * Test that both tools use consistent LLM sanitization.
	 */
	public function test_both_tools_implement_llm_sanitizer() {
		$generate_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$edit_tool     = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$this->assertTrue(
			$generate_tool instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface,
			'Generate tool should implement LLM sanitizer interface'
		);

		$this->assertTrue(
			$edit_tool instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface,
			'Edit tool should implement LLM sanitizer interface'
		);

		// Test that sanitization works similarly for both.
		$mock_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'content'       => array(
				'data'     => str_repeat( 'x', 10000 ), // Large base64 data.
				'data_url' => 'data:image/png;base64,' . str_repeat( 'x', 10000 ),
			),
		);

		$generate_sanitized = $generate_tool->sanitize_for_llm( $mock_result );
		$edit_sanitized     = $edit_tool->sanitize_for_llm( $mock_result );

		// Both should strip the large base64 data.
		$this->assertArrayNotHasKey(
			'data',
			isset( $generate_sanitized['content'] ) ? $generate_sanitized['content'] : array(),
			'Generate tool should strip base64 data'
		);

		$this->assertArrayNotHasKey(
			'data',
			isset( $edit_sanitized['content'] ) ? $edit_sanitized['content'] : array(),
			'Edit tool should strip base64 data'
		);
	}
}
