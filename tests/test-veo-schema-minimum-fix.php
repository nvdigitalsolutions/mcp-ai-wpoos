<?php
/**
 * Test that tool schema minimum duration fix prevents API errors.
 *
 * This test verifies the fix for the issue where AI agents could select duration=4
 * from the tool schema, which would fail when falling back to Veo 2.0 (requires min 5s).
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo tool schema minimum duration fix.
 */
class WP_MCP_AI_Veo_Schema_Minimum_Fix_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up API key for tests.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a test user with upload_files capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		wp_delete_user( $this->user_id );
		parent::tearDown();
	}

	/**
	 * Test that tool schema advertises minimum=5 to prevent AI from selecting invalid values.
	 *
	 * This is the primary fix - ensuring AI agents (like OpenAI function calling) never
	 * receive a schema that suggests duration=4 is acceptable, since it would fail with Veo 2.0.
	 */
	public function test_tool_schema_minimum_is_5() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'duration', $schema['properties'] );

		$duration_schema = $schema['properties']['duration'];

		$this->assertEquals(
			5,
			$duration_schema['minimum'],
			'Tool schema should advertise minimum=5 to ensure compatibility with both Veo 3.1 and Veo 2.0'
		);

		$this->assertEquals(
			8,
			$duration_schema['maximum'],
			'Tool schema should advertise maximum=8'
		);

		$this->assertEquals(
			5,
			$duration_schema['default'],
			'Tool schema should have default=5'
		);
	}

	/**
	 * Test that schema description doesn't mislead AI about minimum duration.
	 */
	public function test_schema_description_accurate() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$schema = $tool->get_parameters_schema();

		$duration_description = $schema['properties']['duration']['description'];

		// Should mention 5-8 range.
		$this->assertStringContainsString(
			'5-8',
			$duration_description,
			'Description should mention 5-8 second range'
		);

		// Should not mention 4-8 range anymore.
		$this->assertStringNotContainsString(
			'4-8 for Veo 3.1',
			$duration_description,
			'Description should not mention model-specific 4-8 range that could confuse AI'
		);
	}

	/**
	 * Test that when AI selects minimum value (5), it works for both models.
	 *
	 * Simulates an AI agent reading the schema, seeing minimum=5, and selecting that value.
	 */
	public function test_ai_selected_minimum_works_for_both_models() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'build_generation_payload' );
		$method->setAccessible( true );

		$reflection = new ReflectionClass( $service );
		$veo_3_model = $reflection->getConstant( 'VEO_MODEL' );
		$veo_2_model = $reflection->getConstant( 'VEO_2_MODEL' );

		// AI selects minimum value from schema (5).
		$args = array(
			'prompt'   => 'Test video',
			'duration' => 5,
		);

		// Test with Veo 3.1.
		$payload_veo3 = $method->invoke( $service, $args, $veo_3_model );
		$this->assertEquals(
			5,
			$payload_veo3['parameters']['durationSeconds'],
			'Duration 5 should work with Veo 3.1'
		);

		// Test with Veo 2.0.
		$payload_veo2 = $method->invoke( $service, $args, $veo_2_model );
		$this->assertEquals(
			5,
			$payload_veo2['parameters']['durationSeconds'],
			'Duration 5 should work with Veo 2.0'
		);
	}

	/**
	 * Test that tool description accurately reflects the fix.
	 */
	public function test_tool_description_reflects_fix() {
		$tool        = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$description = $tool->get_description();

		// Should mention 5-8 second videos.
		$this->assertStringContainsString(
			'5-8 second videos',
			$description,
			'Tool description should mention 5-8 second videos'
		);

		// Should still mention fallback capability.
		$this->assertStringContainsString(
			'fallback',
			$description,
			'Description should mention fallback capability'
		);

		// Should mention both models.
		$this->assertStringContainsString( 'Veo 3.1', $description );
		$this->assertStringContainsString( 'Veo 2.0', $description );
	}

	/**
	 * Test the full scenario: AI calls tool with schema-compliant value.
	 *
	 * This simulates the real-world usage where:
	 * 1. OpenAI reads the tool schema
	 * 2. Selects a value based on schema (minimum=5)
	 * 3. Tool executes successfully
	 */
	public function test_full_ai_workflow_scenario() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Get schema as AI would see it.
		$schema = $tool->get_parameters_schema();

		// AI selects minimum value from schema.
		$ai_selected_duration = $schema['properties']['duration']['minimum'];

		$this->assertEquals( 5, $ai_selected_duration, 'AI should see minimum=5 in schema' );

		// Now execute tool with AI-selected value.
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = $args;

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op',
								'done' => false,
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$result = $tool->execute(
			array(
				'prompt'   => 'Test with AI-selected minimum duration',
				'duration' => $ai_selected_duration,
			),
			array(
				'user_id'      => $this->user_id,
				'agentic_loop' => false,
			)
		);

		// Verify request was made with correct duration.
		$this->assertNotNull( $captured_request, 'Request should be captured' );

		$request_body = json_decode( $captured_request['body'], true );
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'AI-selected minimum duration (5) should be sent to API'
		);

		// Should not be an error (returns async response).
		$this->assertFalse(
			is_wp_error( $result ),
			'Tool should execute successfully with schema-compliant duration'
		);
	}

	/**
	 * Test backward compatibility: Service still handles duration 4 for Veo 3.1.
	 *
	 * Even though the tool schema now has minimum=5, the service should still
	 * handle duration=4 correctly for Veo 3.1 (in case it's called programmatically).
	 */
	public function test_service_still_handles_duration_4_for_veo_3() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'build_generation_payload' );
		$method->setAccessible( true );

		$reflection  = new ReflectionClass( $service );
		$veo_3_model = $reflection->getConstant( 'VEO_MODEL' );

		$args = array(
			'prompt'   => 'Test video',
			'duration' => 4,
		);

		$payload = $method->invoke( $service, $args, $veo_3_model );

		$this->assertEquals(
			4,
			$payload['parameters']['durationSeconds'],
			'Service should still handle duration=4 for Veo 3.1 (backward compatibility)'
		);
	}

	/**
	 * Test that service adjusts duration 4 to 5 for Veo 2.0.
	 *
	 * This verifies the existing safety net in the service layer.
	 */
	public function test_service_adjusts_duration_4_to_5_for_veo_2() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$method  = new ReflectionMethod( $service, 'build_generation_payload' );
		$method->setAccessible( true );

		$reflection  = new ReflectionClass( $service );
		$veo_2_model = $reflection->getConstant( 'VEO_2_MODEL' );

		$args = array(
			'prompt'   => 'Test video',
			'duration' => 4,
		);

		$payload = $method->invoke( $service, $args, $veo_2_model );

		$this->assertEquals(
			5,
			$payload['parameters']['durationSeconds'],
			'Service should adjust duration=4 to 5 for Veo 2.0 (minimum 5s requirement)'
		);
	}
}
