<?php
/**
 * Tests for Veo video generation parameter validation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Veo parameter validation.
 */
class WP_MCP_AI_Veo_Validation_Test extends WP_UnitTestCase {

	/**
	 * Service instance for testing.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	private $service;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
	}

	/**
	 * Test that 1080p with 9:16 aspect ratio returns validation error.
	 */
	public function test_1080p_rejects_9_16_aspect_ratio() {
		$args = array(
			'prompt'       => 'Test video',
			'resolution'   => '1080p',
			'aspect_ratio' => '9:16',
		);

		$result = $this->service->generate_video( $args );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
		$this->assertStringContainsString( '1080p', $result->get_error_message() );
		$this->assertStringContainsString( '16:9', $result->get_error_message() );
	}

	/**
	 * Test that 1080p with non-8-second duration returns validation error.
	 */
	public function test_1080p_requires_8_seconds_duration() {
		$args = array(
			'prompt'       => 'Test video',
			'resolution'   => '1080p',
			'aspect_ratio' => '16:9',
			'duration'     => 5, // Should be 8 for 1080p.
		);

		$result = $this->service->generate_video( $args );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
		$this->assertStringContainsString( '8', $result->get_error_message() );
		$this->assertStringContainsString( 'duration', $result->get_error_message() );
	}

	/**
	 * Test that missing prompt returns error.
	 */
	public function test_missing_prompt_returns_error() {
		$args = array(
			'resolution'   => '720p',
			'aspect_ratio' => '16:9',
		);

		$result = $this->service->generate_video( $args );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test that 720p with various durations passes validation.
	 *
	 * Note: This only tests parameter validation, not actual API calls.
	 */
	public function test_720p_accepts_various_durations() {
		// We can't test the full flow without an API key, but we can verify.
		// that valid 720p parameters pass the initial validation step.
		$valid_params = array(
			array(
				'prompt'       => 'Test',
				'resolution'   => '720p',
				'aspect_ratio' => '16:9',
				'duration'     => 5,
			),
			array(
				'prompt'       => 'Test',
				'resolution'   => '720p',
				'aspect_ratio' => '9:16',
				'duration'     => 6,
			),
		);

		foreach ( $valid_params as $args ) {
			$result = $this->service->generate_video( $args );

			// Without API key, we expect a missing API key error, not a validation error.
			// This confirms the validation passed.
			if ( is_wp_error( $result ) ) {
				$this->assertNotEquals(
					'wp_mcp_ai_invalid_arguments',
					$result->get_error_code(),
					'Valid 720p parameters should not trigger validation error'
				);
			}
		}
	}
}
