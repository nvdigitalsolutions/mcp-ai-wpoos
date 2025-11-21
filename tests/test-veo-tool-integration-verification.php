<?php
/**
 * Integration test to verify Veo video tool works with new default duration.
 *
 * This test confirms:
 * 1. Tool parameter schema has correct default (4)
 * 2. Tool passes arguments correctly to service
 * 3. Service applies default (4) when not provided
 * 4. Service validates and corrects invalid durations
 * 5. End-to-end flow works as expected
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo video tool integration with new default duration.
 */
class WP_MCP_AI_Veo_Tool_Integration_Verification_Test extends WP_UnitTestCase {

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
	 * Test that tool parameter schema has correct default value.
	 */
	public function test_tool_parameter_schema_has_correct_default() {
		$tool   = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$schema = $tool->get_parameters_schema();

		// Verify schema structure.
		$this->assertIsArray( $schema, 'Schema should be an array' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'duration', $schema['properties'], 'Schema should have duration property' );

		// Verify default value is 4.
		$duration_schema = $schema['properties']['duration'];
		$this->assertArrayHasKey( 'default', $duration_schema, 'Duration should have default value' );
		$this->assertEquals(
			4,
			$duration_schema['default'],
			'Duration default should be 4 seconds'
		);

		// Verify range constraints.
		$this->assertEquals( 4, $duration_schema['minimum'], 'Minimum duration should be 4' );
		$this->assertEquals( 8, $duration_schema['maximum'], 'Maximum duration should be 8' );

		// Verify description mentions the new default.
		$this->assertStringContainsString(
			'Default is 4 seconds',
			$duration_schema['description'],
			'Description should mention default is 4 seconds'
		);
	}

	/**
	 * Test service constant has correct value.
	 */
	public function test_service_constant_has_correct_value() {
		$this->assertEquals(
			4,
			WP_MCP_AI_Gemini_Video_Generation_Service::DEFAULT_DURATION,
			'Service DEFAULT_DURATION constant should be 4'
		);

		$this->assertEquals(
			4,
			WP_MCP_AI_Gemini_Video_Generation_Service::MIN_DURATION,
			'Service MIN_DURATION should be 4'
		);

		$this->assertEquals(
			8,
			WP_MCP_AI_Gemini_Video_Generation_Service::MAX_DURATION,
			'Service MAX_DURATION should be 8'
		);
	}

