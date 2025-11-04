<?php
/**
 * Tests for the base version mode functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Base Version Test Class.
 */
class WP_MCP_AI_Base_Version_Test extends WP_UnitTestCase {

	/**
	 * Test that base version is enabled by default when constant is not defined.
	 */
	public function test_base_version_enabled_by_default() {
		// Ensure the constant is not defined for this test.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			$this->markTestSkipped( 'Cannot test default behavior when WP_MCP_AI_BASE_VERSION is already defined.' );
		}

		$this->assertTrue( wp_mcp_ai_is_base_version(), 'Base version should be enabled by default when constant is not defined' );
	}

	/**
	 * Test that base version can be explicitly enabled.
	 */
	public function test_base_version_explicitly_enabled() {
		// Use filter to simulate constant being set to true.
		add_filter(
			'wp_mcp_ai_base_version',
			function ( $is_base ) {
				// Simulate: defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION === true.
				return true;
			},
			999
		);

		$this->assertTrue( wp_mcp_ai_is_base_version(), 'Base version should be enabled when constant is true' );

		remove_all_filters( 'wp_mcp_ai_base_version', 999 );
	}

	/**
	 * Test that base version can be disabled.
	 */
	public function test_base_version_can_be_disabled() {
		// Use filter to simulate constant being set to false.
		add_filter(
			'wp_mcp_ai_base_version',
			function ( $is_base ) {
				// Simulate: defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION === false.
				return false;
			},
			999
		);

		$this->assertFalse( wp_mcp_ai_is_base_version(), 'Base version should be disabled when constant is false' );

		remove_all_filters( 'wp_mcp_ai_base_version', 999 );
	}

	/**
	 * Test that tool registry loads base tools by default.
	 */
	public function test_tool_registry_loads_base_tools_by_default() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Get all registered tools.
		$tools = $registry->get_tools();

		// Base tools that should always be available.
		$expected_base_tools = array(
			'get_recent_posts',
			'search_content',
			'get_user_info',
			'get_site_summary',
			'web_search',
		);

		$tool_slugs = array();
		foreach ( $tools as $tool ) {
			$tool_slugs[] = $tool->get_slug();
		}

		foreach ( $expected_base_tools as $expected_slug ) {
			$this->assertContains(
				$expected_slug,
				$tool_slugs,
				"Base tool '{$expected_slug}' should be loaded by default"
			);
		}
	}

	/**
	 * Test that extended tools are excluded in base version mode.
	 */
	public function test_extended_tools_excluded_in_base_version() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Get all registered tools.
		$tools = $registry->get_tools();

		// Extended tools that should NOT be available in base version.
		// These require third-party plugins or external APIs.
		$extended_tools = array(
			'create_woo_product',
			'get_woo_products',
			'get_jetengine_items',
			'search_gmail',
		);

		$tool_slugs = array();
		foreach ( $tools as $tool ) {
			$tool_slugs[] = $tool->get_slug();
		}

		foreach ( $extended_tools as $extended_slug ) {
			$this->assertNotContains(
				$extended_slug,
				$tool_slugs,
				"Extended tool '{$extended_slug}' should NOT be loaded in base version mode"
			);
		}
	}
}
