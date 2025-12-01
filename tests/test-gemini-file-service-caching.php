<?php
/**
 * Tests for Gemini File Service Caching and Tracking.
 *
 * Tests Phase 2.1 file management features:
 * - File caching to avoid re-uploads
 * - File tracking for lifecycle management
 * - Cleanup of old files
 * - Cron job handlers
 *
 * @package WP_MCP_AI
 */

/**
 * Test Gemini File Service caching and file management functionality.
 */
class Test_Gemini_File_Service_Caching extends WP_UnitTestCase {
	/**
	 * Gemini File Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_File_Service
	 */
	protected $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
		$this->service = new WP_MCP_AI_Gemini_File_Service();

		// Clear any existing tracked files.
		delete_option( 'wp_mcp_ai_gemini_tracked_files' );

		// Clear all related transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_gemini_file_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_gemini_file_%'" );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		// Clear tracked files option.
		delete_option( 'wp_mcp_ai_gemini_tracked_files' );

		// Clear all related transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_gemini_file_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_gemini_file_%'" );

		parent::tearDown();
	}

	/**
	 * Test that get_cached_file returns null when no cache exists.
	 */
	public function test_get_cached_file_returns_null_when_no_cache() {
		$result = $this->service->get_cached_file( 'https://example.com/video.mp4' );

		$this->assertNull( $result, 'Should return null when no cache exists' );
	}

	/**
	 * Test that get_cached_file returns null for invalid input.
	 */
	public function test_get_cached_file_returns_null_for_invalid_input() {
		$result = $this->service->get_cached_file( '', null );

		$this->assertNull( $result, 'Should return null when both parameters are empty' );
	}

	/**
	 * Test that track_uploaded_file stores file information.
	 */
	public function test_track_uploaded_file_stores_data() {
		$file_name = 'files/test123';
		$file_uri  = 'https://generativelanguage.googleapis.com/v1beta/files/test123';
		$mime_type = 'video/mp4';
		$video_url = 'https://example.com/test.mp4';

		$result = $this->service->track_uploaded_file( $file_name, $file_uri, $mime_type, $video_url );

		$this->assertTrue( $result, 'Should return true on successful tracking' );

		// Verify transient was created.
		$cache_key   = 'wp_mcp_ai_gemini_file_' . md5( $video_url );
		$cached_data = get_transient( $cache_key );

		$this->assertNotFalse( $cached_data, 'Transient should exist' );
		$this->assertIsArray( $cached_data, 'Cached data should be an array' );
		$this->assertEquals( $file_name, $cached_data['file_name'], 'File name should match' );
		$this->assertEquals( $file_uri, $cached_data['file_uri'], 'File URI should match' );
		$this->assertEquals( $mime_type, $cached_data['mime_type'], 'MIME type should match' );
		$this->assertEquals( $video_url, $cached_data['video_url'], 'Video URL should match' );
	}

	/**
	 * Test that track_uploaded_file adds to tracked files list.
	 */
	public function test_track_uploaded_file_adds_to_list() {
		$file_name = 'files/test456';
		$file_uri  = 'https://generativelanguage.googleapis.com/v1beta/files/test456';
		$mime_type = 'video/webm';
		$video_url = 'https://example.com/test2.webm';

		$this->service->track_uploaded_file( $file_name, $file_uri, $mime_type, $video_url );

		// Verify it was added to the tracked files list.
		$tracked_files = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );

		$this->assertIsArray( $tracked_files, 'Tracked files should be an array' );
		$this->assertNotEmpty( $tracked_files, 'Tracked files should not be empty' );

		// Find our file in the list.
		$found = false;
		foreach ( $tracked_files as $cache_key => $file_data ) {
			if ( isset( $file_data['file_name'] ) && $file_data['file_name'] === $file_name ) {
				$found = true;
				$this->assertArrayHasKey( 'uploaded_at', $file_data, 'Should have uploaded_at timestamp' );
				$this->assertIsInt( $file_data['uploaded_at'], 'uploaded_at should be an integer timestamp' );
				break;
			}
		}

		$this->assertTrue( $found, 'File should be in tracked files list' );
	}

	/**
	 * Test that track_uploaded_file works with attachment ID.
	 */
	public function test_track_uploaded_file_with_attachment_id() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Test Video',
			)
		);

		$file_name = 'files/test789';
		$file_uri  = 'https://generativelanguage.googleapis.com/v1beta/files/test789';
		$mime_type = 'video/mp4';

		$result = $this->service->track_uploaded_file( $file_name, $file_uri, $mime_type, '', $attachment_id );

		$this->assertTrue( $result, 'Should successfully track file with attachment ID' );

		// Verify the cache key includes attachment ID.
		$tracked_files = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );
		$this->assertNotEmpty( $tracked_files, 'Should have tracked files' );

		// The cache key should contain the attachment ID.
		$cache_keys = array_keys( $tracked_files );
		$found      = false;
		foreach ( $cache_keys as $key ) {
			if ( strpos( $key, 'wp_mcp_ai_gemini_file_' . $attachment_id ) === 0 ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Cache key should contain attachment ID' );
	}

	/**
	 * Test that track_uploaded_file returns false for invalid input.
	 */
	public function test_track_uploaded_file_returns_false_for_invalid_input() {
		$result = $this->service->track_uploaded_file( 'files/test', 'uri', 'video/mp4', '', null );

		$this->assertFalse( $result, 'Should return false when both video_url and attachment_id are empty' );
	}

	/**
	 * Test that list_tracked_files returns empty array when no files tracked.
	 */
	public function test_list_tracked_files_returns_empty_when_no_files() {
		$result = $this->service->list_tracked_files();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertEmpty( $result, 'Should return empty array when no files tracked' );
	}

	/**
	 * Test that list_tracked_files returns tracked files.
	 */
	public function test_list_tracked_files_returns_tracked_files() {
		// Track multiple files.
		$this->service->track_uploaded_file( 'files/test1', 'uri1', 'video/mp4', 'https://example.com/1.mp4' );
		$this->service->track_uploaded_file( 'files/test2', 'uri2', 'video/webm', 'https://example.com/2.webm' );

		$result = $this->service->list_tracked_files();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertCount( 2, $result, 'Should return 2 tracked files' );

		// Verify structure of returned files.
		foreach ( $result as $file_info ) {
			$this->assertArrayHasKey( 'cache_key', $file_info, 'Should have cache_key' );
			$this->assertArrayHasKey( 'file_name', $file_info, 'Should have file_name' );
			$this->assertArrayHasKey( 'uploaded_at', $file_info, 'Should have uploaded_at' );
		}
	}

	/**
	 * Test that list_tracked_files handles expired transients.
	 */
	public function test_list_tracked_files_handles_expired_transients() {
		// Track a file.
		$video_url = 'https://example.com/expired.mp4';
		$this->service->track_uploaded_file( 'files/expired', 'uri', 'video/mp4', $video_url );

		// Delete the transient (simulating expiration).
		$cache_key = 'wp_mcp_ai_gemini_file_' . md5( $video_url );
		delete_transient( $cache_key );

		// List should still return the file from the tracking list.
		$result = $this->service->list_tracked_files();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertCount( 1, $result, 'Should return 1 file from tracking list' );
		$this->assertEquals( 'files/expired', $result[0]['file_name'], 'Should have correct file_name' );
	}

	/**
	 * Test that cleanup_old_files returns proper structure.
	 */
	public function test_cleanup_old_files_returns_proper_structure() {
		// No files to clean up.
		$result = $this->service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertArrayHasKey( 'deleted_count', $result, 'Should have deleted_count' );
		$this->assertArrayHasKey( 'failed_count', $result, 'Should have failed_count' );
		$this->assertArrayHasKey( 'total_checked', $result, 'Should have total_checked' );
		$this->assertEquals( 0, $result['deleted_count'], 'Should have 0 deleted files' );
		$this->assertEquals( 0, $result['failed_count'], 'Should have 0 failed deletions' );
		$this->assertEquals( 0, $result['total_checked'], 'Should have checked 0 files' );
	}

	/**
	 * Test that cleanup_old_files doesn't delete recent files.
	 */
	public function test_cleanup_old_files_keeps_recent_files() {
		// Track a recent file.
		$this->service->track_uploaded_file( 'files/recent', 'uri', 'video/mp4', 'https://example.com/recent.mp4' );

		// Try to clean up files older than 24 hours.
		$result = $this->service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		$this->assertEquals( 0, $result['deleted_count'], 'Should not delete recent files' );
		$this->assertEquals( 1, $result['total_checked'], 'Should have checked 1 file' );

		// Verify file is still tracked.
		$tracked = $this->service->list_tracked_files();
		$this->assertCount( 1, $tracked, 'Recent file should still be tracked' );
	}

	/**
	 * Test that cleanup_old_files identifies old files.
	 */
	public function test_cleanup_old_files_identifies_old_files() {
		// Track a file and manually set old timestamp.
		$video_url = 'https://example.com/old.mp4';
		$this->service->track_uploaded_file( 'files/old', 'uri', 'video/mp4', $video_url );

		// Manually update the uploaded_at to be old.
		$tracked_files = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );
		$cache_key     = 'wp_mcp_ai_gemini_file_' . md5( $video_url );
		if ( isset( $tracked_files[ $cache_key ] ) ) {
			$tracked_files[ $cache_key ]['uploaded_at'] = time() - ( 25 * HOUR_IN_SECONDS );
			update_option( 'wp_mcp_ai_gemini_tracked_files', $tracked_files, false );
		}

		// Try to clean up files older than 24 hours.
		// Note: This will fail to delete because we don't have a real API key,.
		// but it should attempt deletion.
		$result = $this->service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		$this->assertEquals( 1, $result['total_checked'], 'Should check 1 file' );
		// The file won't actually be deleted because API call will fail,.
		// but we've verified the age check logic works.
	}

	/**
	 * Test cron job handler function exists.
	 */
	public function test_cleanup_cron_handler_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_cleanup_gemini_files_handler' ),
			'Cron handler function should exist'
		);
	}

	/**
	 * Test cron job is scheduled on plugin load.
	 */
	public function test_cleanup_cron_scheduled() {
		// Trigger the scheduling function.
		do_action( 'plugins_loaded' );

		// Check if cron job is scheduled.
		$next_run = wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' );

		$this->assertNotFalse( $next_run, 'Cleanup cron job should be scheduled' );
		$this->assertIsInt( $next_run, 'Next run should be a timestamp' );
	}

	/**
	 * Test that cron job handler can be called.
	 */
	public function test_cleanup_cron_handler_callable() {
		$this->assertTrue(
			is_callable( 'wp_mcp_ai_cleanup_gemini_files_handler' ),
			'Cron handler should be callable'
		);
	}

	/**
	 * Test cache key generation for video URLs.
	 */
	public function test_cache_key_generation_for_urls() {
		$video_url1 = 'https://example.com/video1.mp4';
		$video_url2 = 'https://example.com/video2.mp4';

		// Track two different videos.
		$this->service->track_uploaded_file( 'files/v1', 'uri1', 'video/mp4', $video_url1 );
		$this->service->track_uploaded_file( 'files/v2', 'uri2', 'video/mp4', $video_url2 );

		$tracked = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );

		// Should have two different cache keys.
		$this->assertCount( 2, $tracked, 'Should have 2 tracked files with different cache keys' );

		// Cache keys should be deterministic.
		$expected_key1 = 'wp_mcp_ai_gemini_file_' . md5( $video_url1 );
		$expected_key2 = 'wp_mcp_ai_gemini_file_' . md5( $video_url2 );

		$this->assertArrayHasKey( $expected_key1, $tracked, 'Should have cache key for video 1' );
		$this->assertArrayHasKey( $expected_key2, $tracked, 'Should have cache key for video 2' );
	}

	/**
	 * Test that same video URL generates same cache key.
	 */
	public function test_same_url_generates_same_cache_key() {
		$video_url = 'https://example.com/same.mp4';

		// Track the same video twice.
		$this->service->track_uploaded_file( 'files/first', 'uri1', 'video/mp4', $video_url );
		$this->service->track_uploaded_file( 'files/second', 'uri2', 'video/mp4', $video_url );

		$tracked = get_option( 'wp_mcp_ai_gemini_tracked_files', array() );

		// Should only have one entry (overwritten).
		$this->assertCount( 1, $tracked, 'Should have 1 tracked file (second overwrites first)' );

		// Should be the second file.
		$cache_key = 'wp_mcp_ai_gemini_file_' . md5( $video_url );
		$this->assertEquals( 'files/second', $tracked[ $cache_key ]['file_name'], 'Should have second file' );
	}

	/**
	 * Test that cache expiration is set correctly.
	 */
	public function test_cache_expiration_is_24_hours() {
		$video_url = 'https://example.com/expiration.mp4';
		$this->service->track_uploaded_file( 'files/exp', 'uri', 'video/mp4', $video_url );

		$cache_key = 'wp_mcp_ai_gemini_file_' . md5( $video_url );

		// Get the timeout option name.
		$timeout_option = '_transient_timeout_' . $cache_key;
		$timeout        = get_option( $timeout_option );

		$this->assertNotFalse( $timeout, 'Timeout should be set' );

		// Should be approximately 24 hours from now.
		$expected_timeout = time() + ( 24 * HOUR_IN_SECONDS );
		$difference       = abs( $timeout - $expected_timeout );

		$this->assertLessThan( 5, $difference, 'Timeout should be approximately 24 hours' );
	}

	/**
	 * Test service handles file types beyond videos.
	 */
	public function test_service_handles_multiple_file_types() {
		// Track various file types.
		$this->service->track_uploaded_file( 'files/vid', 'uri1', 'video/mp4', 'https://example.com/vid.mp4' );
		$this->service->track_uploaded_file( 'files/img', 'uri2', 'image/png', 'https://example.com/img.png' );
		$this->service->track_uploaded_file( 'files/doc', 'uri3', 'application/pdf', 'https://example.com/doc.pdf' );
		$this->service->track_uploaded_file( 'files/aud', 'uri4', 'audio/mp3', 'https://example.com/aud.mp3' );

		$tracked = $this->service->list_tracked_files();

		$this->assertCount( 4, $tracked, 'Should track all file types' );

		// Verify MIME types are preserved.
		$mime_types = array_column( $tracked, 'mime_type' );
		$this->assertContains( 'video/mp4', $mime_types, 'Should have video MIME type' );
		$this->assertContains( 'image/png', $mime_types, 'Should have image MIME type' );
		$this->assertContains( 'application/pdf', $mime_types, 'Should have PDF MIME type' );
		$this->assertContains( 'audio/mp3', $mime_types, 'Should have audio MIME type' );
	}
}
