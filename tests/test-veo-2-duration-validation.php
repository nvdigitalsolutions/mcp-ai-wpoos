<?php
/**
 * Test Veo 2.0 duration validation fix.
 *
 * Tests that the final validation stage correctly enforces model-specific minimum durations.
 * This prevents "durationSeconds is out of bound" errors from the Gemini API.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test class for Veo 2.0 duration validation.
 */
class WP_MCP_AI_Veo_2_Duration_Validation_Test extends WP_UnitTestCase {

	/**
	 * Service instance for testing.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	private $service;

	/**
	 * Veo 2.0 model constant.
	 *
	 * @var string
	 */
	private $veo_2_model;

	/**
	 * Veo 3.1 model constant.
	 *
	 * @var string
	 */
	private $veo_3_model;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$reflection    = new ReflectionClass( $this->service );

		$this->veo_2_model = $reflection->getConstant( 'VEO_2_MODEL' );
		$this->veo_3_model = $reflection->getConstant( 'VEO_MODEL' );
	}

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that duration 4 (Veo 3.1 minimum) is adjusted to 5 for Veo 2.0.
	 * This is the main bug fix - Veo 2 requires minimum 5 seconds, not 4.
	 */
	public function test_veo_2_adjusts_duration_4_to_5() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		$args = array(
			'prompt'   => 'Test video',
			'duration' => 4, // Valid for Veo 3.1 but below Veo 2.0 minimum.
		);

		$payload = $method->invoke( $this->service, $args, $this->veo_2_model );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'parameters', $payload );
		$this->assertArrayHasKey( 'durationSeconds', $payload['parameters'] );

		$this->assertEquals(
			5,
			$payload['parameters']['durationSeconds'],
			'Duration 4 should be adjusted to 5 for Veo 2.0 (minimum 5 seconds)'
		);

		$this->assertIsInt(
			$payload['parameters']['durationSeconds'],
			'Duration should be an integer'
		);
	}

	/**
	 * Test that duration 4 is preserved for Veo 3.1.
	 */
	public function test_veo_3_preserves_duration_4() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		$args = array(
			'prompt'   => 'Test video',
			'duration' => 4, // Valid for Veo 3.1.
		);

		$payload = $method->invoke( $this->service, $args, $this->veo_3_model );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'parameters', $payload );
		$this->assertArrayHasKey( 'durationSeconds', $payload['parameters'] );

		$this->assertEquals(
			4,
			$payload['parameters']['durationSeconds'],
			'Duration 4 should be preserved for Veo 3.1 (minimum 4 seconds)'
		);
	}

	/**
	 * Test that invalid durations (0, negative, non-integer) default to model minimum.
	 */
	public function test_invalid_durations_default_to_model_minimum() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Test duration 0 with Veo 2.0.
		$args    = array(
			'prompt'   => 'Test video',
			'duration' => 0,
		);
		$payload = $method->invoke( $this->service, $args, $this->veo_2_model );

		$this->assertEquals(
			5,
			$payload['parameters']['durationSeconds'],
			'Duration 0 should default to 5 for Veo 2.0'
		);

		// Test duration 0 with Veo 3.1.
		$payload = $method->invoke( $this->service, $args, $this->veo_3_model );

		$this->assertEquals(
			4,
			$payload['parameters']['durationSeconds'],
			'Duration 0 should default to 4 for Veo 3.1'
		);
	}

	/**
	 * Test that durations 1-3 are adjusted to model minimum.
	 */
	public function test_below_minimum_durations_adjusted() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		$below_min_values = array( 1, 2, 3 );

		foreach ( $below_min_values as $test_duration ) {
			// Test with Veo 2.0.
			$args    = array(
				'prompt'   => 'Test video',
				'duration' => $test_duration,
			);
			$payload = $method->invoke( $this->service, $args, $this->veo_2_model );

			$this->assertEquals(
				5,
				$payload['parameters']['durationSeconds'],
				"Duration {$test_duration} should be adjusted to 5 for Veo 2.0"
			);

			// Test with Veo 3.1.
			$payload = $method->invoke( $this->service, $args, $this->veo_3_model );

			$this->assertEquals(
				4,
				$payload['parameters']['durationSeconds'],
				"Duration {$test_duration} should be adjusted to 4 for Veo 3.1"
			);
		}
	}

	/**
	 * Test that all valid durations (5-8) work correctly with Veo 2.0.
	 */
	public function test_veo_2_valid_durations() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Valid durations for Veo 2.0: 5-8 seconds.
		foreach ( array( 5, 6, 7, 8 ) as $valid_duration ) {
			$args    = array(
				'prompt'   => 'Test video',
				'duration' => $valid_duration,
			);
			$payload = $method->invoke( $this->service, $args, $this->veo_2_model );

			$this->assertEquals(
				$valid_duration,
				$payload['parameters']['durationSeconds'],
				"Valid duration {$valid_duration} should be preserved for Veo 2.0"
			);
		}
	}

	/**
	 * Test that all valid durations (4-8) work correctly with Veo 3.1.
	 */
	public function test_veo_3_valid_durations() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Valid durations for Veo 3.1: 4-8 seconds.
		foreach ( array( 4, 5, 6, 7, 8 ) as $valid_duration ) {
			$args    = array(
				'prompt'   => 'Test video',
				'duration' => $valid_duration,
			);
			$payload = $method->invoke( $this->service, $args, $this->veo_3_model );

			$this->assertEquals(
				$valid_duration,
				$payload['parameters']['durationSeconds'],
				"Valid duration {$valid_duration} should be preserved for Veo 3.1"
			);
		}
	}

	/**
	 * Test that excessive durations are clamped to maximum (8).
	 */
	public function test_above_maximum_durations_clamped() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		$above_max_values = array( 9, 10, 15, 100 );

		foreach ( $above_max_values as $test_duration ) {
			// Test with Veo 2.0.
			$args    = array(
				'prompt'   => 'Test video',
				'duration' => $test_duration,
			);
			$payload = $method->invoke( $this->service, $args, $this->veo_2_model );

			$this->assertEquals(
				8,
				$payload['parameters']['durationSeconds'],
				"Duration {$test_duration} should be clamped to 8 for Veo 2.0"
			);

			// Test with Veo 3.1.
			$payload = $method->invoke( $this->service, $args, $this->veo_3_model );

			$this->assertEquals(
				8,
				$payload['parameters']['durationSeconds'],
				"Duration {$test_duration} should be clamped to 8 for Veo 3.1"
			);
		}
	}

	/**
	 * Test that default duration (5) works for both models.
	 */
	public function test_default_duration_works_for_both_models() {
		$method = new ReflectionMethod( $this->service, 'build_generation_payload' );
		$method->setAccessible( true );

		// Test without duration parameter (should use default 5).
		$args = array(
			'prompt' => 'Test video',
		);

		// Test with Veo 2.0.
		$payload = $method->invoke( $this->service, $args, $this->veo_2_model );

		$this->assertEquals(
			5,
			$payload['parameters']['durationSeconds'],
			'Default duration (5) should work for Veo 2.0 (minimum 5 seconds)'
		);

		// Test with Veo 3.1.
		$payload = $method->invoke( $this->service, $args, $this->veo_3_model );

		$this->assertEquals(
			5,
			$payload['parameters']['durationSeconds'],
			'Default duration (5) should work for Veo 3.1 (minimum 4 seconds)'
		);
	}

	/**
	 * Test the actual API request flow with Veo 2.0 and duration 4.
	 * This simulates the real-world scenario that was failing.
	 */
	public function test_veo_2_api_request_with_duration_4() {
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
						'body' => $args['body'],
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

		// Attempt to generate video with Veo 2.0 and duration 4.
		$result = $service->generate_video(
			array(
				'prompt'   => 'Test video',
				'duration' => 4,
				'model'    => 'veo-2.0',
			)
		);

		// Verify request was captured.
		$this->assertNotNull( $captured_request, 'Request should be captured' );

		// Verify URL contains Veo 2.0 model.
		$this->assertStringContainsString(
			'veo-2.0-generate-001',
			$captured_request['url'],
			'URL should reference Veo 2.0 model'
		);

		// Parse request body.
		$request_body = json_decode( $captured_request['body'], true );

		// Verify duration was adjusted to 5 (not 4).
		$this->assertArrayHasKey( 'parameters', $request_body );
		$this->assertArrayHasKey( 'durationSeconds', $request_body['parameters'] );
		$this->assertEquals(
			5,
			$request_body['parameters']['durationSeconds'],
			'Duration 4 should be adjusted to 5 in actual API request for Veo 2.0'
		);

		// Verify it's an integer.
		$this->assertIsInt(
			$request_body['parameters']['durationSeconds'],
			'Duration should be an integer in API request'
		);
	}

	/**
	 * Test the actual API request flow with Veo 3.1 and duration 4.
	 * Verifies that duration 4 is preserved for Veo 3.1.
	 */
	public function test_veo_3_api_request_with_duration_4() {
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
						'body' => $args['body'],
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

		// Attempt to generate video with Veo 3.1 and duration 4.
		$result = $service->generate_video(
			array(
				'prompt'   => 'Test video',
				'duration' => 4,
				'model'    => 'veo-3.1',
			)
		);

		// Verify request was captured.
		$this->assertNotNull( $captured_request, 'Request should be captured' );

		// Verify URL contains Veo 3.1 model.
		$this->assertStringContainsString(
			'veo-3.1-generate-preview',
			$captured_request['url'],
			'URL should reference Veo 3.1 model'
		);

		// Parse request body.
		$request_body = json_decode( $captured_request['body'], true );

		// Verify duration 4 is preserved.
		$this->assertArrayHasKey( 'parameters', $request_body );
		$this->assertArrayHasKey( 'durationSeconds', $request_body['parameters'] );
		$this->assertEquals(
			4,
			$request_body['parameters']['durationSeconds'],
			'Duration 4 should be preserved in actual API request for Veo 3.1'
		);
	}
}
