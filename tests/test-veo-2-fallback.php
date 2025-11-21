<?php
/**
 * Test Veo 2.0 fallback functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Veo 2.0 fallback when Veo 3.1 fails.
 */
class Test_Veo_2_Fallback extends WP_UnitTestCase {

	/**
	 * Test that Veo 2 constant is defined.
	 */
	public function test_veo_2_constant_defined() {
		// Use reflection to access class constant.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$reflection = new ReflectionClass( $service );
		
		$this->assertTrue(
			$reflection->hasConstant( 'VEO_2_MODEL' ),
			'VEO_2_MODEL constant should be defined'
		);
		
		$constant = $reflection->getConstant( 'VEO_2_MODEL' );
		
		$this->assertEquals(
			'veo-2.0-generate-001',
			$constant,
			'VEO_2_MODEL should be veo-2.0-generate-001'
		);
	}

	/**
	 * Test that Veo 2 minimum duration constant is defined.
	 */
	public function test_veo_2_min_duration_constant() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$reflection = new ReflectionClass( $service );
		$constant = $reflection->getConstant( 'VEO_2_MIN_DURATION' );
		
		$this->assertEquals(
			5,
			$constant,
			'VEO_2_MIN_DURATION should be 5 seconds'
		);
	}

	/**
	 * Test should_fallback_to_veo_2 method detects quota errors.
	 */
	public function test_should_fallback_on_quota_error() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		// Test quota exceeded error.
		$error = new WP_Error( 'quota_exceeded', 'Quota exceeded for this model' );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on quota exceeded error'
		);

		// Test rate limit error.
		$error = new WP_Error( 'rate_limit', 'Rate limit exceeded' );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on rate limit error'
		);

		// Test resource exhausted error.
		$error = new WP_Error( 'resource_exhausted', 'Resource exhausted' );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on resource exhausted error'
		);
	}

	/**
	 * Test should_fallback_to_veo_2 method detects availability errors.
	 */
	public function test_should_fallback_on_availability_error() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		// Test model not available error.
		$error = new WP_Error( 'model_unavailable', 'Model is not available' );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on model not available error'
		);

		// Test model not found error.
		$error = new WP_Error( 'not_found', 'Model not found' );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on model not found error'
		);

		// Test model not supported error.
		$error = new WP_Error( 'not_supported', 'Model is not supported' );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on model not supported error'
		);
	}

	/**
	 * Test should_fallback_to_veo_2 method detects HTTP status codes.
	 */
	public function test_should_fallback_on_http_status() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		// Test 429 Too Many Requests.
		$error = new WP_Error( 'http_error', 'Too many requests', array( 'status' => 429 ) );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on 429 status'
		);

		// Test 403 Forbidden.
		$error = new WP_Error( 'http_error', 'Forbidden', array( 'status' => 403 ) );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on 403 status'
		);

		// Test 503 Service Unavailable.
		$error = new WP_Error( 'http_error', 'Service unavailable', array( 'status' => 503 ) );
		$this->assertTrue(
			$method->invoke( $service, $error ),
			'Should fallback on 503 status'
		);
	}

	/**
	 * Test should_fallback_to_veo_2 method does not fallback on non-retryable errors.
	 */
	public function test_should_not_fallback_on_non_retryable_errors() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'should_fallback_to_veo_2' );
		$method->setAccessible( true );

		// Test validation error - should not fallback.
		$error = new WP_Error( 'invalid_prompt', 'Invalid prompt provided' );
		$this->assertFalse(
			$method->invoke( $service, $error ),
			'Should not fallback on validation errors'
		);

		// Test authentication error - should not fallback.
		$error = new WP_Error( 'auth_error', 'Authentication failed' );
		$this->assertFalse(
			$method->invoke( $service, $error ),
			'Should not fallback on authentication errors'
		);

		// Test 400 Bad Request - should not fallback.
		$error = new WP_Error( 'bad_request', 'Bad request', array( 'status' => 400 ) );
		$this->assertFalse(
			$method->invoke( $service, $error ),
			'Should not fallback on 400 status'
		);
	}

	/**
	 * Test build_generation_payload enforces Veo 2 minimum duration.
	 */
	public function test_veo_2_minimum_duration_enforcement() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Test that duration below 5 seconds is adjusted for Veo 2.
		$args = array(
			'prompt'   => 'Test video',
			'duration' => 4, // Below Veo 2 minimum.
		);

		$veo_2_constant = ( new ReflectionClass( $service ) )->getConstant( 'VEO_2_MODEL' );
		$payload = $method->invoke( $service, $args, $veo_2_constant );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'parameters', $payload );
		$this->assertArrayHasKey( 'durationSeconds', $payload['parameters'] );
		$this->assertEquals(
			5,
			$payload['parameters']['durationSeconds'],
			'Veo 2 should enforce minimum 5 second duration'
		);
	}

	/**
	 * Test build_generation_payload downgrades 1080p to 720p for Veo 2.
	 */
	public function test_veo_2_resolution_downgrade() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Test that 1080p is downgraded to 720p for Veo 2.
		$args = array(
			'prompt'     => 'Test video',
			'resolution' => '1080p',
			'duration'   => 8,
		);

		$veo_2_constant = ( new ReflectionClass( $service ) )->getConstant( 'VEO_2_MODEL' );
		$payload = $method->invoke( $service, $args, $veo_2_constant );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'parameters', $payload );
		$this->assertArrayHasKey( 'resolution', $payload['parameters'] );
		$this->assertEquals(
			'720p',
			$payload['parameters']['resolution'],
			'Veo 2 should downgrade 1080p to 720p'
		);
	}

	/**
	 * Test build_generation_payload allows 1080p for Veo 3.1.
	 */
	public function test_veo_3_supports_1080p() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Test that 1080p is preserved for Veo 3.1.
		$args = array(
			'prompt'       => 'Test video',
			'resolution'   => '1080p',
			'duration'     => 8,
			'aspect_ratio' => '16:9',
		);

		$veo_3_constant = ( new ReflectionClass( $service ) )->getConstant( 'VEO_MODEL' );
		$payload = $method->invoke( $service, $args, $veo_3_constant );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'parameters', $payload );
		$this->assertArrayHasKey( 'resolution', $payload['parameters'] );
		$this->assertEquals(
			'1080p',
			$payload['parameters']['resolution'],
			'Veo 3.1 should support 1080p'
		);
	}

	/**
	 * Test that model parameter is stored in async metadata.
	 */
	public function test_async_metadata_includes_model() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method = new ReflectionMethod( $service, 'queue_async_polling' );
		$method->setAccessible( true );

		$veo_2_constant = ( new ReflectionClass( $service ) )->getConstant( 'VEO_2_MODEL' );
		
		$operation = array(
			'operation_name' => 'operations/test-123',
			'model_used'     => $veo_2_constant,
		);

		$args = array(
			'prompt'  => 'Test video',
			'user_id' => 1,
		);

		$result = $method->invoke( $service, $operation, $args );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'job_id', $result );

		// Retrieve the metadata from transient.
		$job_id = $result['job_id'];
		$metadata = get_transient( 'wp_mcp_ai_veo_async_' . $job_id );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'model', $metadata );
		$this->assertEquals(
			$veo_2_constant,
			$metadata['model'],
			'Async metadata should include the model used'
		);

		// Clean up transient.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test tool description mentions fallback support.
	 */
	public function test_tool_description_mentions_fallback() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$description = $tool->get_description();

		$this->assertStringContainsString(
			'Veo 3.1',
			$description,
			'Tool description should mention Veo 3.1'
		);

		$this->assertStringContainsString(
			'Veo 2.0',
			$description,
			'Tool description should mention Veo 2.0'
		);

		$this->assertStringContainsString(
			'fallback',
			$description,
			'Tool description should mention fallback functionality'
		);
	}

	/**
	 * Test tool parameters schema includes model parameter.
	 */
	public function test_tool_schema_includes_model_parameter() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'model', $schema['properties'] );

		$model_param = $schema['properties']['model'];
		$this->assertEquals( 'string', $model_param['type'] );
		$this->assertArrayHasKey( 'enum', $model_param );
		$this->assertContains( 'veo-3.1', $model_param['enum'] );
		$this->assertContains( 'veo-2.0', $model_param['enum'] );
	}
}
