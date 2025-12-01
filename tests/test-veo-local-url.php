<?php
/**
 * Test that Veo video generation returns local WordPress URLs, not external URLs
 *
 * This test verifies that when media is saved to WordPress, the returned URL
 * is from the local uploads directory, not from external storage like OneDrive.
 *
 * @package WP_MCP_AI
 */

class Test_Veo_Local_URL extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Veo_Video
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$this->tool    = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Set up mock API key.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-api-key',
			)
		);
	}

	/**
	 * Test that save_video_to_media returns local WordPress URL.
	 *
	 * This test verifies that the URL returned is from wp_upload_bits,
	 * not from wp_get_attachment_url which could be filtered by offloading plugins.
	 */
	public function test_service_save_video_returns_local_url() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = array(
			'video_data'   => 'fake-video-data',
			'prompt'       => 'Test video',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		// Mock wp_get_attachment_url to return OneDrive URL (simulating offloading).
		add_filter(
			'wp_get_attachment_url',
			function ( $url, $attachment_id ) {
				return 'https://onedrive.live.com/fake-video.mp4';
			},
			10,
			2
		);

		$save_result = $method->invoke( $this->service, $result, $user_id );

		// Verify it's not an error.
		$this->assertNotWPError( $save_result, 'save_video_to_media should succeed' );

		// Verify it returns an array with attachment_id and url.
		$this->assertIsArray( $save_result, 'save_video_to_media should return array' );
		$this->assertArrayHasKey( 'attachment_id', $save_result, 'Result should have attachment_id' );
		$this->assertArrayHasKey( 'url', $save_result, 'Result should have url' );

		// Verify the URL is a local WordPress URL, not OneDrive.
		$this->assertStringContainsString( 'wp-content/uploads', $save_result['url'], 'URL should be local WordPress uploads URL' );
		$this->assertStringNotContainsString( 'onedrive', $save_result['url'], 'URL should NOT be OneDrive URL' );

		// Verify it contains the year/month structure typical of WordPress uploads.
		$year_month = gmdate( 'Y/m' );
		$this->assertStringContainsString( $year_month, $save_result['url'], 'URL should contain current year/month path' );
	}

	/**
	 * Test that tool save_video_to_media returns local WordPress URL.
	 */
	public function test_tool_save_video_returns_local_url() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = array(
			'video_data'   => 'fake-video-data',
			'prompt'       => 'Test video',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		// Mock wp_get_attachment_url to return OneDrive URL.
		add_filter(
			'wp_get_attachment_url',
			function ( $url, $attachment_id ) {
				return 'https://onedrive.live.com/fake-video.mp4';
			},
			10,
			2
		);

		$save_result = $method->invoke( $this->tool, $result, $user_id );

		// Verify it's not an error.
		$this->assertNotWPError( $save_result, 'save_video_to_media should succeed' );

		// Verify it returns an array with attachment_id and url.
		$this->assertIsArray( $save_result, 'save_video_to_media should return array' );
		$this->assertArrayHasKey( 'attachment_id', $save_result, 'Result should have attachment_id' );
		$this->assertArrayHasKey( 'url', $save_result, 'Result should have url' );

		// Verify the URL is a local WordPress URL, not OneDrive.
		$this->assertStringContainsString( 'wp-content/uploads', $save_result['url'], 'URL should be local WordPress uploads URL' );
		$this->assertStringNotContainsString( 'onedrive', $save_result['url'], 'URL should NOT be OneDrive URL' );
	}

	/**
	 * Test that async completion uses local URL.
	 */
	public function test_async_completion_uses_local_url() {
		// Create a mock completed operation result.
		$result = array(
			'done'     => true,
			'response' => array(
				'generateVideoResponse' => array(
					'generatedSamples' => array(
						array(
							'video' => array(
								'uri' => 'https://example.com/video.mp4',
							),
						),
					),
				),
			),
		);

		$args = array(
			'prompt'        => 'Test video',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'save_to_media' => true,
			'user_id'       => $this->factory->user->create( array( 'role' => 'administrator' ) ),
		);

		// Mock the download function.
		add_filter(
			'pre_http_request',
			function ( $preempt, $request_args, $url ) {
				if ( false !== strpos( $url, 'video.mp4' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'mock-video-data',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		// Mock wp_get_attachment_url to return OneDrive URL.
		add_filter(
			'wp_get_attachment_url',
			function ( $url, $attachment_id ) {
				return 'https://onedrive.live.com/fake-video.mp4';
			},
			10,
			2
		);

		// Process the completed video using reflection.
		$reflection     = new ReflectionClass( $this->service );
		$process_method = $reflection->getMethod( 'process_completed_video' );
		$process_method->setAccessible( true );
		$video_result = $process_method->invoke( $this->service, $result, $args );

		$this->assertNotWPError( $video_result, 'process_completed_video should succeed' );

		// Now simulate the async polling save.
		$save_method = $reflection->getMethod( 'save_video_to_media' );
		$save_method->setAccessible( true );
		$save_result = $save_method->invoke( $this->service, $video_result, $args['user_id'] );

		// Verify the saved result has local URL.
		$this->assertIsArray( $save_result, 'save_video_to_media should return array' );
		$this->assertArrayHasKey( 'url', $save_result, 'Result should have url' );
		$this->assertStringContainsString( 'wp-content/uploads', $save_result['url'], 'Async result URL should be local WordPress URL' );
		$this->assertStringNotContainsString( 'onedrive', $save_result['url'], 'Async result URL should NOT be OneDrive URL' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Remove all filters we added.
		remove_all_filters( 'wp_get_attachment_url' );
		remove_all_filters( 'pre_http_request' );

		parent::tearDown();
	}
}
