<?php
/**
 * Tests for WP_MCP_AI_Tool_Delete_All_Import.
 *
 * The WP All Import plugin (PMXI_Plugin) is not present in the test
 * environment.  is_available() therefore returns false and every call to
 * execute() immediately returns WP_Error('wp_mcp_ai_all_import_missing').
 * Tests validate that this guard fires correctly and verify schema / flag
 * metadata that can be inspected without the plugin.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the delete_all_import pro tool.
 */
class Test_Tool_Pro_Delete_All_Import extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Delete_All_Import
	 */
	private $tool;

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Delete_All_Import' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/wp-all-import-export/class-wp-mcp-ai-tool-delete-all-import.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Delete_All_Import();
	}

	// -----------------------------------------------------------------------
	// is_available()
	// -----------------------------------------------------------------------

	/**
	 * Test that is_available() returns false when PMXI_Plugin class is absent.
	 */
	public function test_is_available_returns_false_without_all_import_plugin() {
		$this->assertFalse( $this->tool->is_available() );
	}

	// -----------------------------------------------------------------------
	// execute – plugin missing guard
	// -----------------------------------------------------------------------

	/**
	 * Test that execute() returns WP_Error('wp_mcp_ai_all_import_missing')
	 * regardless of arguments when the All Import plugin is not active.
	 */
	public function test_execute_returns_all_import_missing_error_for_admin() {
		$result = $this->tool->execute(
			array( 'import_id' => 1 ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_all_import_missing', $result->get_error_code() );
	}

	/**
	 * Test that the plugin-missing guard fires even for a guest user (plugin
	 * check fires before the capability check).
	 */
	public function test_execute_returns_all_import_missing_error_for_guest() {
		$result = $this->tool->execute(
			array( 'import_id' => 1 ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_all_import_missing', $result->get_error_code() );
	}

	/**
	 * Test that the plugin-missing guard fires even when no arguments are supplied.
	 */
	public function test_execute_returns_all_import_missing_error_with_empty_args() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_all_import_missing', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// get_slug / get_definition / get_capability_flags
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_delete_all_import() {
		$this->assertSame( 'delete_all_import', $this->tool->get_slug() );
	}

	/**
	 * Test that get_name returns a non-empty human-readable name.
	 */
	public function test_get_name_returns_non_empty_string() {
		$name = $this->tool->get_name();
		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
	}

	/**
	 * Test that get_capability_flags contains 'destructive'.
	 */
	public function test_capability_flags_contain_destructive() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'destructive', $flags );
	}
}
