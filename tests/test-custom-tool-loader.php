<?php
/**
 * Tests for Custom Tool Loader
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test custom tool loader functionality.
 */
class Test_Custom_Tool_Loader extends WP_UnitTestCase {
	/**
	 * Custom tool loader instance.
	 *
	 * @var WP_MCP_AI_Custom_Tool_Loader
	 */
	private $loader;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->loader = new WP_MCP_AI_Custom_Tool_Loader();
	}

	/**
	 * Test that custom tool loader class exists.
	 */
	public function test_custom_tool_loader_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Custom_Tool_Loader' ) );
	}

	/**
	 * Test that custom tool loader can be instantiated.
	 */
	public function test_custom_tool_loader_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_Custom_Tool_Loader', $this->loader );
	}

	/**
	 * Test that custom tools directory is created.
	 */
	public function test_custom_tools_directory_created() {
		$dir = $this->loader->get_custom_tools_directory();
		$this->assertDirectoryExists( $dir );
	}

	/**
	 * Test that security files are created in custom tools directory.
	 */
	public function test_security_files_created() {
		$dir = $this->loader->get_custom_tools_directory();

		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/README.txt' );
	}

	/**
	 * Test that tool template can be created.
	 */
	public function test_create_tool_template() {
		$result = $this->loader->create_tool_template( 'test_example' );

		$this->assertNotWPError( $result );
		$this->assertFileExists( $result );

		// Clean up.
		unlink( $result );
	}

	/**
	 * Test that duplicate tool template creation fails.
	 */
	public function test_duplicate_tool_template_fails() {
		$tool_name = 'duplicate_test';

		// Create first template.
		$result1 = $this->loader->create_tool_template( $tool_name );
		$this->assertNotWPError( $result1 );

		// Try to create duplicate.
		$result2 = $this->loader->create_tool_template( $tool_name );
		$this->assertWPError( $result2 );
		$this->assertEquals( 'wp_mcp_ai_tool_exists', $result2->get_error_code() );

		// Clean up.
		unlink( $result1 );
	}

	/**
	 * Test that invalid tool names are rejected.
	 */
	public function test_invalid_tool_name_rejected() {
		$result = $this->loader->create_tool_template( '' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_tool_name', $result->get_error_code() );
	}

	/**
	 * Test that custom tools can be listed.
	 */
	public function test_list_custom_tools() {
		// Create a test tool.
		$tool_file = $this->loader->create_tool_template( 'list_test' );

		// List tools.
		$tools = $this->loader->list_custom_tools();

		$this->assertIsArray( $tools );
		$this->assertGreaterThan( 0, count( $tools ) );

		// Verify tool is in list.
		$found = false;
		foreach ( $tools as $tool ) {
			if ( 'list_test' === $tool['slug'] ) {
				$found = true;
				$this->assertArrayHasKey( 'filename', $tool );
				$this->assertArrayHasKey( 'filepath', $tool );
				$this->assertArrayHasKey( 'size', $tool );
				$this->assertArrayHasKey( 'modified', $tool );
				break;
			}
		}

		$this->assertTrue( $found, 'Created tool not found in list' );

		// Clean up.
		unlink( $tool_file );
	}

	/**
	 * Test that custom tool can be deleted.
	 */
	public function test_delete_custom_tool() {
		// Create a test tool.
		$tool_file = $this->loader->create_tool_template( 'delete_test' );
		$this->assertFileExists( $tool_file );

		// Delete the tool.
		$result = $this->loader->delete_custom_tool( 'delete_test' );
		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $tool_file );
	}

	/**
	 * Test that deleting non-existent tool fails.
	 */
	public function test_delete_nonexistent_tool_fails() {
		$result = $this->loader->delete_custom_tool( 'nonexistent_tool' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
	}
}
