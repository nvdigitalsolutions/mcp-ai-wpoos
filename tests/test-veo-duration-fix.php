<?php
/**
 * Tests for Veo video generation duration validation fix.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo video generation duration validation.
 */
class WP_MCP_AI_Veo_Duration_Validation_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that duration value 0 (from absint of invalid input) defaults to model minimum.
	 */
	public function test_duration_zero_defaults_to_model_minimum() {
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

		// Test with duration 0 (simulating absint of invalid input).
		$result = $service->generate_video(
			array(
				'prompt'   => 'Test video',
				'duration' => 0,
			)
		);

		$this->assertNotNull( $captured_request, 'Request should be captured' );
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Duration 0 should have been adjusted to model minimum (4 for Veo 3.1).
		$this->assertEquals(
			4,
			$request_body['parameters']['durationSeconds'],
			'Duration 0 should be adjusted to model minimum (4 for Veo 3.1)'
		);
	}

	/**
	 * Test that negative duration values are converted to absolute values by absint.
	 */
	public function test_negative_duration_converted_to_absolute() {
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

		// Test with negative duration.
		$result = $service->generate_video(
			array(
				'prompt'   => 'Test video',
				'duration' => -5,
			)
		);

		$this->assertNotNull( $captured_request, 'Request should be captured' );
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Negative duration: absint(-5) returns 5 (absolute value), which is within valid range.
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'Negative duration should be converted to absolute value (5) by absint'
		);
	}

	/**
	 * Test that duration values outside 4-8 range are clamped to valid range.
	 */
	public function test_out_of_range_durations_clamped_to_valid_range() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Test values below minimum (should be clamped to 4 for Veo 3.1).
		$below_min_values = array( 1, 2, 3 );
		foreach ( $below_min_values as $test_duration ) {
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

			$result = $service->generate_video(
				array(
					'prompt'   => 'Test video',
					'duration' => $test_duration,
				)
			);

			$this->assertNotNull( $captured_request, "Request should be captured for duration {$test_duration}" );
			$request_body = json_decode( $captured_request['args']['body'], true );

			$this->assertEquals(
				4,
				$request_body['parameters']['durationSeconds'],
				"Duration {$test_duration} (below minimum) should be clamped to 4 seconds (Veo 3.1 minimum)"
			);
		}

		// Test values above maximum (should be clamped to 8).
		$above_max_values = array( 9, 10, 15, 100 );
		foreach ( $above_max_values as $test_duration ) {
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

			$result = $service->generate_video(
				array(
					'prompt'   => 'Test video',
					'duration' => $test_duration,
				)
			);

			$this->assertNotNull( $captured_request, "Request should be captured for duration {$test_duration}" );
			$request_body = json_decode( $captured_request['args']['body'], true );

			$this->assertEquals(
				8,
				$request_body['parameters']['durationSeconds'],
				"Duration {$test_duration} (above maximum) should be clamped to 8 seconds (maximum)"
			);
		}
	}

	/**
	 * Test that all valid duration values (4-8) are passed correctly.
	 */
	public function test_valid_durations_passed_correctly() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Test all valid duration values.
		foreach ( array( 4, 5, 6, 7, 8 ) as $valid_duration ) {
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

			$result = $service->generate_video(
				array(
					'prompt'   => 'Test video',
					'duration' => $valid_duration,
				)
			);

			$this->assertNotNull( $captured_request, "Request should be captured for duration {$valid_duration}" );
			$request_body = json_decode( $captured_request['args']['body'], true );

			$this->assertEquals(
				$valid_duration,
				$request_body['parameters']['durationSeconds'],
				"Valid duration {$valid_duration} should be passed through correctly"
			);
		}
	}

	/**
	 * Test that the final validation catches any edge cases.
	 */
	public function test_final_validation_safety_check() {
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

		// Test without duration parameter (should use default).
		$result = $service->generate_video(
			array(
				'prompt' => 'Test video',
			)
		);

		$this->assertNotNull( $captured_request, 'Request should be captured' );
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Should default to 5.
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'Missing duration should default to 5 seconds'
		);

		// Verify the duration is an integer in the JSON.
		$this->assertIsInt(
			$request_body['parameters']['durationSeconds'],
			'Duration should be an integer, not a string'
		);
	}

	/**
	 * Test that default duration (5) works correctly with Veo 2.0.
	 */
	public function test_default_duration_works_with_veo_2() {
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

		// Test without duration parameter with Veo 2.0 model.
		$result = $service->generate_video(
			array(
				'prompt' => 'Test video',
				'model'  => 'veo-2.0',
			)
		);

		$this->assertNotNull( $captured_request, 'Request should be captured' );
		$request_body = json_decode( $captured_request['args']['body'], true );

		// Default duration (5) should satisfy Veo 2.0's minimum of 5 seconds.
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'Default duration (5) should work with Veo 2.0 (min 5 seconds)'
		);
	}
}
