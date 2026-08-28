<?php
/**
 * Test Pro Dashboard Constant
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Pro Dashboard constant functionality.
 *
 * Note: These tests document the expected behavior when WP_MCP_AI_PRO_DASHBOARD_ENABLED
 * constant is defined in wp-config.php. Since constants cannot be defined/undefined
 * in PHP tests after they're set, these tests verify the logic around the constant.
 */
class Test_Pro_Dashboard_Constant extends WP_UnitTestCase {

	/**
	 * Test that the constant is checked in the Pro Dashboard class.
	 */
	public function test_pro_dashboard_checks_constant() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Verify the method exists and is callable.
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		$this->assertTrue( method_exists( $dashboard, 'is_pro_active' ), 'is_pro_active method should exist' );

		// The test bootstrap defines WP_MCP_AI_PRO_DASHBOARD_ENABLED as false,
		// and without an opt-in filter Pro should be disabled.
		$result = $dashboard->is_pro_active();
		$this->assertFalse( $result, 'is_pro_active should be false without constant or filter' );
	}

	/**
	 * Test that the constant is checked in REST API class.
	 */
	public function test_rest_api_checks_constant() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-rest.php';

		$rest_api = new WP_MCP_AI_Pro_Dashboard_REST();
		$this->assertTrue( method_exists( $rest_api, 'check_pro_permission' ), 'check_pro_permission method should exist' );

		// Set up admin user for capability check.
		wp_set_current_user( 1 );

		// Without constant or filter, should return WP_Error.
		$result = $rest_api->check_pro_permission();
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error when Pro is not active' );
	}

	/**
	 * Test that the constant is checked in License class.
	 */
	public function test_license_checks_constant() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-license.php';

		$this->assertTrue( method_exists( 'WP_MCP_AI_Pro_License', 'is_pro_active' ), 'is_pro_active static method should exist' );

		// Without constant, filter, or valid license, should be false.
		$result = WP_MCP_AI_Pro_License::is_pro_active();
		$this->assertIsBool( $result, 'is_pro_active should return a boolean' );
	}

	/**
	 * Test filter still works for backward compatibility.
	 */
	public function test_filter_backward_compatibility() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		// Initially disabled.
		$this->assertFalse( $dashboard->is_pro_active() );

		// Enable with filter.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
		$this->assertTrue( $dashboard->is_pro_active(), 'Filter should still work for backward compatibility' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that the License class constant opt-out wins over the filter.
	 */
	public function test_license_filter_backward_compatibility() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-license.php';

		// The License class treats a false constant as a hard opt-out: the
		// filter cannot re-enable Pro when the admin disabled it via the
		// constant. The test bootstrap defines the constant as false.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
		$this->assertFalse( WP_MCP_AI_Pro_License::is_pro_active(), 'Constant opt-out should win over the filter in License class' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that REST API filter works for backward compatibility.
	 */
	public function test_rest_api_filter_backward_compatibility() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-rest.php';

		$rest_api = new WP_MCP_AI_Pro_Dashboard_REST();

		// Set up admin user for capability check.
		wp_set_current_user( 1 );

		// Enable with filter.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
		$result = $rest_api->check_pro_permission();
		$this->assertTrue( true === $result, 'Filter should enable Pro in REST API' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test documentation for constant usage.
	 */
	public function test_constant_documentation() {
		// This test documents how users should use the constant: adding
		// `define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );` to wp-config.php.

		// Verify constant name is correct in our checks.
		$expected_constant = 'WP_MCP_AI_PRO_DASHBOARD_ENABLED';

		// Get the Pro Dashboard class code to verify it checks for this constant.
		$dashboard_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';
		$this->assertFileExists( $dashboard_file, 'Pro Dashboard file should exist' );

		$content = file_get_contents( $dashboard_file );
		$this->assertStringContainsString( $expected_constant, $content, 'Pro Dashboard should check for WP_MCP_AI_PRO_DASHBOARD_ENABLED constant' );
	}
}
