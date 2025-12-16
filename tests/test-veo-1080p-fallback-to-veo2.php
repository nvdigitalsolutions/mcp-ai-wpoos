<?php
/**
 * Tests for Veo 3.1 to Veo 2.0 fallback with 1080p resolution downgrade.
 *
 * Tests that when 1080p is requested with Veo 3.1 and it fails due to usage limits,
 * the system properly falls back to Veo 2.0 and downgrades resolution to 720p.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo 3.1 to Veo 2.0 fallback with 1080p.
 */
class WP_MCP_AI_Veo_1080p_Fallback_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that 1080p request falls back to Veo 2.0 with 720p when Veo 3.1 hits quota limits.
	 */
	public function test_1080p_falls_back_to_veo_2_with_720p_on_quota_error() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_requests = array();

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_requests ) {
				// Veo 3.1 request - simulate quota exceeded error.
				if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
					$captured_requests[] = array(
						'model' => 'veo-3.1',
						'args'  => $args,
						'url'   => $url,
					);

					return array(
						'response' => array(
							'code'    => 429,
							'message' => 'Too Many Requests',
						),
						'body'     => wp_json_encode(
							array(
								'error' => array(
									'code'    => 429,
									'message' => 'Quota exceeded for veo-3.1-generate-preview',
									'status'  => 'RESOURCE_EXHAUSTED',
								),
							)
						),
					);
				}

				// Veo 2.0 fallback request - simulate success.
				if ( strpos( $url, 'veo-2.0-generate-001' ) !== false && strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_requests[] = array(
						'model' => 'veo-2.0',
						'args'  => $args,
						'url'   => $url,
					);

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-veo-2-op',
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

		// Request 1080p video - should auto-adjust duration to 8s, try Veo 3.1, then fallback to Veo 2.0 with 720p.
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 1080p video with fallback',
				'resolution' => '1080p',
				'duration'   => 5, // Will be auto-adjusted to 8s for 1080p.
			)
		);

		// Verify we got two requests: Veo 3.1 then Veo 2.0.
		$this->assertCount( 2, $captured_requests, 'Should have attempted Veo 3.1 then Veo 2.0' );

		// Verify first request was to Veo 3.1 with 1080p.
		$veo_3_request = $captured_requests[0];
		$this->assertEquals( 'veo-3.1', $veo_3_request['model'], 'First request should be to Veo 3.1' );
		$veo_3_body = json_decode( $veo_3_request['args']['body'], true );
		$this->assertEquals( '1080p', $veo_3_body['parameters']['resolution'], 'Veo 3.1 request should have 1080p resolution' );
		$this->assertEquals( 8, $veo_3_body['parameters']['durationSeconds'], 'Veo 3.1 request should have 8s duration (auto-adjusted)' );

		// Verify second request was to Veo 2.0 with 720p (downgraded).
		$veo_2_request = $captured_requests[1];
		$this->assertEquals( 'veo-2.0', $veo_2_request['model'], 'Second request should be to Veo 2.0' );
		$veo_2_body = json_decode( $veo_2_request['args']['body'], true );
		// Veo 2.0 doesn't support resolution parameter, so it should not be present or be null.
		$this->assertArrayNotHasKey( 'resolution', $veo_2_body['parameters'], 'Veo 2.0 request should not have resolution parameter' );
		$this->assertEquals( 8, $veo_2_body['parameters']['durationSeconds'], 'Veo 2.0 request should maintain 8s duration' );

		// Verify result indicates async operation and fallback was used.
		$this->assertNotWPError( $result, 'Fallback to Veo 2.0 should succeed' );
		$this->assertTrue( $result['async'], 'Should return async result' );
	}

	/**
	 * Test that 1080p with duration 4 falls back properly with both resolution and duration adjustments.
	 */
	public function test_1080p_duration_4_falls_back_with_double_adjustment() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_requests = array();

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_requests ) {
				// Veo 3.1 request - simulate rate limit error.
				if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
					$captured_requests[] = array(
						'model' => 'veo-3.1',
						'args'  => $args,
						'url'   => $url,
					);

					return array(
						'response' => array(
							'code'    => 429,
							'message' => 'Too Many Requests',
						),
						'body'     => wp_json_encode(
							array(
								'error' => array(
									'code'    => 429,
									'message' => 'Rate limit exceeded',
								),
							)
						),
					);
				}

				// Veo 2.0 fallback request - simulate success.
				if ( strpos( $url, 'veo-2.0-generate-001' ) !== false ) {
					$captured_requests[] = array(
						'model' => 'veo-2.0',
						'args'  => $args,
						'url'   => $url,
					);

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-veo-2-op',
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

		// Request 1080p with duration 4 (only valid for Veo 3.1).
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 1080p video with duration 4',
				'resolution' => '1080p',
				'duration'   => 4,
			)
		);

		$this->assertCount( 2, $captured_requests, 'Should have attempted Veo 3.1 then Veo 2.0' );

		// Verify Veo 3.1 request had duration adjusted to 8s for 1080p.
		$veo_3_body = json_decode( $captured_requests[0]['args']['body'], true );
		$this->assertEquals( 8, $veo_3_body['parameters']['durationSeconds'], 'Veo 3.1 request should have duration adjusted from 4 to 8 for 1080p' );

		// Verify Veo 2.0 request kept 8s (no need to adjust to 5 since 8 >= 5).
		$veo_2_body = json_decode( $captured_requests[1]['args']['body'], true );
		$this->assertEquals( 8, $veo_2_body['parameters']['durationSeconds'], 'Veo 2.0 request should maintain 8s duration' );
	}

	/**
	 * Test that 720p requests don't trigger unnecessary fallback adjustments.
	 */
	public function test_720p_fallback_no_resolution_adjustment() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_requests = array();

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_requests ) {
				// Veo 3.1 request - simulate unavailable.
				if ( strpos( $url, 'veo-3.1-generate-preview' ) !== false ) {
					$captured_requests[] = array(
						'model' => 'veo-3.1',
						'args'  => $args,
						'url'   => $url,
					);

					return array(
						'response' => array(
							'code'    => 503,
							'message' => 'Service Unavailable',
						),
						'body'     => wp_json_encode(
							array(
								'error' => array(
									'message' => 'Model veo-3.1-generate-preview is not available',
								),
							)
						),
					);
				}

				// Veo 2.0 fallback request - simulate success.
				if ( strpos( $url, 'veo-2.0-generate-001' ) !== false ) {
					$captured_requests[] = array(
						'model' => 'veo-2.0',
						'args'  => $args,
						'url'   => $url,
					);

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-veo-2-op',
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

		// Request 720p video (no auto-adjustment needed).
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 720p video with fallback',
				'resolution' => '720p',
				'duration'   => 6,
			)
		);

		$this->assertCount( 2, $captured_requests, 'Should have attempted Veo 3.1 then Veo 2.0' );

		// Both requests should have 720p and 6s duration (no adjustments needed).
		$veo_3_body = json_decode( $captured_requests[0]['args']['body'], true );
		$this->assertEquals( '720p', $veo_3_body['parameters']['resolution'], 'Veo 3.1 request should have 720p' );
		$this->assertEquals( 6, $veo_3_body['parameters']['durationSeconds'], 'Veo 3.1 request should have 6s duration' );

		$veo_2_body = json_decode( $captured_requests[1]['args']['body'], true );
		// Veo 2.0 doesn't include resolution parameter.
		$this->assertEquals( 6, $veo_2_body['parameters']['durationSeconds'], 'Veo 2.0 request should maintain 6s duration' );
	}
}
