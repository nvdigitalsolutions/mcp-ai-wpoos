<?php
/**
 * Tests for Veo content policy violation handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for content policy error detection and fallback prevention.
 */
class Test_Veo_Content_Policy extends WP_UnitTestCase {

	/**
	 * Test is_content_policy_error detects usage guidelines violations.
	 */
	public function test_is_content_policy_error_detects_usage_guidelines() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'is_content_policy_error' );
		$method->setAccessible( true );

		// The exact error message from the problem report.
		$this->assertTrue(
			$method->invoke( $service, 'The prompt could not be submitted. This prompt contains words that violate Gemini API\'s usage guidelines. Try rephrasing the prompt.' ),
			'Should detect usage guidelines violation'
		);

		// Generic usage guidelines indicator.
		$this->assertTrue(
			$method->invoke( $service, 'This content does not comply with our policies.' ),
			'Should detect "does not comply" keyword'
		);

		// Content policy keyword.
		$this->assertTrue(
			$method->invoke( $service, 'content policy restriction applied' ),
			'Should detect "content policy" phrase'
		);

		// Unsafe content.
		$this->assertTrue(
			$method->invoke( $service, 'Request blocked due to unsafe content detected.' ),
			'Should detect "unsafe content" phrase'
		);

		// Harmful content.
		$this->assertTrue(
			$method->invoke( $service, 'Prompt rejected: harmful content found.' ),
			'Should detect "harmful content" phrase'
		);

		// Does not comply.
		$this->assertTrue(
			$method->invoke( $service, 'This prompt does not comply with our guidelines.' ),
			'Should detect "does not comply" phrase'
		);
	}

	/**
	 * Test is_content_policy_error does not flag unrelated errors.
	 */
	public function test_is_content_policy_error_does_not_flag_unrelated_errors() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'is_content_policy_error' );
		$method->setAccessible( true );

		$this->assertFalse(
			$method->invoke( $service, 'Quota exceeded. Please try again later.' ),
			'Should not flag quota errors as content policy'
		);

		$this->assertFalse(
			$method->invoke( $service, 'Invalid argument: duration must be between 4 and 8 seconds.' ),
			'Should not flag invalid argument errors as content policy'
		);

		$this->assertFalse(
			$method->invoke( $service, 'Model not found.' ),
			'Should not flag model not found errors as content policy'
		);

		$this->assertFalse(
			$method->invoke( $service, 'Service temporarily unavailable.' ),
			'Should not flag service unavailable errors as content policy'
		);
	}

	/**
	 * Test should_fallback_to_veo_2 returns false for content policy violations.
	 */
	public function test_should_not_fallback_on_content_policy_violation_error_code() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		$error = new WP_Error(
			'wp_mcp_ai_content_policy_violation',
			'The video prompt was rejected due to content policy.',
			array( 'status' => 400 )
		);

		$this->assertFalse(
			$method->invoke( $service, $error ),
			'Should not fallback to Veo 2 for content policy violations (same prompt will fail)'
		);
	}

	/**
	 * Test should_fallback_to_veo_2 returns false when message contains usage guidelines text.
	 */
	public function test_should_not_fallback_on_usage_guidelines_message() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		$error = new WP_Error(
			'wp_mcp_ai_veo_request_failed',
			'This prompt contains words that violate Gemini API\'s usage guidelines. Try rephrasing the prompt.',
			array( 'status' => 400 )
		);

		$this->assertFalse(
			$method->invoke( $service, $error ),
			'Should not fallback to Veo 2 when message references usage guidelines'
		);
	}

	/**
	 * Test that quota errors still trigger Veo 2 fallback.
	 */
	public function test_quota_errors_still_trigger_fallback() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		$error = new WP_Error(
			'wp_mcp_ai_quota_exceeded',
			'Quota exceeded. Please try again later.',
			array( 'status' => 429 )
		);

		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Quota errors should still trigger Veo 2 fallback'
		);
	}

	/**
	 * Test tool schema includes design_context parameter.
	 */
	public function test_tool_schema_includes_design_context() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey(
			'design_context',
			$schema['properties'],
			'Schema should include design_context parameter'
		);

		$param = $schema['properties']['design_context'];
		$this->assertEquals( 'boolean', $param['type'] );
		$this->assertFalse( $param['default'], 'design_context should default to false' );
		$this->assertNotEmpty( $param['description'], 'design_context parameter should have a description' );
	}

	/**
	 * Test enhance_prompt_with_style adds design context prefix when requested.
	 */
	public function test_design_context_prefix_applied() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$method = new ReflectionMethod( $tool, 'enhance_prompt_with_style' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$tool,
			array(
				'prompt'         => 'futuristic cityscape at night',
				'design_context' => true,
			)
		);

		$this->assertStringStartsWith(
			'Design concept visualization: ',
			$prompt,
			'Should prepend design context prefix'
		);

		$this->assertStringContainsString(
			'futuristic cityscape at night',
			$prompt,
			'Original prompt should be preserved'
		);
	}

	/**
	 * Test enhance_prompt_with_style does not add design context when not requested.
	 */
	public function test_design_context_prefix_not_applied_by_default() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$method = new ReflectionMethod( $tool, 'enhance_prompt_with_style' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$tool,
			array(
				'prompt' => 'futuristic cityscape at night',
			)
		);

		$this->assertEquals(
			'futuristic cityscape at night',
			$prompt,
			'Should not modify prompt when design_context is not set'
		);
	}

	/**
	 * Test design context works alongside style prefix.
	 */
	public function test_design_context_combined_with_style() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$method = new ReflectionMethod( $tool, 'enhance_prompt_with_style' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$tool,
			array(
				'prompt'         => 'urban landscape',
				'style'          => 'cinematic',
				'design_context' => true,
			)
		);

		$this->assertStringStartsWith(
			'Design concept visualization: ',
			$prompt,
			'Design context prefix should appear first'
		);

		$this->assertStringContainsString(
			'Cinematic shot with professional lighting and composition: ',
			$prompt,
			'Style prefix should also be present'
		);

		$this->assertStringContainsString(
			'urban landscape',
			$prompt,
			'Original prompt should be preserved'
		);
	}
}
