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
t::setUp();

ce WP_MCP_AI_PATH . 'includes/resources/class-wp-mcp-ai-file-resource-manager.php';
ce WP_MCP_AI_PATH . 'includes/resources/class-wp-mcp-ai-video-file-manager.php';

ager = new WP_MCP_AI_Video_File_Manager();
}

/**
 * Tear down test environment.
 */
public function tearDown(): void {
Clean up tracked files.
( $this->video_manager ) {
ager->clear_all();
t::tearDown();
}

/**
 * Test that base manager class exists.
 */
public function test_base_manager_class_exists() {
class_exists( 'WP_MCP_AI_File_Resource_Manager' ), 'Base resource manager class should exist' );
}

/**
 * Test that video manager class exists.
 */
public function test_video_manager_class_exists() {
class_exists( 'WP_MCP_AI_Video_File_Manager' ), 'Video file manager class should exist' );
}

/**
 * Test video manager can be instantiated.
 */
public function test_video_manager_instantiation() {
stanceOf( WP_MCP_AI_Video_File_Manager::class, $this->video_manager, 'Video manager should be instantiable' );
}

/**
 * Test video manager extends base manager.
 */
public function test_video_manager_extends_base() {
stanceOf( WP_MCP_AI_File_Resource_Manager::class, $this->video_manager, 'Video manager should extend base manager' );
}

/**
 * Test file type is set correctly.
 */
public function test_file_type() {
'video', $this->video_manager->get_file_type(), 'File type should be "video"' );
}

/**
 * Test file tracking.
 */
public function test_track_file() {
= 'test_video_123';
= array(
ame' => 'files/abc123',
=> 'https://example.com/files/abc123',
=> 'https://example.com/video.mp4',
= $this->video_manager->track_file( $file_id, $metadata );
$result, 'File tracking should succeed' );

= $this->video_manager->get_tracked_file( $file_id );
otNull( $tracked, 'Tracked file should be retrievable' );
'files/abc123', $tracked['file_name'], 'File name should match' );
'video', $tracked['file_type'], 'File type should be added' );
HasKey( 'tracked_at', $tracked, 'Tracked at timestamp should be added' );
}

/**
 * Test file untracking.
 */
public function test_untrack_file() {
= 'test_video_456';
= array(
ame' => 'files/def456',
=> 'https://example.com/files/def456',
ager->track_file( $file_id, $metadata );
otNull( $this->video_manager->get_tracked_file( $file_id ), 'File should be tracked' );

= $this->video_manager->untrack_file( $file_id );
$result, 'File untracking should succeed' );
ull( $this->video_manager->get_tracked_file( $file_id ), 'File should be untracked' );
}

/**
 * Test cache checking.
 */
public function test_is_file_cached() {
= 'test_video_789';
= array(
ame' => 'files/ghi789',
=> 'https://example.com/files/ghi789',
File not tracked yet.
$this->video_manager->is_file_cached( $file_id ), 'Untracked file should not be cached' );

Track the file.
ager->track_file( $file_id, $metadata );
$this->video_manager->is_file_cached( $file_id ), 'Recently tracked file should be cached' );
}

/**
 * Test get cached file.
 */
public function test_get_cached_file() {
= 'test_video_101';
= array(
ame' => 'files/jkl101',
=> 'https://example.com/files/jkl101',
=> 'attachment:123',
Not cached initially.
ull( $this->video_manager->get_cached_file( $file_id ), 'Uncached file should return null' );

Track the file.
ager->track_file( $file_id, $metadata );

Should be cached now.
= $this->video_manager->get_cached_file( $file_id );
otNull( $cached, 'Cached file should be retrievable' );
'files/jkl101', $cached['file_name'], 'Cached file name should match' );
'attachment:123', $cached['source'], 'Cached file source should match' );
}

/**
 * Test statistics generation.
 */
public function test_get_statistics() {
Track multiple files.
ager->track_file( 'file1', array( 'file_name' => 'files/1' ) );
ager->track_file( 'file2', array( 'file_name' => 'files/2' ) );
ager->track_file( 'file3', array( 'file_name' => 'files/3' ) );

= $this->video_manager->get_statistics();

( $stats, 'Statistics should be an array' );
'video', $stats['file_type'], 'File type should be video' );
3, $stats['total_files'], 'Total files should be 3' );
3, $stats['cached_files'], 'All files should be cached' );
0, $stats['expired_files'], 'No files should be expired' );
100, $stats['cache_hit_rate'], 'Cache hit rate should be 100%' );
}

/**
 * Test cache duration setting.
 */
public function test_set_cache_duration() {
ager->set_cache_duration( 3600 ); // 1 hour.

Track a file.
= 'test_duration';
ager->track_file( $file_id, array( 'file_name' => 'files/duration' ) );

Should be cached within 1 hour.
$this->video_manager->is_file_cached( $file_id ), 'File should be cached' );
}

/**
 * Test max file age setting.
 */
public function test_set_max_file_age() {
ager->set_max_file_age( 86400 ); // 1 day.

This just tests the setter - actual expiration logic would need time manipulation.
= $this->video_manager->get_statistics();
( $stats, 'Statistics should still work after setting max age' );
}

/**
 * Test get all tracked files.
 */
public function test_get_tracked_files() {
ager->track_file( 'file1', array( 'file_name' => 'files/1' ) );
ager->track_file( 'file2', array( 'file_name' => 'files/2' ) );

= $this->video_manager->get_tracked_files();

( $files, 'Tracked files should be an array' );
t( 2, $files, 'Should have 2 tracked files' );
HasKey( 'file1', $files, 'Should have file1' );
HasKey( 'file2', $files, 'Should have file2' );
}

/**
 * Test clear all tracked files.
 */
public function test_clear_all() {
ager->track_file( 'file1', array( 'file_name' => 'files/1' ) );
ager->track_file( 'file2', array( 'file_name' => 'files/2' ) );

t( 2, $this->video_manager->get_tracked_files(), 'Should have 2 files before clear' );

= $this->video_manager->clear_all();
$result, 'Clear all should succeed' );
t( 0, $this->video_manager->get_tracked_files(), 'Should have 0 files after clear' );
}
}
