<?php
/**
 * Test video download authentication fix for 403 errors.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that video downloads include API key for authentication.
 */
class Test_Veo_Video_Download_Auth extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Set up test API key.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['gemini_api_key'] = 'test-api-key-12345';
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Test that download_video adds API key to URL.
	 */
	public function test_download_video_adds_api_key_to_url() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		$captured_url = null;

		// Mock wp_remote_get to capture the URL.
		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;

			// Return a successful mock response.
			return array(
				'headers'  => array(),
				'body'     => 'fake video data',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		// Test with a GCS URL.
		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4';
		$result    = $method->invoke( $service, $video_uri );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify URL was captured and contains API key.
		$this->assertNotNull( $captured_url, 'URL should have been captured' );
		$this->assertStringContainsString( 'key=test-api-key-12345', $captured_url, 'URL should contain API key' );
		$this->assertStringContainsString( 'storage.googleapis.com', $captured_url, 'URL should contain GCS domain' );

		// Verify result is the video data.
		$this->assertEquals( 'fake video data', $result );
	}

	/**
	 * Test that download_video handles missing API key.
	 */
	public function test_download_video_missing_api_key() {
		// Remove API key.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['gemini_api_key'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4';
		$result    = $method->invoke( $service, $video_uri );

		// Verify error is returned.
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Test that download_video handles 403 errors.
	 */
	public function test_download_video_handles_403_error() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		// Mock wp_remote_get to return 403.
		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4';
		$result    = $method->invoke( $service, $video_uri );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify error is returned.
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_download_failed', $result->get_error_code() );
		$this->assertStringContainsString( '403', $result->get_error_message() );
	}

	/**
	 * Test that download_video handles empty response.
	 */
	public function test_download_video_handles_empty_response() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		// Mock wp_remote_get to return empty body.
		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4';
		$result    = $method->invoke( $service, $video_uri );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify error is returned.
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_empty_video', $result->get_error_code() );
	}

	/**
	 * Test that download_video preserves URL structure.
	 */
	public function test_download_video_preserves_url_structure() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		$captured_url = null;

		// Mock wp_remote_get to capture the URL.
		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;

			return array(
				'headers'  => array(),
				'body'     => 'video data',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		// Test with a URL that already has query parameters.
		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4?existing=param';
		$result    = $method->invoke( $service, $video_uri );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify both existing params and API key are present.
		$this->assertStringContainsString( 'existing=param', $captured_url, 'Existing params should be preserved' );
		$this->assertStringContainsString( 'key=test-api-key-12345', $captured_url, 'API key should be added' );
		$this->assertStringContainsString( '&', $captured_url, 'Multiple params should be separated' );
	}

	/**
	 * Test that download logs are created.
	 */
	public function test_download_video_logging() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		// Track logged events.
		$logged_events = array();
		add_filter(
			'wp_mcp_ai_log_event',
			function ( $event_type, $message, $context ) use ( &$logged_events ) {
				$logged_events[] = array(
					'type'    => $event_type,
					'message' => $message,
					'context' => $context,
				);
			},
			10,
			3
		);

		// Mock successful download.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				return array(
					'headers'  => array(),
					'body'     => 'test video data',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4';
		$result    = $method->invoke( $service, $video_uri );

		// Verify logging occurred.
		$this->assertNotEmpty( $logged_events, 'Events should be logged' );

		// Check for download attempt log.
		$attempt_log = array_filter(
			$logged_events,
			function ( $event ) {
				return 'veo_video_download_attempt' === $event['type'];
			}
		);
		$this->assertNotEmpty( $attempt_log, 'Download attempt should be logged' );

		// Check for success log.
		$success_log = array_filter(
			$logged_events,
			function ( $event ) {
				return 'veo_video_downloaded' === $event['type'];
			}
		);
		$this->assertNotEmpty( $success_log, 'Download success should be logged' );
	}

	/**
	 * Test timeout configuration.
	 */
	public function test_download_video_timeout() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'download_video' );
		$method->setAccessible( true );

		$captured_args = null;

		// Mock wp_remote_get to capture the args.
		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_args ) {
			$captured_args = $args;

			return array(
				'headers'  => array(),
				'body'     => 'video data',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$video_uri = 'https://storage.googleapis.com/gemini-generated-videos/video-123.mp4';
		$result    = $method->invoke( $service, $video_uri );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify timeout is set to 300 seconds (5 minutes).
		$this->assertEquals( 300, $captured_args['timeout'], 'Timeout should be 300 seconds' );
	}
}
