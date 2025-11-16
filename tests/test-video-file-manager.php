<?php
/**
 * Tests for Video File Manager Service.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Video File Manager functionality.
 */
class Test_Video_File_Manager extends WP_UnitTestCase {
	/**
	 * Video File Manager instance.
	 *
	 * @var WP_MCP_AI_Video_File_Manager
	 */
	protected $manager;

	/**
	 * Mock Gemini File Service.
	 *
	 * @var WP_MCP_AI_Gemini_File_Service
	 */
	protected $mock_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-file-manager.php';

		// Create a mock service for testing.
		$this->mock_service = $this->createMock( WP_MCP_AI_Gemini_File_Service::class );
		$this->manager      = new WP_MCP_AI_Video_File_Manager( $this->mock_service );

		// Clear registry before each test.
		$this->manager->clear_registry();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up registry after tests.
		$this->manager->clear_registry();

		parent::tearDown();
	}

	/**
	 * Test that service class exists.
	 */
	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Video_File_Manager' ), 'WP_MCP_AI_Video_File_Manager class should exist' );
	}

	/**
	 * Test service can be instantiated.
	 */
	public function test_service_instantiation() {
		$this->assertInstanceOf( WP_MCP_AI_Video_File_Manager::class, $this->manager, 'Service should be instantiable' );
	}

	/**
	 * Test generate_video_hash with valid file.
	 */
	public function test_generate_video_hash_valid_file() {
		// Create a temporary test file.
		$temp_file = wp_tempnam( 'test' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_file, 'test video content' );

		$hash = $this->manager->generate_video_hash( $temp_file );

		// Clean up.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $temp_file );

		$this->assertNotWPError( $hash, 'Hash generation should succeed for valid file' );
		$this->assertIsString( $hash, 'Hash should be a string' );
		$this->assertEquals( 32, strlen( $hash ), 'MD5 hash should be 32 characters' );
	}

	/**
	 * Test generate_video_hash with nonexistent file.
	 */
	public function test_generate_video_hash_nonexistent_file() {
		$result = $this->manager->generate_video_hash( '/nonexistent/file.mp4' );

		$this->assertWPError( $result, 'Should return WP_Error for nonexistent file' );
		$this->assertEquals( 'wp_mcp_ai_file_not_found', $result->get_error_code(), 'Should return file not found error' );
	}

	/**
	 * Test register_file adds file to registry.
	 */
	public function test_register_file() {
		$video_hash  = 'test_hash_123';
		$upload_data = array(
			'file_name' => 'files/abc123',
			'file_uri'  => 'https://example.com/files/abc123',
			'mime_type' => 'video/mp4',
		);

		$result = $this->manager->register_file( $video_hash, $upload_data );

		$this->assertTrue( $result, 'File registration should succeed' );

		$cached = $this->manager->get_cached_file( $video_hash );
		$this->assertNotFalse( $cached, 'Registered file should be in cache' );
		$this->assertEquals( $upload_data['file_name'], $cached['file_name'], 'File name should match' );
		$this->assertEquals( $upload_data['file_uri'], $cached['file_uri'], 'File URI should match' );
	}

	/**
	 * Test register_file with metadata.
	 */
	public function test_register_file_with_metadata() {
		$video_hash  = 'test_hash_456';
		$upload_data = array(
			'file_name' => 'files/xyz789',
			'file_uri'  => 'https://example.com/files/xyz789',
			'mime_type' => 'video/mp4',
		);
		$metadata    = array(
			'attachment_id' => 123,
			'video_url'     => 'https://example.com/video.mp4',
		);

		$result = $this->manager->register_file( $video_hash, $upload_data, $metadata );

		$this->assertTrue( $result, 'File registration with metadata should succeed' );

		$cached = $this->manager->get_cached_file( $video_hash );
		$this->assertArrayHasKey( 'metadata', $cached, 'Cached file should have metadata' );
		$this->assertEquals( $metadata, $cached['metadata'], 'Metadata should match' );
	}

	/**
	 * Test get_cached_file returns false for nonexistent hash.
	 */
	public function test_get_cached_file_nonexistent() {
		$cached = $this->manager->get_cached_file( 'nonexistent_hash' );

		$this->assertFalse( $cached, 'Should return false for nonexistent hash' );
	}

	/**
	 * Test touch_file updates last_used_at timestamp.
	 */
	public function test_touch_file() {
		$video_hash  = 'test_hash_789';
		$upload_data = array(
			'file_name' => 'files/def456',
			'file_uri'  => 'https://example.com/files/def456',
			'mime_type' => 'video/mp4',
		);

		$this->manager->register_file( $video_hash, $upload_data );

		// Get initial timestamps.
		$cached_before = $this->manager->get_cached_file( $video_hash );
		$uploaded_at   = $cached_before['uploaded_at'];
		$expiry_before = $cached_before['expiry_time'];

		// Wait a moment.
		sleep( 1 );

		// Touch the file.
		$result = $this->manager->touch_file( $video_hash );

		$this->assertTrue( $result, 'Touch should succeed' );

		// Check timestamps were updated.
		$cached_after = $this->manager->get_cached_file( $video_hash );
		$this->assertEquals( $uploaded_at, $cached_after['uploaded_at'], 'Upload timestamp should not change' );
		$this->assertGreaterThan( $cached_before['last_used_at'], $cached_after['last_used_at'], 'Last used timestamp should be updated' );
		$this->assertGreaterThan( $expiry_before, $cached_after['expiry_time'], 'Expiry time should be extended' );
	}

	/**
	 * Test touch_file returns false for nonexistent file.
	 */
	public function test_touch_file_nonexistent() {
		$result = $this->manager->touch_file( 'nonexistent_hash' );

		$this->assertFalse( $result, 'Touch should return false for nonexistent file' );
	}

	/**
	 * Test unregister_file removes file from registry.
	 */
	public function test_unregister_file() {
		$video_hash  = 'test_hash_unregister';
		$upload_data = array(
			'file_name' => 'files/unregister123',
			'file_uri'  => 'https://example.com/files/unregister123',
			'mime_type' => 'video/mp4',
		);

		$this->manager->register_file( $video_hash, $upload_data );

		// Verify file is registered.
		$cached = $this->manager->get_cached_file( $video_hash );
		$this->assertNotFalse( $cached, 'File should be registered' );

		// Unregister.
		$result = $this->manager->unregister_file( $video_hash );

		$this->assertTrue( $result, 'Unregister should succeed' );

		// Verify file is removed.
		$cached_after = $this->manager->get_cached_file( $video_hash );
		$this->assertFalse( $cached_after, 'File should be removed from cache' );
	}

	/**
	 * Test get_all_files returns all registered files.
	 */
	public function test_get_all_files() {
		// Register multiple files.
		$hash1 = 'hash1';
		$hash2 = 'hash2';

		$this->manager->register_file(
			$hash1,
			array(
				'file_name' => 'files/file1',
				'file_uri'  => 'https://example.com/files/file1',
				'mime_type' => 'video/mp4',
			)
		);

		$this->manager->register_file(
			$hash2,
			array(
				'file_name' => 'files/file2',
				'file_uri'  => 'https://example.com/files/file2',
				'mime_type' => 'video/mp4',
			)
		);

		$all_files = $this->manager->get_all_files();

		$this->assertIsArray( $all_files, 'Should return array' );
		$this->assertCount( 2, $all_files, 'Should have 2 files' );
		$this->assertArrayHasKey( $hash1, $all_files, 'Should have hash1' );
		$this->assertArrayHasKey( $hash2, $all_files, 'Should have hash2' );
	}

	/**
	 * Test get_statistics returns correct stats.
	 */
	public function test_get_statistics() {
		// Register a file.
		$this->manager->register_file(
			'stats_hash',
			array(
				'file_name' => 'files/stats',
				'file_uri'  => 'https://example.com/files/stats',
				'mime_type' => 'video/mp4',
			)
		);

		$stats = $this->manager->get_statistics();

		$this->assertIsArray( $stats, 'Should return array' );
		$this->assertArrayHasKey( 'total_files', $stats, 'Should have total_files' );
		$this->assertArrayHasKey( 'active_files', $stats, 'Should have active_files' );
		$this->assertArrayHasKey( 'expired_files', $stats, 'Should have expired_files' );
		$this->assertEquals( 1, $stats['total_files'], 'Should have 1 total file' );
		$this->assertEquals( 1, $stats['active_files'], 'Should have 1 active file' );
		$this->assertEquals( 0, $stats['expired_files'], 'Should have 0 expired files' );
	}

	/**
	 * Test cleanup_expired_files removes only expired files.
	 */
	public function test_cleanup_expired_files() {
		// Register an expired file (manipulate expiry time).
		$expired_hash = 'expired_hash';
		$upload_data  = array(
			'file_name' => 'files/expired',
			'file_uri'  => 'https://example.com/files/expired',
			'mime_type' => 'video/mp4',
		);

		$this->manager->register_file( $expired_hash, $upload_data );

		// Manually set expiry to past.
		$registry                          = $this->manager->get_all_files();
		$registry[ $expired_hash ]['expiry_time'] = time() - 1000;
		update_option( WP_MCP_AI_Video_File_Manager::REGISTRY_OPTION, $registry, false );

		// Mock the delete_file method to return success.
		$this->mock_service->method( 'delete_file' )->willReturn( true );

		// Run cleanup.
		$results = $this->manager->cleanup_expired_files();

		$this->assertIsArray( $results, 'Should return array' );
		$this->assertEquals( 1, $results['total_checked'], 'Should check 1 file' );
		$this->assertEquals( 1, $results['deleted'], 'Should delete 1 file' );
		$this->assertEquals( 0, $results['failed'], 'Should have 0 failures' );

		// Verify file was removed.
		$cached = $this->manager->get_cached_file( $expired_hash );
		$this->assertFalse( $cached, 'Expired file should be removed' );
	}

	/**
	 * Test cleanup_expired_files handles deletion errors.
	 */
	public function test_cleanup_expired_files_with_errors() {
		// Register an expired file.
		$expired_hash = 'expired_error_hash';
		$upload_data  = array(
			'file_name' => 'files/expired_error',
			'file_uri'  => 'https://example.com/files/expired_error',
			'mime_type' => 'video/mp4',
		);

		$this->manager->register_file( $expired_hash, $upload_data );

		// Manually set expiry to past.
		$registry                          = $this->manager->get_all_files();
		$registry[ $expired_hash ]['expiry_time'] = time() - 1000;
		update_option( WP_MCP_AI_Video_File_Manager::REGISTRY_OPTION, $registry, false );

		// Mock the delete_file method to return error.
		$this->mock_service->method( 'delete_file' )->willReturn(
			new WP_Error( 'deletion_failed', 'Failed to delete file' )
		);

		// Run cleanup.
		$results = $this->manager->cleanup_expired_files();

		$this->assertIsArray( $results, 'Should return array' );
		$this->assertEquals( 1, $results['total_checked'], 'Should check 1 file' );
		$this->assertEquals( 0, $results['deleted'], 'Should delete 0 files' );
		$this->assertEquals( 1, $results['failed'], 'Should have 1 failure' );
		$this->assertNotEmpty( $results['errors'], 'Should have error details' );

		// Verify file was still removed from registry despite API error.
		$cached = $this->manager->get_cached_file( $expired_hash );
		$this->assertFalse( $cached, 'File should be removed from registry even if API deletion failed' );
	}

	/**
	 * Test clear_registry removes all data.
	 */
	public function test_clear_registry() {
		// Register files.
		$this->manager->register_file(
			'clear1',
			array(
				'file_name' => 'files/clear1',
				'file_uri'  => 'https://example.com/files/clear1',
				'mime_type' => 'video/mp4',
			)
		);

		$this->manager->register_file(
			'clear2',
			array(
				'file_name' => 'files/clear2',
				'file_uri'  => 'https://example.com/files/clear2',
				'mime_type' => 'video/mp4',
			)
		);

		// Verify files exist.
		$all_files = $this->manager->get_all_files();
		$this->assertCount( 2, $all_files, 'Should have 2 files before clearing' );

		// Clear registry.
		$result = $this->manager->clear_registry();

		$this->assertTrue( $result, 'Clear registry should succeed' );

		// Verify all files removed.
		$all_files_after = $this->manager->get_all_files();
		$this->assertCount( 0, $all_files_after, 'Should have 0 files after clearing' );
	}

	/**
	 * Test that cached file is invalidated when expired.
	 */
	public function test_cached_file_invalidated_when_expired() {
		$video_hash  = 'expiry_test_hash';
		$upload_data = array(
			'file_name' => 'files/expiry_test',
			'file_uri'  => 'https://example.com/files/expiry_test',
			'mime_type' => 'video/mp4',
		);

		$this->manager->register_file( $video_hash, $upload_data );

		// Verify file is cached.
		$cached = $this->manager->get_cached_file( $video_hash );
		$this->assertNotFalse( $cached, 'File should be cached initially' );

		// Manually expire the file.
		$registry                        = $this->manager->get_all_files();
		$registry[ $video_hash ]['expiry_time'] = time() - 1;
		update_option( WP_MCP_AI_Video_File_Manager::REGISTRY_OPTION, $registry, false );

		// Try to get cached file - should return false.
		$cached_after = $this->manager->get_cached_file( $video_hash );
		$this->assertFalse( $cached_after, 'Expired file should not be returned from cache' );
	}
}
