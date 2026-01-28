<?php
/**
 * Test Manage Files tool.
 *
 * @package WP_MCP_AI
 */
class Test_Manage_Files_Tool extends WP_UnitTestCase {

	/**
	 * Manage Files tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Manage_Files
	 */
	private $tool;

	/**
	 * Test administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Test editor user ID (no edit_plugins capability).
	 *
	 * @var int
	 */
	private $editor_user_id;

	/**
	 * Temporary test directory path.
	 *
	 * @var string
	 */
	private $test_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-manage-files.php';

		// Instantiate the tool.
		$this->tool = new WP_MCP_AI_Tool_Manage_Files();

		// Create test users.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$this->editor_user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		// Set up temporary test directory within plugin path.
		$this->test_dir = WP_MCP_AI_PATH . 'test-temp-' . time();
		if ( ! file_exists( $this->test_dir ) ) {
			wp_mkdir_p( $this->test_dir );
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Remove test directory and all contents.
		if ( file_exists( $this->test_dir ) ) {
			$this->remove_directory( $this->test_dir );
		}

		parent::tearDown();
	}

	/**
	 * Recursively remove directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'manage_files', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$this->assertEquals( 'Manage Files', $this->tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$description = $this->tool->get_description();
		$this->assertStringContainsString( 'Read, write, and list files', $description );
		$this->assertStringContainsString( 'Architect Agent', $description );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'path', $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'create_dirs', $schema['properties'] );

		$this->assertContains( 'read', $schema['properties']['action']['enum'] );
		$this->assertContains( 'write', $schema['properties']['action']['enum'] );
		$this->assertContains( 'list', $schema['properties']['action']['enum'] );

		$this->assertContains( 'action', $schema['required'] );
		$this->assertContains( 'path', $schema['required'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'reversible', $flags );
	}

	/**
	 * Test execute requires user to be logged in.
	 */
	public function test_execute_requires_login() {
		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'path'   => 'includes',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_unauthorized', $result->get_error_code() );
	}

