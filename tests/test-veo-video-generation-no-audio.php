<?php
/**
 * Tests for Veo video generation service - specifically ensuring generateAudio is not sent.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo video generation audio parameter fix.
 */
class WP_MCP_AI_Veo_Video_Generation_No_Audio_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that generateAudio parameter is not included in API request payload.
	 *
	 * This test verifies the fix for the issue where Veo 3.1 API was rejecting
	 * requests containing the unsupported 'generateAudio' parameter.
	 */
	public function test_generate_audio_parameter_not_sent_to_api() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create service instance.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_request = null;

		// Mock the HTTP request to capture what's being sent.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				// Only intercept Veo API calls.
				if ( strpos( $url, 'predictLongRunning' ) === false ) {
					return $preempt;
				}

				$captured_request = array(
					'args' => $args,
					'url'  => $url,
				);

				// Return a mock response with operation name.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'name' => 'operations/test-operation-123',
							'done' => false,
						)
					),
				);
			},
			10,
			3
		);

		// Mock the polling response to avoid actually waiting.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only intercept polling calls.
				if ( strpos( $url, 'operations/test-operation-123' ) === false ) {
					return $preempt;
				}

				// Return completed operation with video URI.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'name'     => 'operations/test-operation-123',
							'done'     => true,
							'response' => array(
								'predictions' => array(
									array(
										'videoUri' => 'https://example.com/test-video.mp4',
									),
								),
							),
						)
					),
				);
			},
			11,
			3
		);

		// Mock video download.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only intercept video download.
				if ( strpos( $url, 'test-video.mp4' ) === false ) {
					return $preempt;
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => 'fake-video-data',
				);
			},
			12,
			3
		);

		// Attempt to generate video (will be mocked).
		$result = $service->generate_video(
			array(
				'prompt'       => 'A cat playing piano',
				'duration'     => 5,
				'aspect_ratio' => '16:9',
				'resolution'   => '720p',
			)
		);

		// Verify request was captured.
		$this->assertNotNull( $captured_request, 'HTTP request should have been captured' );

		// Decode the request body.
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Verify the request structure.
		$this->assertIsArray( $request_body, 'Request body should be a valid JSON array' );
		$this->assertArrayHasKey( 'parameters', $request_body, 'Request should have parameters' );

		// The critical assertion: generateAudio should NOT be in the parameters.
		$this->assertArrayNotHasKey(
			'generateAudio',
			$request_body['parameters'],
			'generateAudio parameter should NOT be sent to the API as it is not supported by Veo 3.1'
		);

		// Verify expected parameters ARE present.
		$this->assertArrayHasKey( 'model', $request_body['parameters'], 'model parameter should be present' );
		$this->assertArrayHasKey( 'durationSeconds', $request_body['parameters'], 'durationSeconds parameter should be present' );
		$this->assertArrayHasKey( 'aspectRatio', $request_body['parameters'], 'aspectRatio parameter should be present' );
		$this->assertArrayHasKey( 'resolution', $request_body['parameters'], 'resolution parameter should be present' );

		// Verify result is successful.
		$this->assertFalse( is_wp_error( $result ), 'Video generation should succeed' );
	}

	/**
	 * Test that the service can be called without any audio-related parameters.
	 */
	public function test_service_works_without_audio_parameter() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Mock responses.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
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

				if ( strpos( $url, 'operations/test-op' ) !== false ) {
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
						'body'     => 'video-content',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Call with minimal parameters - no audio parameter at all.
		$result = $service->generate_video(
			array(
				'prompt' => 'Test video generation',
			)
		);

		// Should succeed without errors.
		$this->assertFalse( is_wp_error( $result ), 'Video generation should work without audio parameter' );
		$this->assertArrayHasKey( 'video_data', $result, 'Result should contain video data' );
		$this->assertEquals( 'video-content', $result['video_data'], 'Video data should match mock response' );
	}
}
