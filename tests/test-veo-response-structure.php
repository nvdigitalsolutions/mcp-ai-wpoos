<?php
/**
 * Test Veo Video Generation Service Response Structure Handling
 *
 * Tests that the service can handle both old (legacy) and new (2025) API response structures.
 *
 * @package WP_MCP_AI
 */

class Test_Veo_Response_Structure extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Set up mock API key
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-api-key',
			)
		);
	}

	/**
	 * Test processing video with new API response structure (2025).
	 *
	 * New structure:
	 * response.generateVideoResponse.generatedSamples[0].video.uri
	 */
	public function test_process_completed_video_new_structure() {
		// Mock the completed operation response with new structure
		$result = array(
			'done'     => true,
			'response' => array(
				'generateVideoResponse' => array(
					'generatedSamples' => array(
						array(
							'video' => array(
								'uri' => 'https://example.com/video-new-structure.mp4',
							),
						),
					),
				),
			),
		);

		$args = array(
			'prompt'       => 'Test video prompt',
			'duration'     => 5,
			'aspect_ratio' => '3:2',
			'resolution'   => '720p',
		);

		// Mock the download function
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'video-new-structure.mp4' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'mock-video-data-new-structure',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'process_completed_video' );
		$method->setAccessible( true );

		$processed = $method->invoke( $this->service, $result, $args );

		// Verify the result
		$this->assertNotWPError( $processed, 'Processing should succeed with new structure' );
		$this->assertArrayHasKey( 'video_data', $processed );
		$this->assertEquals( 'mock-video-data-new-structure', $processed['video_data'] );
		$this->assertEquals( 'https://example.com/video-new-structure.mp4', $processed['video_uri'] );
		$this->assertEquals( 'Test video prompt', $processed['prompt'] );
		$this->assertEquals( 5, $processed['duration'] );
		$this->assertEquals( '3:2', $processed['aspect_ratio'] );
		$this->assertEquals( '720p', $processed['resolution'] );
	}

	/**
	 * Test processing video with old API response structure (legacy).
	 *
	 * Old structure:
	 * response.predictions[0].videoUri
	 */
	public function test_process_completed_video_old_structure() {
		// Mock the completed operation response with old structure
		$result = array(
			'done'     => true,
			'response' => array(
				'predictions' => array(
					array(
						'videoUri' => 'https://example.com/video-old-structure.mp4',
					),
				),
			),
		);

		$args = array(
			'prompt'       => 'Test video prompt old structure',
			'duration'     => 4,
			'aspect_ratio' => '2:3',
			'resolution'   => '720p',
		);

		// Mock the download function
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'video-old-structure.mp4' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'mock-video-data-old-structure',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'process_completed_video' );
		$method->setAccessible( true );

		$processed = $method->invoke( $this->service, $result, $args );

		// Verify the result
		$this->assertNotWPError( $processed, 'Processing should succeed with old structure' );
		$this->assertArrayHasKey( 'video_data', $processed );
		$this->assertEquals( 'mock-video-data-old-structure', $processed['video_data'] );
		$this->assertEquals( 'https://example.com/video-old-structure.mp4', $processed['video_uri'] );
		$this->assertEquals( 'Test video prompt old structure', $processed['prompt'] );
		$this->assertEquals( 4, $processed['duration'] );
		$this->assertEquals( '2:3', $processed['aspect_ratio'] );
		$this->assertEquals( '720p', $processed['resolution'] );
	}

	/**
	 * Test processing video with missing video URI in response.
	 *
	 * Should return WP_Error when no video URI is found.
	 */
	public function test_process_completed_video_missing_uri() {
		// Mock response with no video URI
		$result = array(
			'done'     => true,
			'response' => array(
				'metadata' => array(
					'some' => 'data',
				),
			),
		);

		$args = array(
			'prompt' => 'Test video prompt',
		);

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'process_completed_video' );
		$method->setAccessible( true );

		$processed = $method->invoke( $this->service, $result, $args );

		// Verify error is returned
		$this->assertWPError( $processed, 'Should return error when video URI is missing' );
		$this->assertEquals( 'wp_mcp_ai_no_video_uri', $processed->get_error_code() );
		$this->assertEquals( 'No video URI in completion response.', $processed->get_error_message() );
	}

	/**
	 * Test that new structure takes precedence when both are present.
	 *
	 * This ensures that if the API sends both structures (which shouldn't happen
	 * but could during a transition period), we use the new structure.
	 */
	public function test_process_completed_video_both_structures_prefer_new() {
		// Mock response with both structures
		$result = array(
			'done'     => true,
			'response' => array(
				'generateVideoResponse' => array(
					'generatedSamples' => array(
						array(
							'video' => array(
								'uri' => 'https://example.com/video-new.mp4',
							),
						),
					),
				),
				'predictions'           => array(
					array(
						'videoUri' => 'https://example.com/video-old.mp4',
					),
				),
			),
		);

		$args = array(
			'prompt' => 'Test video prompt',
		);

		// Mock the download function
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'video-new.mp4' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'mock-video-data-new',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'process_completed_video' );
		$method->setAccessible( true );

		$processed = $method->invoke( $this->service, $result, $args );

		// Verify the new structure URI is used
		$this->assertNotWPError( $processed );
		$this->assertEquals( 'https://example.com/video-new.mp4', $processed['video_uri'] );
		$this->assertEquals( 'mock-video-data-new', $processed['video_data'] );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		// Remove filters
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}
}
