<?php
/**
 * Tests for the generate_openai_image tool with orchestration mode.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';

/**
 * Test case for multi-step orchestration in generate_openai_image tool.
 */
class WP_MCP_AI_Generate_OpenAI_Image_Orchestration_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that orchestration mode is disabled by default.
	 */
	public function test_orchestration_mode_disabled_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$result = $tool->execute(
			array(
				'prompt' => 'Test image prompt',
			),
			array( 'user_id' => $user_id )
		);

		// Legacy mode should not include orchestration metadata.
		// Note: Actual image generation may fail without API credentials,
		// but we can still check the structure.
		if ( ! is_wp_error( $result ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result );
			$this->assertArrayNotHasKey( 'execution_id', $result );
		}
	}

	/**
	 * Test parameter schema includes orchestration parameters.
	 */
	public function test_parameter_schema_includes_orchestration_params() {
		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'orchestration_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize_prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'generate_alt_text', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize_output', $schema['properties'] );
		$this->assertArrayHasKey( 'generate_variants', $schema['properties'] );
	}

	/**
	 * Test tool description mentions orchestration.
	 */
	public function test_tool_description_mentions_orchestration() {
		$tool        = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'orchestration', strtolower( $description ) );
	}

	/**
	 * Test validation rejects empty prompt.
	 */
	public function test_validation_rejects_empty_prompt() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test validation rejects too-short prompt.
	 */
	public function test_validation_rejects_too_short_prompt() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$result = $tool->execute(
			array(
				'prompt'             => 'ab', // Too short (< 3 chars).
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test validation rejects too-long prompt.
	 */
	public function test_validation_rejects_too_long_prompt() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$long_prompt = str_repeat( 'A', 4001 ); // 4001 characters (> 4000 max).

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$result = $tool->execute(
			array(
				'prompt'             => $long_prompt,
				'orchestration_mode' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'orchestration_failed', $result->get_error_code() );
	}

	/**
	 * Test validation accepts valid prompt.
	 */
	public function test_validation_accepts_valid_prompt() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		
		// Use reflection to test validation method directly.
		$reflection = new ReflectionClass( $tool );
		$method = $reflection->getMethod( 'step_validate_parameters' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, array(
			'prompt' => 'A beautiful landscape with mountains',
		) );

		// Should return true (not WP_Error).
		$this->assertTrue( $result );
	}

	/**
	 * Test validation rejects invalid size.
	 */
	public function test_validation_rejects_invalid_size() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_validate_parameters' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, array(
			'prompt' => 'Test prompt',
			'size'   => '999x999', // Invalid size.
		) );

		$this->assertWPError( $result );
	}

	/**
	 * Test validation rejects invalid quality.
	 */
	public function test_validation_rejects_invalid_quality() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_validate_parameters' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, array(
			'prompt'  => 'Test prompt',
			'quality' => 'invalid_quality', // Invalid quality.
		) );

		$this->assertWPError( $result );
	}

	/**
	 * Test prompt optimization (if AI is available).
	 */
	public function test_prompt_optimization() {
		if ( ! class_exists( 'WP_MCP_AI_Streaming' ) ) {
			$this->markTestSkipped( 'AI streaming not available' );
		}

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_optimize_prompt' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, array(
			'prompt' => 'mountain',
		), array() );

		// Should either return optimized prompt (string) or error.
		$this->assertTrue( is_string( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Test alt text generation step.
	 */
	public function test_alt_text_generation_step() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'Tool registry not available' );
		}

		// Create a test attachment.
		$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/../assets/test-image.png' );

		if ( ! $attachment_id ) {
			$this->markTestSkipped( 'Could not create test attachment' );
		}

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_generate_alt_text' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, $attachment_id, array( 'prompt' => 'test' ), array() );

		// Should return alt text (string) or error.
		$this->assertTrue( is_string( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Test storage optimization step.
	 */
	public function test_storage_optimization_step() {
		// Create a test attachment.
		$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/../assets/test-image.png' );

		if ( ! $attachment_id ) {
			$this->markTestSkipped( 'Could not create test attachment' );
		}

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_optimize_storage' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, $attachment_id, array(
			'prompt' => 'Beautiful sunset over mountains',
			'model'  => 'dall-e-3',
		), array() );

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'metadata_added', $result );
		}
	}

	/**
	 * Test variant generation step.
	 */
	public function test_variant_generation_step() {
		// Create a test attachment.
		$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/../assets/test-image.png' );

		if ( ! $attachment_id ) {
			$this->markTestSkipped( 'Could not create test attachment' );
		}

		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_generate_variants' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, $attachment_id, array() );

		// Should return array of variants or error.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Test orchestration step logging.
	 */
	public function test_orchestration_step_logging() {
		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		
		$log_method = $reflection->getMethod( 'log_orchestration_step' );
		$log_method->setAccessible( true );
		
		$get_method = $reflection->getMethod( 'get_orchestration_steps' );
		$get_method->setAccessible( true );

		$execution_id = 'test_exec_' . wp_generate_uuid4();

		// Log some steps.
		$log_method->invoke( $tool, $execution_id, 'started', array( 'test' => true ) );
		$log_method->invoke( $tool, $execution_id, 'validate', 'Validating' );
		$log_method->invoke( $tool, $execution_id, 'completed', 'Done' );

		// Retrieve steps.
		$steps = $get_method->invoke( $tool, $execution_id );

		$this->assertIsArray( $steps );
		$this->assertCount( 3, $steps );
		
		$step_names = array_column( $steps, 'name' );
		$this->assertContains( 'started', $step_names );
		$this->assertContains( 'validate', $step_names );
		$this->assertContains( 'completed', $step_names );
	}

	/**
	 * Test error handling in orchestration.
	 */
	public function test_error_handling_in_orchestration() {
		$tool       = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'handle_orchestration_error' );
		$method->setAccessible( true );

		$original_error = new WP_Error( 'test_error', 'Test error message' );
		$execution_id   = 'test_exec_123';

		$result = $method->invoke( $tool, 'test_step', $original_error, $execution_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'orchestration_failed', $result->get_error_code() );
		
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'step', $data );
		$this->assertArrayHasKey( 'original_code', $data );
		$this->assertArrayHasKey( 'execution_id', $data );
	}

	/**
	 * Test backward compatibility with legacy mode.
	 */
	public function test_backward_compatibility_legacy_mode() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();

		// Test with orchestration_mode explicitly false.
		$result1 = $tool->execute(
			array(
				'prompt'             => 'Test prompt 1',
				'orchestration_mode' => false,
			),
			array( 'user_id' => $user_id )
		);

		// Test with orchestration_mode not set (default).
		$result2 = $tool->execute(
			array(
				'prompt' => 'Test prompt 2',
			),
			array( 'user_id' => $user_id )
		);

		// Both should work in legacy mode (or fail with API errors, not orchestration errors).
		if ( ! is_wp_error( $result1 ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result1 );
		} else {
			// If error, it should not be orchestration error.
			$this->assertNotEquals( 'orchestration_failed', $result1->get_error_code() );
		}

		if ( ! is_wp_error( $result2 ) ) {
			$this->assertArrayNotHasKey( 'orchestration', $result2 );
		} else {
			$this->assertNotEquals( 'orchestration_failed', $result2->get_error_code() );
		}
	}
}
