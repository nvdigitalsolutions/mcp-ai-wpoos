<?php
/**
 * Comprehensive REST and Service Layer Tests for Veo Video Tool
 *
 * This test file verifies:
 * 1. REST API endpoint handles tool execution correctly
 * 2. Tool orchestrator processes video generation requests
 * 3. Service layer validation works end-to-end
 * 4. Default duration (5 seconds) is applied correctly through the entire stack
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for REST and service layer integration.
 */
class WP_MCP_AI_Veo_REST_Service_Integration_Test extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up REST server.
		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

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

		// Create a test assistant with video tool enabled.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Video Assistant',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);

		// Configure assistant with video generation tool. Assistant config is
		// stored in individual post meta keys (see WP_MCP_AI_Assistant_CPT).
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_tools', array( 'generate_veo_video' ) );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'gemini-2.0-flash-exp' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'gemini' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( 'wp_mcp_ai_settings' );
		wp_delete_post( $this->assistant_id, true );
		wp_delete_user( $this->user_id );

		parent::tearDown();
	}

	/**
	 * Test REST endpoint with no duration parameter uses service default of 5.
	 */
	public function test_rest_endpoint_no_duration_uses_default() {
		wp_set_current_user( $this->user_id );

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

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'generate_veo_video' );
		$request->set_param(
			'arguments',
			array(
				'prompt' => 'Test video via REST endpoint without duration',
				// No duration parameter - should use service default of 5.
			)
		);

		// Execute request.
		$response = $this->server->dispatch( $request );

		// Verify response. The Veo tool is background-only, so the endpoint
		// queues it as a pending job instead of running inline.
		$this->assertNotInstanceOf( 'WP_Error', $response, 'REST request should not return error' );
		$this->assertEquals( 200, $response->get_status(), 'REST request should return 200 status' );

		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'], 'Background-only tool should queue as pending' );
		$this->assertNotEmpty( $data['job_id'], 'A job ID should be returned' );

		// Run the queued job inline, as the async executor does on its cron tick.
		wp_mcp_ai_get_async_tool_executor()->execute_async_tool( $data['job_id'] );

		// Verify API was called with correct duration.
		$this->assertTrue( $generation_called, 'Video generation API should have been called' );
		$this->assertNotNull( $captured_request, 'Request should have been captured' );

		$request_body = json_decode( $captured_request['body'], true );
		$this->assertArrayHasKey( 'parameters', $request_body, 'Request should have parameters' );
		$this->assertArrayHasKey( 'durationSeconds', $request_body['parameters'], 'Parameters should have durationSeconds' );
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'Duration should default to 5 seconds when not provided via REST'
		);
	}

	/**
	 * Test REST endpoint with valid duration passes it through correctly.
	 */
	public function test_rest_endpoint_with_valid_duration() {
		wp_set_current_user( $this->user_id );

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

		// Create REST request with duration=7.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'generate_veo_video' );
		$request->set_param(
			'arguments',
			array(
				'prompt'   => 'Test video via REST endpoint with duration 7',
				'duration' => 7,
			)
		);

		// Execute request.
		$response = $this->server->dispatch( $request );

		// Verify response. The Veo tool is background-only, so the endpoint
		// queues it as a pending job instead of running inline.
		$this->assertEquals( 200, $response->get_status(), 'REST request should return 200 status' );

		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'], 'Background-only tool should queue as pending' );
		$this->assertNotEmpty( $data['job_id'], 'A job ID should be returned' );

		// Run the queued job inline, as the async executor does on its cron tick.
		wp_mcp_ai_get_async_tool_executor()->execute_async_tool( $data['job_id'] );

		// Verify API was called with correct duration.
		$this->assertTrue( $generation_called, 'Video generation API should have been called' );
		$request_body = json_decode( $captured_request['body'], true );
		$this->assertEquals(
			7,
			$request_body['parameters']['durationSeconds'],
			'Duration 7 should be passed through correctly via REST'
		);
	}

	/**
	 * Test REST endpoint with invalid duration is rejected by the validated
	 * tool before the generation API is called.
	 */
	public function test_rest_endpoint_with_invalid_duration() {
		wp_set_current_user( $this->user_id );

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

		// Create REST request with invalid duration=15.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'generate_veo_video' );
		$request->set_param(
			'arguments',
			array(
				'prompt'   => 'Test video via REST endpoint with invalid duration',
				'duration' => 15,
			)
		);

		// Execute request.
		$response = $this->server->dispatch( $request );

		// Verify response. The Veo tool is background-only, so the endpoint
		// queues it as a pending job instead of running inline.
		$this->assertEquals( 200, $response->get_status(), 'REST request should return 200 status' );

		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'], 'Background-only tool should queue as pending' );
		$this->assertNotEmpty( $data['job_id'], 'A job ID should be returned' );

		// Run the queued job inline, as the async executor does on its cron tick.
		wp_mcp_ai_get_async_tool_executor()->execute_async_tool( $data['job_id'] );

		// The validated tool rejects out-of-range durations before any API
		// call: the job should fail validation and the API must not be hit.
		$result = wp_mcp_ai_get_async_tool_executor()->get_result( $data['job_id'] );
		$this->assertSame( 'failed', $result['status'], 'Out-of-range duration should fail validation' );
		$this->assertSame( 'Validation failed', $result['error'], 'Job error should describe the validation failure' );
		$this->assertSame(
			'duration',
			$result['error_data']['errors'][0]['field'],
			'Validation error should reference the duration field'
		);
		$this->assertFalse( $generation_called, 'Generation API should not be called for an invalid duration' );
	}

	/**
	 * Test service layer validation works independently of REST.
	 */
	public function test_service_layer_validation_independent() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to test protected method.
		$reflection_class  = new ReflectionClass( $service );
		$reflection_method = $reflection_class->getMethod( 'build_generation_payload' );
		$reflection_method->setAccessible( true );

		// Test various scenarios.
		$test_cases = array(
			array(
				'name'     => 'No duration',
				'args'     => array( 'prompt' => 'Test' ),
				'expected' => 5,
			),
			array(
				'name'     => 'Valid duration 4',
				'args'     => array(
					'prompt'   => 'Test',
					'duration' => 4,
				),
				'expected' => 4,
			),
			array(
				'name'     => 'Valid duration 5',
				'args'     => array(
					'prompt'   => 'Test',
					'duration' => 5,
				),
				'expected' => 5,
			),
			array(
				'name'     => 'Valid duration 8',
				'args'     => array(
					'prompt'   => 'Test',
					'duration' => 8,
				),
				'expected' => 8,
			),
			array(
				'name'     => 'Invalid duration 0',
				'args'     => array(
					'prompt'   => 'Test',
					'duration' => 0,
				),
				'expected' => 4,
			),
			array(
				'name'     => 'Invalid duration 3',
				'args'     => array(
					'prompt'   => 'Test',
					'duration' => 3,
				),
				'expected' => 4,
			),
			array(
				'name'     => 'Invalid duration 9',
				'args'     => array(
					'prompt'   => 'Test',
					'duration' => 9,
				),
				'expected' => 8,
			),
			array(
				'name'     => '1080p overrides to 8',
				'args'     => array(
					'prompt'     => 'Test',
					'duration'   => 5,
					'resolution' => '1080p',
				),
				'expected' => 8,
			),
		);

		foreach ( $test_cases as $test_case ) {
			$payload = $reflection_method->invoke( $service, $test_case['args'] );

			$this->assertEquals(
				$test_case['expected'],
				$payload['parameters']['durationSeconds'],
				"Service validation failed for: {$test_case['name']}"
			);

			// Verify it's an integer.
			$this->assertIsInt(
				$payload['parameters']['durationSeconds'],
				"Duration should be integer for: {$test_case['name']}"
			);
		}
	}

	/**
	 * Test JSON encoding preserves integer type.
	 */
	public function test_json_encoding_preserves_integer_type() {
		$service           = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$reflection_class  = new ReflectionClass( $service );
		$reflection_method = $reflection_class->getMethod( 'build_generation_payload' );
		$reflection_method->setAccessible( true );

		$args    = array(
			'prompt'   => 'Test',
			'duration' => 4,
		);
		$payload = $reflection_method->invoke( $service, $args );

		// Encode to JSON and decode back.
		$json    = wp_json_encode( $payload );
		$decoded = json_decode( $json, true );

		// Verify duration is still an integer after JSON round-trip.
		$this->assertIsInt(
			$decoded['parameters']['durationSeconds'],
			'Duration should remain integer after JSON encoding/decoding'
		);

		$this->assertEquals(
			4,
			$decoded['parameters']['durationSeconds'],
			'Duration value should be preserved after JSON encoding/decoding'
		);
	}

	/**
	 * Test that tool execution context is properly passed through.
	 *
	 * The Veo tool is background-only, so the REST endpoint queues it and
	 * the execution context (user and assistant) must be preserved in the
	 * queued job metadata for the async executor.
	 */
	public function test_tool_execution_context_preserved() {
		wp_set_current_user( $this->user_id );

		$generation_called = false;

		// Mock HTTP requests to prevent actual API calls.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$generation_called ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$generation_called = true;

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

		// Execute tool through the REST endpoint with specific context.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'generate_veo_video' );
		$request->set_param(
			'arguments',
			array( 'prompt' => 'Test context preservation' )
		);

		$response = $this->server->dispatch( $request );
		$this->assertNotInstanceOf( 'WP_Error', $response, 'REST request should not return error' );
		$this->assertEquals( 200, $response->get_status(), 'REST request should return 200 status' );

		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'], 'Background-only tool should queue as pending' );
		$this->assertNotEmpty( $data['job_id'], 'A job ID should be returned' );

		// Verify the queued job metadata preserves the execution context.
		$metadata = get_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $data['job_id'] );
		$this->assertIsArray( $metadata, 'Job metadata should be stored' );
		$this->assertIsArray( $metadata['context'], 'Job metadata should include context' );
		$this->assertEquals( $this->user_id, (int) $metadata['context']['user_id'], 'User ID should be preserved in context' );
		$this->assertEquals( $this->assistant_id, (int) $metadata['context']['assistant_id'], 'Assistant ID should be preserved in context' );

		// Run the queued job inline to prove the preserved context drives
		// execution (and leave no pending job behind).
		wp_mcp_ai_get_async_tool_executor()->execute_async_tool( $data['job_id'] );
		$this->assertTrue( $generation_called, 'Queued job should execute with the preserved context' );
	}
}
