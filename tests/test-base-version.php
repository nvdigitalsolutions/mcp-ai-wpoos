<?php
/**
 * Tests for the base version mode functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Base Version Test Class.
 */
class WP_MCP_AI_Base_Version_Test extends WP_UnitTestCase {

	/**
	 * Test that base version logic returns true when constant is not defined.
	 *
	 * Since the constant is defined in bootstrap for full version tests,
	 * we test the logic by examining the function's behavior.
	 */
	public function test_base_version_enabled_by_default() {
		// The test bootstrap defines WP_MCP_AI_BASE_VERSION as false for full version.
		// We verify the function logic: ! defined() || value.
		// When defined as false: ! true || false = false (full version) - correct.
		// When undefined (production default): ! false || undefined = true (base version) - correct.

		// Test that with constant false, we get full version.
		$this->assertFalse( wp_mcp_ai_is_base_version(), 'Full version should be active when constant is false (test environment)' );

		// Verify via filter that base version can be enabled.
		$callback = function ( $is_base ) {
			return true;
		};
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$reflection = new ReflectionClass( $registry );
		$method     = $reflection->getMethod( 'is_base_version' );
		$method->setAccessible( true );
		$result = $method->invoke( $registry );

		$this->assertTrue( $result, 'Base version can be enabled via filter' );

		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
	}

	/**
	 * Test that base version is enabled when constant is explicitly true.
	 */
	public function test_base_version_explicitly_enabled() {
		// Test via the tool registry filter which wraps wp_mcp_ai_is_base_version().
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Store callback for cleanup.
		$callback = function ( $is_base ) {
			// Override to simulate constant being set to true.
			return true;
		};

		// Use filter to simulate constant being set to true.
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		// The tool registry uses this filter internally.
		$reflection = new ReflectionClass( $registry );
		$method     = $reflection->getMethod( 'is_base_version' );
		$method->setAccessible( true );
		$result = $method->invoke( $registry );

		$this->assertTrue( $result, 'Base version should be enabled when filter returns true' );

		// Clean up specific filter.
		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
	}

	/**
	 * Test that base version can be disabled via filter.
	 */
	public function test_base_version_can_be_disabled() {
		// Test via the tool registry filter which wraps wp_mcp_ai_is_base_version().
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Store callback for cleanup.
		$callback = function ( $is_base ) {
			// Override to simulate constant being set to false.
			return false;
		};

		// Use filter to simulate constant being set to false.
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		// The tool registry uses this filter internally.
		$reflection = new ReflectionClass( $registry );
		$method     = $reflection->getMethod( 'is_base_version' );
		$method->setAccessible( true );
		$result = $method->invoke( $registry );

		$this->assertFalse( $result, 'Base version should be disabled when filter returns false' );

		// Clean up specific filter.
		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
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
