<?php
/**
 * Tests for Chart.js enqueue logic
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Chart.js enqueue functionality.
 */
class Test_Chart_JS_Enqueue extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// WP_UnitTestCase does not reset the WP_Scripts queue between
		// tests, so clear any Chart.js enqueue state leaked by earlier
		// tests in this process. Clearing the whole queue is required:
		// wp-mcp-ai-token-charts depends on Chart.js, so a leftover
		// enqueued dependency would still make wp_script_is() report
		// Chart.js as enqueued.
		global $wp_scripts;
		$wp_scripts->queue = array();

		// WooCommerce's PageController::get_current_page() emits a
		// doing-it-wrong notice when admin_enqueue_scripts callbacks run
		// before the current_screen action has fired. Fire it once here,
		// passing the WP_Screen object the same way core does (WC's
		// callbacks read $screen->id and warn on a string).
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );
		do_action( 'current_screen', get_current_screen() );
	}

	/**
	 * Test that Chart.js is enqueued on the correct page (token_manager tab).
	 */
	public function test_chartjs_enqueued_on_token_manager_tab() {
		// Set the tab parameter.
		$_GET['tab'] = 'token_manager';

		// Simulate the admin_enqueue_scripts action with the correct hook.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Chart.js should be enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should be enqueued on token_manager tab' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that Chart.js is enqueued on the orchestration tab.
	 */
	public function test_chartjs_enqueued_on_orchestration_tab() {
		// Set the tab parameter.
		$_GET['tab'] = 'orchestration';

		// Simulate the admin_enqueue_scripts action with the correct hook.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Chart.js should be enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should be enqueued on orchestration tab' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that Chart.js is NOT enqueued on other tabs.
	 */
	public function test_chartjs_not_enqueued_on_other_tabs() {
		// Set a different tab.
		$_GET['tab'] = 'general';

		// Simulate the admin_enqueue_scripts action with the correct hook.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Chart.js should NOT be enqueued.
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should not be enqueued on general tab' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that Chart.js is NOT enqueued on password vault page.
	 */
	public function test_chartjs_not_enqueued_on_password_vault_page() {
		// Set the vault tab parameter.
		$_GET['tab'] = 'vault';

		// Simulate the admin_enqueue_scripts action with the password vault hook.
		set_current_screen( 'nvoos-pro-dashboard_page_wp-mcp-ai-password-vault' );
		do_action( 'admin_enqueue_scripts', 'nvoos-pro-dashboard_page_wp-mcp-ai-password-vault' );

		// Chart.js should NOT be enqueued.
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should not be enqueued on password vault page' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that Chart.js is NOT enqueued on other admin pages.
	 */
	public function test_chartjs_not_enqueued_on_other_pages() {
		// Simulate the admin_enqueue_scripts action with a different hook.
		set_current_screen( 'edit.php' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );

		// Chart.js should NOT be enqueued.
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should not be enqueued on other pages' );
	}
}
