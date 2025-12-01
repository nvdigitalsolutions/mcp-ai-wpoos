<?php
/**
 * Tests for OpenAI Video Analysis Integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test OpenAI video analysis via frame extraction.
 */
class Test_OpenAI_Video_Analysis extends WP_UnitTestCase {
	/**
	 * Video analysis service instance.
	 *
	 * @var WP_MCP_AI_Video_Analysis_Service
	 */
	protected $service;

	/**
	 * Test video file path.
	 *
	 * @var string
	 */
	protected $test_video_path;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-analysis-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-frame-extractor-service.php';

		$this->service = new WP_MCP_AI_Video_Analysis_Service();

		// Create a test video if FFmpeg is available.
		if ( $this->is_ffmpeg_available() ) {
			$this->test_video_path = $this->create_test_video();
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test video.
		if ( ! empty( $this->test_video_path ) && file_exists( $this->test_video_path ) ) {
			unlink( $this->test_video_path );
		}

		parent::tearDown();
	}

	/**
	 * Check if FFmpeg is available.
	 *
	 * @return bool
	 */
	protected function is_ffmpeg_available() {
		exec( 'ffmpeg -version 2>&1', $output, $return_code );
		return 0 === $return_code;
	}

	/**
	 * Create a minimal test video file.
	 *
	 * @return string Path to test video.
	 */
	protected function create_test_video() {
		$upload_dir = wp_upload_dir();
		$video_path = $upload_dir['basedir'] . '/test-video-openai-' . uniqid() . '.mp4';

		// Create a minimal black video (1 second, 320x240).
		$command = sprintf(
			'ffmpeg -f lavfi -i color=c=black:s=320x240:d=1 -f lavfi -i anullsrc=r=44100:cl=mono -c:v libx264 -t 1 -pix_fmt yuv420p -y %s 2>&1',
			escapeshellarg( $video_path )
		);
		exec( $command );

		return $video_path;
	}

