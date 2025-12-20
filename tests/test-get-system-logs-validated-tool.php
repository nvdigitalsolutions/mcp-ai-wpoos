<?php
/**
 * Tests for the get_system_logs_validated tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-system-logs-validated.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Test case for the Symfony Validator version of get_system_logs tool.
 */
class WP_MCP_AI_Get_System_Logs_Validated_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that the tool has correct metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Get_System_Logs_Validated();

		$this->assertSame( 'get_system_logs_validated', $tool->get_slug() );
		$this->assertSame( 'Get System Logs (Validated)', $tool->get_name() );
		$this->assertStringContainsString( 'system log', strtolower( $tool->get_description() ) );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/**
	 * Test tool execution with default arguments.
	 */
	public function test_execute_with_defaults() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'wp_mcp_ai', $result );
		$this->assertArrayHasKey( 'WordPress', $result );
		$this->assertArrayHasKey( 'plugin_logs', $result );
	}

	/**
	 * Test tool execution with custom activity limit.
	 */
	public function test_execute_with_custom_activity_limit() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'activity_limit' => 25 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'wp_mcp_ai', $result );
	}

	/**
	 * Test tool rejects activity limit below minimum.
	 */
	public function test_execute_rejects_activity_limit_below_minimum() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'activity_limit' => 0 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects activity limit above maximum.
	 */
	public function test_execute_rejects_activity_limit_above_maximum() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'activity_limit' => 100 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool execution with custom error limit.
	 */
	public function test_execute_with_custom_error_limit() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'error_limit' => 30 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'wp_mcp_ai', $result );
	}

	/**
	 * Test tool rejects error limit below minimum.
	 */
	public function test_execute_rejects_error_limit_below_minimum() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'error_limit' => 0 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool execution with debug log disabled.
	 */
	public function test_execute_with_debug_log_disabled() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'include_debug_log' => false ),
			array( 'user_id' => $admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'WordPress', $result );
	}

	/**
	 * Test tool requires manage_options capability.
	 */
	public function test_execute_requires_manage_options() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute( array(), array( 'user_id' => $editor_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test capability flags match the original tool.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test tool rejects invalid plugin log depth.
	 */
	public function test_execute_rejects_invalid_plugin_log_depth() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'plugin_log_depth' => 10 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects invalid debug log bytes.
	 */
	public function test_execute_rejects_invalid_debug_log_bytes() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_System_Logs_Validated();
		$result = $tool->execute(
			array( 'debug_log_bytes' => 500 ), // Too small, minimum is 1024.
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}
}
