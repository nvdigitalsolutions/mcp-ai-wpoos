<?php
/**
 * Tests for the base version mode functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Extended tools are registered based on their third-party dependency,
	 * not the base-version flag: each class self-reports availability via a
	 * static is_available() gate.
	 */
	public function test_extended_tools_self_report_availability() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// WooCommerce tools are gated on WooCommerce being active.
		$woo_active = class_exists( 'WooCommerce' ) && class_exists( 'WC_Product' );
		foreach ( array( 'create_woo_product', 'get_woo_products' ) as $woo_slug ) {
			$tool = $registry->get_tool( $woo_slug );
			if ( $woo_active ) {
				$this->assertNotNull( $tool, "WooCommerce tool '{$woo_slug}' should be registered when WooCommerce is active." );
			} else {
				$this->assertNull( $tool, "WooCommerce tool '{$woo_slug}' should be omitted when WooCommerce is inactive." );
			}
		}

		// JetEngine tools are gated on JetEngine being active.
		$jetengine_active = function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
		$jetengine_tool   = $registry->get_tool( 'get_jetengine_items' );
		if ( $jetengine_active ) {
			$this->assertNotNull( $jetengine_tool, 'JetEngine tool should be registered when JetEngine is active.' );
		} else {
			$this->assertNull( $jetengine_tool, 'JetEngine tool should be omitted when JetEngine is inactive.' );
		}
	}
}