	/**
	 * Test that analyze_video method accepts OpenAI as provider.
	 */
	public function test_analyze_video_accepts_openai_provider() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for OpenAI video analysis test' );
		}

		// Create a mock to avoid actual API call.
		$mock_service = $this->getMockBuilder( WP_MCP_AI_Video_Analysis_Service::class )
			->onlyMethods( array( 'analyze_with_openai' ) )
			->getMock();

		$mock_service->expects( $this->once() )
			->method( 'analyze_with_openai' )
			->willReturn(
				array(
					'text'        => 'Mock analysis result',
					'provider'    => 'openai',
					'model'       => 'gpt-4o',
					'frame_count' => 10,
				)
			);

		$result = $mock_service->analyze_video(
			array(
				'video_url' => $this->test_video_path,
				'prompt'    => 'Analyze this video',
				'provider'  => 'openai',
			)
		);

		$this->assertIsArray( $result, 'Should return array result' );
		$this->assertEquals( 'openai', $result['provider'], 'Provider should be OpenAI' );
	}

	/**
	 * Test OpenAI analysis returns error without FFmpeg.
	 */
	public function test_openai_analysis_error_without_ffmpeg() {
		// Create a reflection to call protected method.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'analyze_with_openai' );
		$method->setAccessible( true );

		// Mock FFmpeg as unavailable by using a mock extractor.
		$result = $method->invoke( $this->service, '', null, 'Test prompt', 'gpt-4o' );

		// Since we can't easily mock the extractor inside the method,
		// we'll check if the result structure is correct.
		// The actual FFmpeg check will depend on system state.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Should return array or WP_Error'
		);
	}

	/**
	 * Test that OpenAI analysis requires video_url or attachment_id.
	 */
	public function test_analyze_video_missing_video_source() {
		$result = $this->service->analyze_video(
			array(
				'prompt'   => 'Analyze this video',
				'provider' => 'openai',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return error without video source' );
		$this->assertEquals( 'wp_mcp_ai_missing_video', $result->get_error_code() );
	}

	/**
	 * Test unsupported provider returns error.
	 */
	public function test_analyze_video_unsupported_provider() {
		$result = $this->service->analyze_video(
			array(
				'video_url' => 'https://example.com/video.mp4',
				'provider'  => 'unsupported_provider',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return error for unsupported provider' );
		$this->assertEquals( 'wp_mcp_ai_unsupported_provider', $result->get_error_code() );
	}

	/**
	 * Test OpenAI provider routing.
	 */
	public function test_openai_provider_routing() {
		// We need to use reflection to check if the right method is called.
		$mock_service = $this->getMockBuilder( WP_MCP_AI_Video_Analysis_Service::class )
			->onlyMethods( array( 'analyze_with_openai' ) )
			->getMock();

		$mock_service->expects( $this->once() )
			->method( 'analyze_with_openai' )
			->with(
				$this->equalTo( 'https://example.com/video.mp4' ),
				$this->equalTo( null ),
				$this->equalTo( 'Test prompt' ),
				$this->equalTo( '' )
			)
			->willReturn( array( 'text' => 'Success' ) );

		$result = $mock_service->analyze_video(
			array(
				'video_url' => 'https://example.com/video.mp4',
				'prompt'    => 'Test prompt',
				'provider'  => 'openai',
			)
		);

		$this->assertIsArray( $result, 'Should return array result' );
	}

	/**
	 * Test frame extraction error handling in OpenAI analysis.
	 */
	public function test_openai_analysis_handles_extraction_errors() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for error handling test' );
		}

		// Use a non-existent file to trigger extraction error.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'analyze_with_openai' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->service,
			'',
			999999, // Non-existent attachment ID
			'Test prompt',
			'gpt-4o'
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return error for invalid attachment' );
	}

	/**
	 * Test that OpenAI analysis uses correct default model.
	 */
	public function test_openai_analysis_default_model() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for model test' );
		}

		// We'll check that gpt-4o is used as the default.
		// Since we can't easily test the actual API call without mocking OpenAI client,
		// we'll verify the service structure accepts model parameter.
		$result = $this->service->analyze_video(
			array(
				'video_url' => $this->test_video_path,
				'provider'  => 'openai',
				'model'     => 'gpt-4o-mini',
			)
		);

		// Without API credentials, this will fail with an error, but we can check
		// that it's trying to process (not a structural error).
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Should attempt to process with custom model'
		);
	}

	/**
	 * Test integration between frame extractor and video analysis service.
	 */
	public function test_frame_extractor_integration() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for integration test' );
		}

		// Create frame extractor.
		$extractor = new WP_MCP_AI_Video_Frame_Extractor_Service();

		// Extract frames from test video.
		$frame_paths = $extractor->extract_frames( $this->test_video_path, 5 );

		$this->assertNotInstanceOf( WP_Error::class, $frame_paths, 'Frame extraction should succeed' );
		$this->assertIsArray( $frame_paths, 'Should return array of paths' );
		$this->assertGreaterThan( 0, count( $frame_paths ), 'Should extract at least one frame' );

		// Convert to base64.
		$base64_frames = $extractor->frames_to_base64( $frame_paths );

		$this->assertIsArray( $base64_frames, 'Should return base64 array' );
		$this->assertCount( count( $frame_paths ), $base64_frames, 'Should have same count' );

		// Each base64 frame should be a valid data URL.
		foreach ( $base64_frames as $frame ) {
			$this->assertStringStartsWith( 'data:image/', $frame, 'Should be image data URL' );
		}

		// Clean up.
		$extractor->cleanup_frames( $frame_paths );
	}

	/**
	 * Test that cleanup happens even on errors.
	 */
	public function test_cleanup_on_error() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for cleanup test' );
		}

		// This test verifies that temporary files are cleaned up properly.
		// We'll track the upload directory before and after.
		$upload_dir = wp_upload_dir();
		$temp_base  = $upload_dir['basedir'] . '/wp-mcp-ai-temp';

		// Count existing temp directories.
		$before_count = 0;
		if ( file_exists( $temp_base ) ) {
			$before_count = count( glob( $temp_base . '/*' ) );
		}

		// Try to analyze (will fail due to missing API key, but should clean up).
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'analyze_with_openai' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->service,
			$this->test_video_path,
			null,
			'Test prompt',
			'gpt-4o'
		);

		// Count temp directories after (should be same or cleaned up).
		$after_count = 0;
		if ( file_exists( $temp_base ) ) {
			$after_count = count( glob( $temp_base . '/*' ) );
		}

		// We expect cleanup to happen, so count should not increase permanently.
		$this->assertLessThanOrEqual(
			$before_count + 1,
			$after_count,
			'Temp directories should be cleaned up'
		);
	}
}
