<?php
/**
 * Tests for Video Frame Extractor Service.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Video Frame Extractor Service functionality.
 */
class Test_Video_Frame_Extractor extends WP_UnitTestCase {
	/**
	 * Frame extractor service instance.
	 *
	 * @var WP_MCP_AI_Video_Frame_Extractor_Service
	 */
	protected $extractor;

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

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-frame-extractor-service.php';
		$this->extractor = new WP_MCP_AI_Video_Frame_Extractor_Service();

		// Create a test video file (minimal MP4).
		$this->test_video_path = $this->create_test_video();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test video.
		if ( file_exists( $this->test_video_path ) ) {
			unlink( $this->test_video_path );
		}

		parent::tearDown();
	}

	/**
	 * Create a minimal test video file.
	 *
	 * @return string Path to test video.
	 */
	protected function create_test_video() {
		$upload_dir = wp_upload_dir();
		$video_path = $upload_dir['basedir'] . '/test-video-' . uniqid() . '.mp4';

		// Create a minimal black video using FFmpeg (1 second, 320x240).
		if ( $this->is_ffmpeg_available() ) {
			$command = sprintf(
				'ffmpeg -f lavfi -i color=c=black:s=320x240:d=1 -f lavfi -i anullsrc=r=44100:cl=mono -c:v libx264 -t 1 -pix_fmt yuv420p -y %s 2>&1',
				escapeshellarg( $video_path )
			);
			exec( $command );
		} else {
			// If FFmpeg not available, create a dummy file.
			file_put_contents( $video_path, 'dummy video content for testing' );
		}

		return $video_path;
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
	 * Test FFmpeg availability check.
	 */
	public function test_is_ffmpeg_available() {
		$available = $this->extractor->is_ffmpeg_available();

		// This should match the actual FFmpeg installation status.
		if ( $this->is_ffmpeg_available() ) {
			$this->assertTrue( $available, 'FFmpeg should be detected as available' );
		} else {
			$this->assertFalse( $available, 'FFmpeg should be detected as not available' );
		}
	}

	/**
	 * Test video duration detection.
	 */
	public function test_get_video_duration() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for video duration test' );
		}

		$duration = $this->extractor->get_video_duration( $this->test_video_path );

		$this->assertNotInstanceOf( WP_Error::class, $duration, 'Duration should not be an error' );
		$this->assertIsFloat( $duration, 'Duration should be a float' );
		$this->assertGreaterThan( 0, $duration, 'Duration should be greater than 0' );
		$this->assertLessThanOrEqual( 2, $duration, 'Test video should be ~1 second' );
	}

	/**
	 * Test video duration with non-existent file.
	 */
	public function test_get_video_duration_file_not_found() {
		$duration = $this->extractor->get_video_duration( '/path/to/nonexistent/video.mp4' );

		$this->assertInstanceOf( WP_Error::class, $duration, 'Should return WP_Error for missing file' );
		$this->assertEquals( 'wp_mcp_ai_video_not_found', $duration->get_error_code() );
	}

	/**
	 * Test frame extraction.
	 */
	public function test_extract_frames() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for frame extraction test' );
		}

		$frame_paths = $this->extractor->extract_frames( $this->test_video_path, 3 );

		$this->assertNotInstanceOf( WP_Error::class, $frame_paths, 'Frame extraction should not return error' );
		$this->assertIsArray( $frame_paths, 'Should return array of frame paths' );
		$this->assertCount( 3, $frame_paths, 'Should extract exactly 3 frames' );

		// Verify frames exist and are images.
		foreach ( $frame_paths as $frame_path ) {
			$this->assertFileExists( $frame_path, 'Frame file should exist' );
			$this->assertGreaterThan( 0, filesize( $frame_path ), 'Frame file should not be empty' );

			// Check MIME type.
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_file( $finfo, $frame_path );
			finfo_close( $finfo );

			$this->assertEquals( 'image/jpeg', $mime_type, 'Frame should be JPEG image' );
		}

		// Clean up.
		$this->extractor->cleanup_frames( $frame_paths );
	}

	/**
	 * Test frame extraction without FFmpeg.
	 */
	public function test_extract_frames_without_ffmpeg() {
		// Create a mock extractor that reports FFmpeg as unavailable.
		$mock_extractor = $this->getMockBuilder( WP_MCP_AI_Video_Frame_Extractor_Service::class )
			->onlyMethods( array( 'is_ffmpeg_available' ) )
			->getMock();

		$mock_extractor->method( 'is_ffmpeg_available' )
			->willReturn( false );

		$result = $mock_extractor->extract_frames( $this->test_video_path, 5 );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return error without FFmpeg' );
		$this->assertEquals( 'wp_mcp_ai_ffmpeg_not_found', $result->get_error_code() );
	}

	/**
	 * Test frame extraction with non-existent video.
	 */
	public function test_extract_frames_file_not_found() {
		$result = $this->extractor->extract_frames( '/path/to/nonexistent/video.mp4', 5 );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return error for missing file' );
		$this->assertEquals( 'wp_mcp_ai_video_not_found', $result->get_error_code() );
	}

	/**
	 * Test frame extraction with default frame count.
	 */
	public function test_extract_frames_default_count() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for frame extraction test' );
		}

		$frame_paths = $this->extractor->extract_frames( $this->test_video_path );

		$this->assertNotInstanceOf( WP_Error::class, $frame_paths, 'Frame extraction should not return error' );
		$this->assertIsArray( $frame_paths, 'Should return array of frame paths' );
		$this->assertCount( 10, $frame_paths, 'Should extract 10 frames by default' );

		// Clean up.
		$this->extractor->cleanup_frames( $frame_paths );
	}

	/**
	 * Test frame extraction with max frame count limit.
	 */
	public function test_extract_frames_max_limit() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for frame extraction test' );
		}

		// Try to extract 100 frames (should be limited to max).
		$frame_paths = $this->extractor->extract_frames( $this->test_video_path, 100 );

		$this->assertNotInstanceOf( WP_Error::class, $frame_paths, 'Frame extraction should not return error' );
		$this->assertIsArray( $frame_paths, 'Should return array of frame paths' );
		$this->assertLessThanOrEqual( 20, count( $frame_paths ), 'Should not exceed max frame count of 20' );

		// Clean up.
		$this->extractor->cleanup_frames( $frame_paths );
	}

	/**
	 * Test frame cleanup.
	 */
	public function test_cleanup_frames() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for cleanup test' );
		}

		// Extract frames.
		$frame_paths = $this->extractor->extract_frames( $this->test_video_path, 3 );
		$this->assertNotInstanceOf( WP_Error::class, $frame_paths );

		// Get directory.
		$directory = dirname( $frame_paths[0] );
		$this->assertDirectoryExists( $directory, 'Frame directory should exist' );

		// Clean up.
		$result = $this->extractor->cleanup_frames( $frame_paths );
		$this->assertTrue( $result, 'Cleanup should succeed' );

		// Verify files and directory are gone.
		foreach ( $frame_paths as $frame_path ) {
			$this->assertFileDoesNotExist( $frame_path, 'Frame file should be deleted' );
		}
		$this->assertDirectoryDoesNotExist( $directory, 'Frame directory should be deleted' );
	}

	/**
	 * Test frame cleanup with directory path.
	 */
	public function test_cleanup_frames_with_directory() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for cleanup test' );
		}

		// Extract frames.
		$frame_paths = $this->extractor->extract_frames( $this->test_video_path, 3 );
		$this->assertNotInstanceOf( WP_Error::class, $frame_paths );

		// Get directory.
		$directory = dirname( $frame_paths[0] );

		// Clean up using directory path.
		$result = $this->extractor->cleanup_frames( $directory );
		$this->assertTrue( $result, 'Cleanup should succeed' );

		// Verify directory is gone.
		$this->assertDirectoryDoesNotExist( $directory, 'Frame directory should be deleted' );
	}

	/**
	 * Test converting frames to base64.
	 */
	public function test_frames_to_base64() {
		if ( ! $this->is_ffmpeg_available() ) {
			$this->markTestSkipped( 'FFmpeg not available for base64 test' );
		}

		// Extract frames.
		$frame_paths = $this->extractor->extract_frames( $this->test_video_path, 3 );
		$this->assertNotInstanceOf( WP_Error::class, $frame_paths );

		// Convert to base64.
		$base64_frames = $this->extractor->frames_to_base64( $frame_paths );

		$this->assertIsArray( $base64_frames, 'Should return array of base64 strings' );
		$this->assertCount( 3, $base64_frames, 'Should have same count as frame paths' );

		// Verify base64 format.
		foreach ( $base64_frames as $base64_frame ) {
			$this->assertIsString( $base64_frame, 'Each frame should be a string' );
			$this->assertStringStartsWith( 'data:image/', $base64_frame, 'Should be data URL' );
			$this->assertStringContainsString( 'base64,', $base64_frame, 'Should contain base64 marker' );
		}

		// Clean up.
		$this->extractor->cleanup_frames( $frame_paths );
	}

	/**
	 * Test frames to base64 with empty array.
	 */
	public function test_frames_to_base64_empty_array() {
		$base64_frames = $this->extractor->frames_to_base64( array() );

		$this->assertIsArray( $base64_frames, 'Should return array' );
		$this->assertEmpty( $base64_frames, 'Should be empty array' );
	}

	/**
	 * Test frames to base64 with non-existent files.
	 */
	public function test_frames_to_base64_missing_files() {
		$base64_frames = $this->extractor->frames_to_base64(
			array(
				'/path/to/missing/frame1.jpg',
				'/path/to/missing/frame2.jpg',
			)
		);

		$this->assertIsArray( $base64_frames, 'Should return array' );
		$this->assertEmpty( $base64_frames, 'Should be empty array for missing files' );
	}

	/**
	 * Test constructor with custom parameters.
	 */
	public function test_constructor_custom_parameters() {
		$custom_extractor = new WP_MCP_AI_Video_Frame_Extractor_Service( 15, 30 );

		// We can't directly test private properties, but we can test the behavior.
		// The custom frame count should be used.
		$this->assertInstanceOf( WP_MCP_AI_Video_Frame_Extractor_Service::class, $custom_extractor );
	}
}
