<?php
/**
 * Tests for Symfony Filesystem integration
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Filesystem_Service
 *
 * Tests for the Symfony Filesystem service integration.
 */
class Test_WP_MCP_AI_Filesystem_Service extends WP_UnitTestCase {

	/**
	 * Filesystem service instance.
	 *
	 * @var WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service
	 */
	private $filesystem_service;

	/**
	 * Temporary directory for tests.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load filesystem service.
		require_once dirname( __DIR__ ) . '/includes/filesystem/class-wp-mcp-ai-filesystem-service.php';
		$this->filesystem_service = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance();

		// Create temp directory for tests.
		$this->temp_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-' . uniqid();
		$this->filesystem_service->mkdir( $this->temp_dir );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		if ( $this->filesystem_service->exists( $this->temp_dir ) ) {
			$this->filesystem_service->remove( $this->temp_dir );
		}
		parent::tearDown();
	}

	/**
	 * Test that filesystem service is a singleton.
	 */
	public function test_filesystem_service_is_singleton() {
		$instance1 = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance();
		$instance2 = \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Filesystem service should be a singleton' );
	}

	/**
	 * Test write_file creates file atomically.
	 */
	public function test_write_file() {
		$filename = $this->temp_dir . '/test.txt';
		$content  = 'Test content';

		$result = $this->filesystem_service->write_file( $filename, $content );

		$this->assertTrue( $result, 'write_file should return true' );
		$this->assertTrue( $this->filesystem_service->exists( $filename ), 'File should exist' );
		$this->assertEquals( $content, file_get_contents( $filename ), 'File content should match' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test append_to_file.
	 */
	public function test_append_to_file() {
		$filename = $this->temp_dir . '/append.txt';
		$content1 = "Line 1\n";
		$content2 = "Line 2\n";

		$this->filesystem_service->write_file( $filename, $content1 );
		$this->filesystem_service->append_to_file( $filename, $content2 );

		$full_content = file_get_contents( $filename ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertEquals( $content1 . $content2, $full_content, 'Content should be appended' );
	}

	/**
	 * Test mkdir creates directories.
	 */
	public function test_mkdir() {
		$nested_dir = $this->temp_dir . '/level1/level2/level3';

		$result = $this->filesystem_service->mkdir( $nested_dir );

		$this->assertTrue( $result, 'mkdir should return true' );
		$this->assertTrue( $this->filesystem_service->exists( $nested_dir ), 'Nested directories should exist' );
		$this->assertTrue( is_dir( $nested_dir ), 'Path should be a directory' );
	}

	/**
	 * Test exists method.
	 */
	public function test_exists() {
		$existing_file     = $this->temp_dir . '/existing.txt';
		$nonexistent_file = $this->temp_dir . '/nonexistent.txt';

		$this->filesystem_service->write_file( $existing_file, 'content' );

		$this->assertTrue( $this->filesystem_service->exists( $existing_file ), 'Existing file should return true' );
		$this->assertFalse( $this->filesystem_service->exists( $nonexistent_file ), 'Nonexistent file should return false' );
	}

	/**
	 * Test remove files and directories.
	 */
	public function test_remove() {
		$file_to_remove = $this->temp_dir . '/remove.txt';
		$dir_to_remove  = $this->temp_dir . '/remove_dir';

		$this->filesystem_service->write_file( $file_to_remove, 'content' );
		$this->filesystem_service->mkdir( $dir_to_remove );

		$this->assertTrue( $this->filesystem_service->exists( $file_to_remove ), 'File should exist before removal' );
		$this->assertTrue( $this->filesystem_service->exists( $dir_to_remove ), 'Directory should exist before removal' );

		$this->filesystem_service->remove( $file_to_remove );
		$this->filesystem_service->remove( $dir_to_remove );

		$this->assertFalse( $this->filesystem_service->exists( $file_to_remove ), 'File should not exist after removal' );
		$this->assertFalse( $this->filesystem_service->exists( $dir_to_remove ), 'Directory should not exist after removal' );
	}

	/**
	 * Test copy files.
	 */
	public function test_copy() {
		$source = $this->temp_dir . '/source.txt';
		$target = $this->temp_dir . '/target.txt';
		$content = 'Source content';

		$this->filesystem_service->write_file( $source, $content );
		$result = $this->filesystem_service->copy( $source, $target );

		$this->assertTrue( $result, 'copy should return true' );
		$this->assertTrue( $this->filesystem_service->exists( $target ), 'Target file should exist' );
		$this->assertEquals( $content, file_get_contents( $target ), 'Target content should match source' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test rename files.
	 */
	public function test_rename() {
		$old_name = $this->temp_dir . '/old.txt';
		$new_name = $this->temp_dir . '/new.txt';
		$content  = 'Content to rename';

		$this->filesystem_service->write_file( $old_name, $content );
		$result = $this->filesystem_service->rename( $old_name, $new_name );

		$this->assertTrue( $result, 'rename should return true' );
		$this->assertFalse( $this->filesystem_service->exists( $old_name ), 'Old file should not exist' );
		$this->assertTrue( $this->filesystem_service->exists( $new_name ), 'New file should exist' );
		$this->assertEquals( $content, file_get_contents( $new_name ), 'Content should be preserved' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test chmod changes permissions.
	 */
	public function test_chmod() {
		$filename = $this->temp_dir . '/chmod.txt';
		$this->filesystem_service->write_file( $filename, 'content' );

		$result = $this->filesystem_service->chmod( $filename, 0644 );

		$this->assertTrue( $result, 'chmod should return true' );
		$perms = fileperms( $filename ) & 0777;
		$this->assertEquals( 0644, $perms, 'Permissions should be set correctly' );
	}

	/**
	 * Test error handling for invalid operations.
	 */
	public function test_error_handling() {
		// Try to write to invalid path.
		$invalid_path = '/invalid/path/that/does/not/exist/file.txt';
		$result       = $this->filesystem_service->write_file( $invalid_path, 'content' );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid operation' );
		$this->assertEquals( 'filesystem_error', $result->get_error_code(), 'Should have filesystem_error code' );
	}

	/**
	 * Test atomic write prevents corruption.
	 */
	public function test_atomic_write() {
		$filename = $this->temp_dir . '/atomic.txt';
		$content  = str_repeat( 'Test content ', 1000 ); // Large content.

		$result = $this->filesystem_service->write_file( $filename, $content );

		$this->assertTrue( $result, 'Atomic write should succeed' );
		$this->assertTrue( $this->filesystem_service->exists( $filename ), 'File should exist' );
		$this->assertEquals( $content, file_get_contents( $filename ), 'File should not be corrupted' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
