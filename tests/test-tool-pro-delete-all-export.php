<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Delete_All_Export.
 *
 * WP All Export plugin is not active in the test environment, so
 * is_available() returns false and every execute() call short-circuits
 * with WP_Error('wp_mcp_ai_all_export_missing').  This is intentional and
 * allows the error-path to be verified without the real plugin installed.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the delete_all_export pro tool.
 */
class Test_Tool_Pro_Delete_All_Export extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Delete_All_Export
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Delete_All_Export' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/wp-all-import-export/class-wp-mcp-ai-tool-delete-all-export.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Delete_All_Export();
	}

	// -----------------------------------------------------------------------
	// get_slug / definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_delete_all_export() {
		$this->assertSame( 'delete_all_export', $this->tool->get_slug() );
	}

	/**
	 * Test that get_name returns a non-empty string.
	 */
	public function test_get_name_returns_non_empty_string() {
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Test that is_available() returns false when the plugin is absent.
	 */
	public function test_is_available_returns_false_without_plugin() {
		$this->assertFalse( WP_MCP_AI_Pro_Tool_Delete_All_Export::is_available() );
	}

	// -----------------------------------------------------------------------
	// execute – plugin not active
	// -----------------------------------------------------------------------

	/**
	 * Test that execute returns WP_Error('wp_mcp_ai_all_export_missing') when plugin absent.
	 */
	public function test_execute_returns_plugin_missing_error() {
		$result = $this->tool->execute(
			array( 'export_id' => 1 ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_all_export_missing', $result->get_error_code() );
	}

	/**
	 * Test that a guest user also gets the plugin-missing error (first guard wins).
	 */
	public function test_guest_also_gets_plugin_missing_error() {
		$result = $this->tool->execute(
			array( 'export_id' => 1 ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_all_export_missing', $result->get_error_code() );
	}

	/**
	 * Test that get_parameters_schema returns the expected required fields.
	 */
	public function test_get_parameters_schema_requires_export_id() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'export_id', $schema['required'] );
	}

	/**
	 * Test that get_capability_flags is an array containing 'requires-capability'.
	 */
	public function test_get_capability_flags_contains_requires_capability() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
	}
}
