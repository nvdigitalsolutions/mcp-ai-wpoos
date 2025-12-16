<?php
/**
 * Tests for Veo 1080p auto-duration adjustment fix.
 *
 * Tests that 1080p resolution automatically adjusts duration to 8 seconds
 * instead of throwing an error when duration is not provided or differs from 8.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo 1080p auto-duration adjustment.
 */
class WP_MCP_AI_Veo_1080p_Auto_Duration_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that 1080p without duration parameter auto-adjusts to 8 seconds.
	 */
	public function test_1080p_without_duration_auto_adjusts_to_8_seconds() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_request = null;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = array(
						'args' => $args,
						'url'  => $url,
					);

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

		// Test with 1080p but no duration parameter.
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 1080p video',
				'resolution' => '1080p',
			)
		);

		$this->assertNotWPError( $result, '1080p without duration should not return error' );
		$this->assertNotNull( $captured_request, 'Request should be captured' );
		
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Duration should have been auto-adjusted to 8 seconds.
		$this->assertEquals(
			8,
			$request_body['parameters']['durationSeconds'],
			'1080p without duration should auto-adjust to 8 seconds'
		);

		// Resolution should be 1080p.
		$this->assertEquals(
			'1080p',
			$request_body['parameters']['resolution'],
			'Resolution should be 1080p'
		);
	}

	/**
	 * Test that 1080p with duration 5 auto-adjusts to 8 seconds.
	 */
	public function test_1080p_with_duration_5_auto_adjusts_to_8_seconds() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_request = null;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = array(
						'args' => $args,
						'url'  => $url,
					);

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

		// Test with 1080p and duration 5.
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 1080p video',
				'resolution' => '1080p',
				'duration'   => 5,
			)
		);

		$this->assertNotWPError( $result, '1080p with duration 5 should not return error' );
		$this->assertNotNull( $captured_request, 'Request should be captured' );
		
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Duration should have been auto-adjusted to 8 seconds.
		$this->assertEquals(
			8,
			$request_body['parameters']['durationSeconds'],
			'1080p with duration 5 should auto-adjust to 8 seconds'
		);
	}

	/**
	 * Test that 1080p with duration 8 works correctly (no adjustment needed).
	 */
	public function test_1080p_with_duration_8_works_correctly() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_request = null;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = array(
						'args' => $args,
						'url'  => $url,
					);

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

		// Test with 1080p and duration 8 (correct value).
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 1080p video',
				'resolution' => '1080p',
				'duration'   => 8,
			)
		);

		$this->assertNotWPError( $result, '1080p with duration 8 should not return error' );
		$this->assertNotNull( $captured_request, 'Request should be captured' );
		
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Duration should remain 8 seconds.
		$this->assertEquals(
			8,
			$request_body['parameters']['durationSeconds'],
			'1080p with duration 8 should remain 8 seconds'
		);
	}

	/**
	 * Test that 720p with duration 5 works correctly (no adjustment for 720p).
	 */
	public function test_720p_with_duration_5_works_correctly() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_request = null;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = array(
						'args' => $args,
						'url'  => $url,
					);

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

		// Test with 720p and duration 5.
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 720p video',
				'resolution' => '720p',
				'duration'   => 5,
			)
		);

		$this->assertNotWPError( $result, '720p with duration 5 should not return error' );
		$this->assertNotNull( $captured_request, 'Request should be captured' );
		
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Duration should remain 5 seconds (no adjustment for 720p).
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'720p with duration 5 should remain 5 seconds'
		);

		// Resolution should be 720p.
		$this->assertEquals(
			'720p',
			$request_body['parameters']['resolution'],
			'Resolution should be 720p'
		);
	}

	/**
	 * Test that 1080p with invalid duration (0) auto-adjusts to 8 seconds.
	 */
	public function test_1080p_with_invalid_duration_auto_adjusts_to_8_seconds() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$captured_request = null;

		// Mock HTTP requests.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = array(
						'args' => $args,
						'url'  => $url,
					);

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

		// Test with 1080p and invalid duration 0.
		$result = $service->generate_video(
			array(
				'prompt'     => 'Test 1080p video',
				'resolution' => '1080p',
				'duration'   => 0,
			)
		);

		$this->assertNotWPError( $result, '1080p with duration 0 should not return error' );
		$this->assertNotNull( $captured_request, 'Request should be captured' );
		
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Duration should have been auto-adjusted to 8 seconds.
		$this->assertEquals(
			8,
			$request_body['parameters']['durationSeconds'],
			'1080p with invalid duration 0 should auto-adjust to 8 seconds'
		);
	}

	/**
	 * Test that 1080p with various invalid durations all auto-adjust to 8 seconds.
	 */
	public function test_1080p_with_various_durations_auto_adjusts() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Test various duration values that should all be adjusted to 8 for 1080p.
		$test_durations = array( 4, 5, 6, 7 ); // All valid durations except 8.

		foreach ( $test_durations as $test_duration ) {
			$captured_request = null;

			// Create a new filter callback for each iteration to avoid accumulation.
			$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					$captured_request = array(
						'args' => $args,
						'url'  => $url,
					);

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
			};

			// Mock HTTP requests - filter will be removed after assertion.
			add_filter( 'pre_http_request', $filter_callback, 10, 3 );

			$result = $service->generate_video(
				array(
					'prompt'     => 'Test 1080p video',
					'resolution' => '1080p',
					'duration'   => $test_duration,
				)
			);

			// Remove the specific filter callback after use.
			remove_filter( 'pre_http_request', $filter_callback, 10 );

			$this->assertNotWPError( $result, "1080p with duration {$test_duration} should not return error" );
			$this->assertNotNull( $captured_request, "Request should be captured for duration {$test_duration}" );
			
			$request_body = json_decode( $captured_request['args']['body'], true );

			// Duration should have been auto-adjusted to 8 seconds.
			$this->assertEquals(
				8,
				$request_body['parameters']['durationSeconds'],
				"1080p with duration {$test_duration} should auto-adjust to 8 seconds"
			);
		}
	}
}
