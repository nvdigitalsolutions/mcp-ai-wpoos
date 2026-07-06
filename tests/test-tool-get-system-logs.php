<?php
/**
 * Tests for get_system_logs tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test get_system_logs tool functionality.
 */
class Test_Tool_Get_System_Logs extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_System_Logs
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool      = new WP_MCP_AI_Tool_Get_System_Logs();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'get_system_logs', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Admin gets a response with expected top-level keys.
	 */
	public function test_admin_gets_log_response_shape() {
		$result = $this->tool->execute(
			array( 'include_plugin_logs' => false ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'wp_mcp_ai', $result );
		$this->assertArrayHasKey( 'WordPress', $result );
	}

	/**
	 * Plugin logs are omitted when include_plugin_logs is false.
	 */
	public function test_plugin_logs_omitted_when_disabled() {
		$result = $this->tool->execute(
			array( 'include_plugin_logs' => false ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'plugin_logs', $result );
		// When disabled, plugin_logs should contain a 'message' key (not an array of files).
		$this->assertArrayHasKey( 'message', $result['plugin_logs'] );
	}

	/**
	 * Capability flags include 'requires-capability'.
	 */
	public function test_capability_flags_require_capability() {
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'requires-capability', $flags );
	}
}
