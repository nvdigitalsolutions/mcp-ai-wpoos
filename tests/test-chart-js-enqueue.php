<?php
/**
 * Tests for Chart.js enqueue logic
 *
 * @package WP_MCP_AI
 */

/**
 * Test Chart.js enqueue functionality.
 */
class Test_Chart_JS_Enqueue extends WP_UnitTestCase {

	/**
	 * Test that Chart.js is enqueued on the correct page (token_manager tab).
	 */
	public function test_chartjs_enqueued_on_token_manager_tab() {
		// Set the tab parameter.
		$_GET['tab'] = 'token_manager';

		// Simulate the admin_enqueue_scripts action with the correct hook.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Chart.js should be enqueued.
		$this->assertTrue( wp_script_is( 'chartjs', 'enqueued' ), 'Chart.js should be enqueued on token_manager tab' );

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
		$this->assertTrue( wp_script_is( 'chartjs', 'enqueued' ), 'Chart.js should be enqueued on orchestration tab' );

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
		$this->assertFalse( wp_script_is( 'chartjs', 'enqueued' ), 'Chart.js should not be enqueued on general tab' );

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
		do_action( 'admin_enqueue_scripts', 'nvoos-pro-dashboard_page_wp-mcp-ai-password-vault' );

		// Chart.js should NOT be enqueued.
		$this->assertFalse( wp_script_is( 'chartjs', 'enqueued' ), 'Chart.js should not be enqueued on password vault page' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that Chart.js is NOT enqueued on other admin pages.
	 */
	public function test_chartjs_not_enqueued_on_other_pages() {
		// Simulate the admin_enqueue_scripts action with a different hook.
		do_action( 'admin_enqueue_scripts', 'edit.php' );

		// Chart.js should NOT be enqueued.
		$this->assertFalse( wp_script_is( 'chartjs', 'enqueued' ), 'Chart.js should not be enqueued on other pages' );
	}
}