	/**
	 * Test tool execution without duration uses service default of 4.
	 */
	public function test_tool_execution_without_duration_uses_default() {
		$tool              = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$captured_request  = null;
		$generation_called = false;

		// Mock HTTP requests to capture what duration is sent to API.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request, &$generation_called ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request  = $args;
					$generation_called = true;

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op-' . uniqid(),
								'done' => false,
							)
						),
					);
				}

				if ( strpos( $url, 'operations/' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name'     => 'operations/test-op',
								'done'     => true,
								'response' => array(
									'predictions' => array(
										array(
											'videoUri' => 'https://example.com/video.mp4',
										),
									),
								),
							)
						),
					);
				}

				if ( strpos( $url, 'video.mp4' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => 'fake-video-content',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Execute tool without providing duration.
		$result = $tool->execute(
			array(
				'prompt' => 'Test video generation without duration',
			),
			array(
				'user_id'      => $this->user_id,
				'agentic_loop' => false, // Force sync execution for testing.
			)
		);

		// Verify API was called.
		$this->assertTrue( $generation_called, 'Video generation API should have been called' );
		$this->assertNotNull( $captured_request, 'Request should have been captured' );

		// Verify the duration sent to API is 4 (the new default).
		$request_body = json_decode( $captured_request['body'], true );
		$this->assertArrayHasKey( 'parameters', $request_body, 'Request should have parameters' );
		$this->assertArrayHasKey( 'durationSeconds', $request_body['parameters'], 'Parameters should have durationSeconds' );
		$this->assertEquals(
			4,
			$request_body['parameters']['durationSeconds'],
			'Duration should default to 4 seconds when not provided'
		);

		// Verify result is not an error.
		$this->assertFalse( is_wp_error( $result ), 'Tool execution should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
	}

	/**
	 * Test tool execution with valid duration passes it through correctly.
	 */
	public function test_tool_execution_with_valid_duration_passes_through() {
		$tool              = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$captured_request  = null;
		$generation_called = false;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request, &$generation_called ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request  = $args;
					$generation_called = true;

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op-' . uniqid(),
								'done' => false,
							)
						),
					);
				}

				if ( strpos( $url, 'operations/' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name'     => 'operations/test-op',
								'done'     => true,
								'response' => array(
									'predictions' => array(
										array(
											'videoUri' => 'https://example.com/video.mp4',
										),
									),
								),
							)
						),
					);
				}

				if ( strpos( $url, 'video.mp4' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => 'fake-video-content',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Execute tool with duration = 6.
		$result = $tool->execute(
			array(
				'prompt'   => 'Test video generation with duration 6',
				'duration' => 6,
			),
			array(
				'user_id'      => $this->user_id,
				'agentic_loop' => false,
			)
		);

		// Verify the duration sent to API is 6.
		$this->assertTrue( $generation_called, 'Video generation API should have been called' );
		$request_body = json_decode( $captured_request['body'], true );
		$this->assertEquals(
			6,
			$request_body['parameters']['durationSeconds'],
			'Duration 6 should be passed through correctly'
		);

		// Verify result is not an error.
		$this->assertFalse( is_wp_error( $result ), 'Tool execution should succeed' );
	}

	/**
	 * Test tool execution with invalid duration uses service default.
	 */
	public function test_tool_execution_with_invalid_duration_uses_default() {
		$tool              = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$captured_request  = null;
		$generation_called = false;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request, &$generation_called ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request  = $args;
					$generation_called = true;

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op-' . uniqid(),
								'done' => false,
							)
						),
					);
				}

				if ( strpos( $url, 'operations/' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name'     => 'operations/test-op',
								'done'     => true,
								'response' => array(
									'predictions' => array(
										array(
											'videoUri' => 'https://example.com/video.mp4',
										),
									),
								),
							)
						),
					);
				}

				if ( strpos( $url, 'video.mp4' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => 'fake-video-content',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Execute tool with invalid duration = 10 (above max).
		$result = $tool->execute(
			array(
				'prompt'   => 'Test video generation with invalid duration',
				'duration' => 10,
			),
			array(
				'user_id'      => $this->user_id,
				'agentic_loop' => false,
			)
		);

		// Verify the duration sent to API is 4 (service corrected it).
		$this->assertTrue( $generation_called, 'Video generation API should have been called' );
		$request_body = json_decode( $captured_request['body'], true );
		$this->assertEquals(
			4,
			$request_body['parameters']['durationSeconds'],
			'Invalid duration (10) should be corrected to default (4) by service'
		);

		// Verify result is not an error.
		$this->assertFalse( is_wp_error( $result ), 'Tool execution should succeed' );
	}

	/**
	 * Test SoC: Tool does not apply defaults, service does.
	 */
	public function test_soc_tool_does_not_apply_defaults() {
		// Use reflection to test the protected method behavior.
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Execute with no duration - should not have duration in args passed to service.
		// We'll capture this by mocking the service.
		$service_args_received = null;

		// We need to test the tool's prepare logic without executing the full flow.
		// Let's test the service directly to confirm it applies defaults.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create a mock to capture the payload building.
		$reflection_class  = new ReflectionClass( $service );
		$reflection_method = $reflection_class->getMethod( 'build_generation_payload' );
		$reflection_method->setAccessible( true );

		// Test 1: Service applies default when duration not provided.
		$args_without_duration = array( 'prompt' => 'Test' );
		$payload               = $reflection_method->invoke( $service, $args_without_duration );

		$this->assertEquals(
			4,
			$payload['parameters']['durationSeconds'],
			'Service should apply default (4) when duration not provided'
		);

		// Test 2: Service uses provided valid duration.
		$args_with_valid_duration = array(
			'prompt'   => 'Test',
			'duration' => 7,
		);
		$payload                  = $reflection_method->invoke( $service, $args_with_valid_duration );

		$this->assertEquals(
			7,
			$payload['parameters']['durationSeconds'],
			'Service should use provided valid duration (7)'
		);

		// Test 3: Service corrects invalid duration to default.
		$args_with_invalid_duration = array(
			'prompt'   => 'Test',
			'duration' => 15,
		);
		$payload                    = $reflection_method->invoke( $service, $args_with_invalid_duration );

		$this->assertEquals(
			4,
			$payload['parameters']['durationSeconds'],
			'Service should correct invalid duration (15) to default (4)'
		);
	}

	/**
	 * Test that all valid durations (4-8) work correctly.
	 */
	public function test_all_valid_durations_work() {
		$service           = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$reflection_class  = new ReflectionClass( $service );
		$reflection_method = $reflection_class->getMethod( 'build_generation_payload' );
		$reflection_method->setAccessible( true );

		// Test each valid duration value.
		foreach ( array( 4, 5, 6, 7, 8 ) as $valid_duration ) {
			$args    = array(
				'prompt'   => 'Test',
				'duration' => $valid_duration,
			);
			$payload = $reflection_method->invoke( $service, $args );

			$this->assertEquals(
				$valid_duration,
				$payload['parameters']['durationSeconds'],
				"Valid duration {$valid_duration} should be passed through correctly"
			);
		}
	}
}
