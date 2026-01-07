<?php
/**
 * Tests for isams_query tool visibility in admin pages.
 *
 * Verifies that the isams_query tool appears in get_all_available_tools()
 * even when the tool is not registered due to missing configuration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that isams_query tool is visible in admin pages even when not configured.
 */
class Test_ISAMS_Tool_Visibility extends WP_UnitTestCase {

	/**
	 * Test that isams_query tool appears in get_all_available_tools() even when not available.
	 */
	public function test_isams_query_appears_when_not_configured() {
		// Ensure iSAMS is not configured (tool should not be available/registered).
		delete_option( 'wp_mcp_ai_settings' );

		// Verify the tool is not available.
		$this->assertFalse(
			WP_MCP_AI_Tool_ISAMS_Query::is_available(),
			'isams_query should not be available without configuration'
		);

		// Get all available tools (should include unregistered tools).
		$all_tools = WP_MCP_AI_Token_Usage_Service::get_all_available_tools();

		// Verify isams_query is in the list.
		$this->assertArrayHasKey(
			'isams_query',
			$all_tools,
			'isams_query should appear in get_all_available_tools() even when not configured'
		);

		// Verify the tool name is correct.
		$this->assertEquals(
			'Query iSAMS',
			$all_tools['isams_query'],
			'isams_query tool name should be correct'
		);
	}

	/**
	 * Test that isams_query tool appears in get_all_available_tools() when configured.
	 */
	public function test_isams_query_appears_when_configured() {
		// Configure iSAMS (tool should be available/registered).
		update_option(
			'wp_mcp_ai_settings',
			array(
				'isams_api_url'    => 'https://example.isams.cloud/',
				'isams_api_key'    => 'test_key',
				'isams_api_secret' => 'test_secret',
			)
		);

		// Verify the tool is available.
		$this->assertTrue(
			WP_MCP_AI_Tool_ISAMS_Query::is_available(),
			'isams_query should be available with configuration'
		);

		// Get all available tools.
		$all_tools = WP_MCP_AI_Token_Usage_Service::get_all_available_tools();

		// Verify isams_query is in the list.
		$this->assertArrayHasKey(
			'isams_query',
			$all_tools,
			'isams_query should appear in get_all_available_tools() when configured'
		);

		// Verify the tool name is correct.
		$this->assertEquals(
			'Query iSAMS',
			$all_tools['isams_query'],
			'isams_query tool name should be correct'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that other conditional Pro tools also appear.
	 */
	public function test_other_conditional_tools_appear() {
		// Ensure project management is not enabled.
		delete_option( 'wp_mcp_ai_settings' );

		// Get all available tools.
		$all_tools = WP_MCP_AI_Token_Usage_Service::get_all_available_tools();

		// Check for project management tools (which require enable_project_management setting).
		$project_tools = array(
			'create_project',
			'update_project',
			'delete_project',
			'list_projects',
			'create_task',
			'update_task',
			'delete_task',
			'list_tasks',
			'create_event',
			'update_event',
			'delete_event',
			'list_events',
			'get_calendar_view',
		);

		$found_count = 0;
		foreach ( $project_tools as $tool_slug ) {
			if ( isset( $all_tools[ $tool_slug ] ) ) {
				$found_count++;
			}
		}

		// We should find at least some project management tools
		// (they might not all be loaded if enable_project_management is false).
		// The key point is that we're testing the mechanism works for conditional tools.
		$this->assertGreaterThan(
			0,
			$found_count,
			'Some conditional Pro tools should appear in get_all_available_tools()'
		);
	}
}
