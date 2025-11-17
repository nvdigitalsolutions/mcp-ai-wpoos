<?php
/**
 * Tests for File Resource Manager.
 *
 * @package WP_MCP_AI
 */

/**
 * Test File Resource Manager functionality.
 */
class Test_File_Resource_Manager extends WP_UnitTestCase {
	/**
	 * Video File Manager instance.
	 *
	 * @var WP_MCP_AI_Video_File_Manager
	 */
	protected $video_manager;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure path constant is available in tests/bootstrap.php; adjust if necessary.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			define( 'WP_MCP_AI_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once WP_MCP_AI_PATH . 'includes/resources/class-wp-mcp-ai-file-resource-manager.php';
		require_once WP_MCP_AI_PATH . 'includes/resources/class-wp-mcp-ai-video-file-manager.php';

		$this->video_manager = new WP_MCP_AI_Video_File_Manager();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		if ( $this->video_manager ) {
			if ( method_exists( $this->video_manager, 'clear_all' ) ) {
				$this->video_manager->clear_all();
			}
		}

		parent::tearDown();
	}

	/**
	 * Test that base manager class exists.
	 */
	public function test_base_manager_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_File_Resource_Manager' ), 'Base resource manager class should exist' );
	}

	/**
	 * Test that video manager class exists.
	 */
	public function test_video_manager_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Video_File_Manager' ), 'Video file manager class should exist' );
	}

	/**
	 * Test video manager can be instantiated.
	 */
	public function test_video_manager_instantiation() {
		$this->assertInstanceOf( WP_MCP_AI_Video_File_Manager::class, $this->video_manager, 'Video manager should be instantiable' );
	}

	/**
	 * Test video manager extends base manager.
	 */
	public function test_video_manager_extends_base() {
		$this->assertInstanceOf( WP_MCP_AI_File_Resource_Manager::class, $this->video_manager, 'Video manager should extend base manager' );
	}

	/**
	 * Test file type is set correctly.
	 */
	public function test_file_type() {
		$this->assertEquals( 'video', $this->video_manager->get_file_type(), 'File type should be "video"' );
	}

	/**
	 * Test file tracking.
	 */
	public function test_track_file() {
		$file_id  = 'test_video_123';
		$metadata = array(
			'file_name' => 'files/abc123',
			'file_url'  => 'https://example.com/files/abc123',
			'source'    => 'https://example.com/video.mp4',
		);

		$result = $this->video_manager->track_file( $file_id, $metadata );
		$this->assertTrue( $result, 'File tracking should succeed' );

		$tracked = $this->video_manager->get_tracked_file( $file_id );
		$this->assertNotNull( $tracked, 'Tracked file should be retrievable' );
		$this->assertEquals( 'files/abc123', $tracked['file_name'], 'File name should match' );
		$this->assertEquals( 'video', $tracked['file_type'], 'File type should be added' );
		$this->assertArrayHasKey( 'tracked_at', $tracked, 'Tracked at timestamp should be added' );
	}

	/**
	 * Test file untracking.
	 */
	public function test_untrack_file() {
		$file_id  = 'test_video_456';
		$metadata = array(
			'file_name' => 'files/def456',
			'file_url'  => 'https://example.com/files/def456',
		);

		$this->video_manager->track_file( $file_id, $metadata );
		$this->assertNotNull( $this->video_manager->get_tracked_file( $file_id ), 'File should be tracked' );

		$result = $this->video_manager->untrack_file( $file_id );
		$this->assertTrue( $result, 'File untracking should succeed' );
		$this->assertNull( $this->video_manager->get_tracked_file( $file_id ), 'File should be untracked' );
	}

	/**
	 * Test cache checking.
	 */
	public function test_is_file_cached() {
		$file_id  = 'test_video_789';
		$metadata = array(
			'file_name' => 'files/ghi789',
			'file_url'  => 'https://example.com/files/ghi789',
		);

		// Not tracked yet.
		$this->assertFalse( $this->video_manager->is_file_cached( $file_id ), 'Untracked file should not be cached' );

		// Track the file.
		$this->video_manager->track_file( $file_id, $metadata );
		$this->assertTrue( $this->video_manager->is_file_cached( $file_id ), 'Recently tracked file should be cached' );
	}

	/**
	 * Test get cached file.
	 */
	public function test_get_cached_file() {
		$file_id  = 'test_video_101';
		$metadata = array(
			'file_name' => 'files/jkl101',
			'file_url'  => 'https://example.com/files/jkl101',
			'source'    => 'attachment:123',
		);

		// Not cached initially.
		$this->assertNull( $this->video_manager->get_cached_file( $file_id ), 'Uncached file should return null' );

		// Track the file.
		$this->video_manager->track_file( $file_id, $metadata );

		// Should be cached now.
		$cached = $this->video_manager->get_cached_file( $file_id );
		$this->assertNotNull( $cached, 'Cached file should be retrievable' );
		$this->assertEquals( 'files/jkl101', $cached['file_name'], 'Cached file name should match' );
		$this->assertEquals( 'attachment:123', $cached['source'], 'Cached file source should match' );
	}

	/**
	 * Test statistics generation.
	 */
	public function test_get_statistics() {
		// Track multiple files.
		$this->video_manager->track_file( 'file1', array( 'file_name' => 'files/1' ) );
		$this->video_manager->track_file( 'file2', array( 'file_name' => 'files/2' ) );
		$this->video_manager->track_file( 'file3', array( 'file_name' => 'files/3' ) );

		$stats = $this->video_manager->get_statistics();

		$this->assertIsArray( $stats, 'Statistics should be an array' );
		$this->assertEquals( 'video', $stats['file_type'], 'File type should be video' );
		$this->assertEquals( 3, $stats['total_files'], 'Total files should be 3' );
		$this->assertEquals( 3, $stats['cached_files'], 'All files should be cached' );
		$this->assertEquals( 0, $stats['expired_files'], 'No files should be expired' );
		$this->assertEquals( 100, $stats['cache_hit_rate'], 'Cache hit rate should be 100%' );
	}

	/**
	 * Test cache duration setting.
	 */
	public function test_set_cache_duration() {
		$this->video_manager->set_cache_duration( 3600 ); // 1 hour.

		// Track a file.
		$file_id = 'test_duration';
		$this->video_manager->track_file( $file_id, array( 'file_name' => 'files/duration' ) );

		// Should be cached within 1 hour.
		$this->assertTrue( $this->video_manager->is_file_cached( $file_id ), 'File should be cached' );
	}

	/**
	 * Test max file age setting.
	 */
	public function test_set_max_file_age() {
		$this->video_manager->set_max_file_age( 86400 ); // 1 day.

		// This just tests the setter - actual expiration logic would need time manipulation.
		$stats = $this->video_manager->get_statistics();
		$this->assertIsArray( $stats, 'Statistics should still work after setting max age' );
	}

	/**
	 * Test get all tracked files.
	 */
	public function test_get_tracked_files() {
		$this->video_manager->track_file( 'file1', array( 'file_name' => 'files/1' ) );
		$this->video_manager->track_file( 'file2', array( 'file_name' => 'files/2' ) );

		$files = $this->video_manager->get_tracked_files();

		$this->assertIsArray( $files, 'Tracked files should be an array' );
		$this->assertCount( 2, $files, 'Should have 2 tracked files' );
		$this->assertArrayHasKey( 'file1', $files, 'Should have file1' );
		$this->assertArrayHasKey( 'file2', $files, 'Should have file2' );
	}

	/**
	 * Test clear all tracked files.
	 */
	public function test_clear_all() {
		$this->video_manager->track_file( 'file1', array( 'file_name' => 'files/1' ) );
		$this->video_manager->track_file( 'file2', array( 'file_name' => 'files/2' ) );

		$this->assertCount( 2, $this->video_manager->get_tracked_files(), 'Should have 2 files before clear' );

		$result = $this->video_manager->clear_all();
		$this->assertTrue( $result, 'Clear all should succeed' );
		$this->assertCount( 0, $this->video_manager->get_tracked_files(), 'Should have 0 files after clear' );
	}
}