	/**
	 * Test execute requires edit_plugins capability.
	 */
	public function test_execute_requires_edit_plugins_capability() {
		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'path'   => 'includes',
			),
			array( 'user_id' => $this->editor_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute requires action parameter.
	 */
	public function test_execute_requires_action() {
		$result = $this->tool->execute(
			array(
				'path' => 'includes',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_action', $result->get_error_code() );
	}

	/**
	 * Test execute validates action parameter.
	 */
	public function test_execute_validates_action() {
		$result = $this->tool->execute(
			array(
				'action' => 'invalid',
				'path'   => 'includes',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_action', $result->get_error_code() );
	}

	/**
	 * Test execute requires path parameter.
	 */
	public function test_execute_requires_path() {
		$result = $this->tool->execute(
			array(
				'action' => 'list',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_path', $result->get_error_code() );
	}

	/**
	 * Test path validation prevents directory traversal.
	 */
	public function test_path_validation_prevents_directory_traversal() {
		$result = $this->tool->execute(
			array(
				'action' => 'read',
				'path'   => '../../../wp-config.php',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_path', $result->get_error_code() );
	}

	/**
	 * Test path validation restricts to plugin directory.
	 */
	public function test_path_validation_restricts_to_plugin_directory() {
		// Try to access a file outside plugin directory.
		$result = $this->tool->execute(
			array(
				'action' => 'read',
				'path'   => '/etc/passwd',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		// This should fail either at path validation or path_outside_plugin.
		$this->assertContains( $result->get_error_code(), array( 'wp_mcp_ai_invalid_path', 'wp_mcp_ai_path_outside_plugin' ) );
	}

	/**
	 * Test list action on valid directory.
	 */
	public function test_list_action_success() {
		// List the includes directory (should exist).
		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'path'   => 'includes',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'list', $result['action'] );
		$this->assertArrayHasKey( 'directories', $result );
		$this->assertArrayHasKey( 'files', $result );
		$this->assertIsArray( $result['directories'] );
		$this->assertIsArray( $result['files'] );
	}

	/**
	 * Test list action on non-existent directory.
	 */
	public function test_list_action_directory_not_found() {
		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'path'   => 'nonexistent-directory-' . time(),
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_dir_not_found', $result->get_error_code() );
	}

	/**
	 * Test list action on file (should fail).
	 */
	public function test_list_action_not_a_directory() {
		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'path'   => 'mcp-ai-wpoos.php',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_not_a_directory', $result->get_error_code() );
	}

	/**
	 * Test read action on existing file.
	 */
	public function test_read_action_success() {
		// Read the main plugin file.
		$result = $this->tool->execute(
			array(
				'action' => 'read',
				'path'   => 'mcp-ai-wpoos.php',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'read', $result['action'] );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'size', $result );
		$this->assertIsString( $result['content'] );
		$this->assertStringContainsString( '<?php', $result['content'] );
	}

	/**
	 * Test read action on non-existent file.
	 */
	public function test_read_action_file_not_found() {
		$result = $this->tool->execute(
			array(
				'action' => 'read',
				'path'   => 'nonexistent-file-' . time() . '.php',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test read action on directory (should fail).
	 */
	public function test_read_action_not_a_file() {
		$result = $this->tool->execute(
			array(
				'action' => 'read',
				'path'   => 'includes',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_not_a_file', $result->get_error_code() );
	}

	/**
	 * Test write action creates new file.
	 */
	public function test_write_action_creates_file() {
		$relative_path = str_replace( WP_MCP_AI_PATH, '', $this->test_dir ) . '/test-file.txt';
		$test_content  = 'Test content for manage_files tool';

		$result = $this->tool->execute(
			array(
				'action'  => 'write',
				'path'    => $relative_path,
				'content' => $test_content,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'write', $result['action'] );
		$this->assertArrayHasKey( 'bytes', $result );
		$this->assertEquals( strlen( $test_content ), $result['bytes'] );

		// Verify file was created with correct content.
		$written_content = file_get_contents( $this->test_dir . '/test-file.txt' );
		$this->assertEquals( $test_content, $written_content );
	}

	/**
	 * Test write action updates existing file.
	 */
	public function test_write_action_updates_file() {
		$file_path = $this->test_dir . '/test-update.txt';
		file_put_contents( $file_path, 'Original content' );

		$relative_path = str_replace( WP_MCP_AI_PATH, '', $file_path );
		$new_content   = 'Updated content';

		$result = $this->tool->execute(
			array(
				'action'  => 'write',
				'path'    => $relative_path,
				'content' => $new_content,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		// Verify file was updated.
		$written_content = file_get_contents( $file_path );
		$this->assertEquals( $new_content, $written_content );
	}

	/**
	 * Test write action creates parent directories.
	 */
	public function test_write_action_creates_directories() {
		$relative_path = str_replace( WP_MCP_AI_PATH, '', $this->test_dir ) . '/subdir/nested/test.txt';
		$test_content  = 'Nested file content';

		$result = $this->tool->execute(
			array(
				'action'      => 'write',
				'path'        => $relative_path,
				'content'     => $test_content,
				'create_dirs' => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		// Verify file exists.
		$file_path = $this->test_dir . '/subdir/nested/test.txt';
		$this->assertTrue( file_exists( $file_path ) );
		$this->assertEquals( $test_content, file_get_contents( $file_path ) );
	}

	/**
	 * Test write action requires content parameter.
	 */
	public function test_write_action_requires_content() {
		$result = $this->tool->execute(
			array(
				'action' => 'write',
				'path'   => str_replace( WP_MCP_AI_PATH, '', $this->test_dir ) . '/test.txt',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_content', $result->get_error_code() );
	}

	/**
	 * Test write action with create_dirs false.
	 */
	public function test_write_action_create_dirs_false() {
		$relative_path = str_replace( WP_MCP_AI_PATH, '', $this->test_dir ) . '/nonexistent/test.txt';

		$result = $this->tool->execute(
			array(
				'action'      => 'write',
				'path'        => $relative_path,
				'content'     => 'test',
				'create_dirs' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_dir_not_found', $result->get_error_code() );
	}

	/**
	 * Test write action allows empty content.
	 */
	public function test_write_action_empty_content() {
		$relative_path = str_replace( WP_MCP_AI_PATH, '', $this->test_dir ) . '/empty-file.txt';

		$result = $this->tool->execute(
			array(
				'action'  => 'write',
				'path'    => $relative_path,
				'content' => '',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		// Verify empty file was created.
		$file_path = $this->test_dir . '/empty-file.txt';
		$this->assertTrue( file_exists( $file_path ) );
		$this->assertEquals( '', file_get_contents( $file_path ) );
	}

	/**
	 * Test multisite capability check.
	 */
	public function test_multisite_capability_check() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite not enabled' );
		}

		// Create a user that's not a member of current blog.
		$other_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Remove from current blog.
		remove_user_from_blog( $other_user_id, get_current_blog_id() );

		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'path'   => 'includes',
			),
			array( 'user_id' => $other_user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_wrong_site', $result->get_error_code() );
	}
}
