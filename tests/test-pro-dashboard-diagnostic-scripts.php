<?php
/**
 * Pro Dashboard Diagnostic Scripts Tests
 *
 * Tests to verify scripts are properly registered on the diagnostic page.
 *
 * @package WP_MCP_AI
 * @since 1.5.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Pro_Dashboard_Diagnostic_Scripts.
 */
class Test_Pro_Dashboard_Diagnostic_Scripts extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// The Pro Dashboard singleton registers its enqueue hooks only on
		// first construction; re-invoke init_hooks() so they exist in this
		// test (wp-phpunit resets hooks between tests).
		if ( class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
			$dashboard            = WP_MCP_AI_Pro_Dashboard::get_instance();
			$dashboard_reflection = new ReflectionClass( $dashboard );
			$init_hooks_method    = $dashboard_reflection->getMethod( 'init_hooks' );
			$init_hooks_method->setAccessible( true );
			$init_hooks_method->invoke( $dashboard );
		}

		// Reset script and style queues so enqueue assertions are not
		// affected by leftovers from previous tests. Dequeueing via the
		// public API also invalidates the internal dependency memo.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( (array) wp_styles()->queue as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Test that scripts are registered on main dashboard page.
	 */
	public function test_scripts_registered_on_main_dashboard() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate main dashboard page load.
		set_current_screen( 'toplevel_page_nvoos-pro-dashboard' );

		// Trigger asset enqueuing.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_nvoos-pro-dashboard' );

		// Check that scripts are registered and enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chartjs', 'registered' ), 'Chart.js should be registered on main dashboard' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-pro-dashboard', 'registered' ), 'Pro Dashboard script should be registered on main dashboard' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should be enqueued on main dashboard' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-pro-dashboard', 'enqueued' ), 'Pro Dashboard script should be enqueued on main dashboard' );
	}

	/**
	 * Test that scripts are registered on diagnostic page.
	 */
	public function test_scripts_registered_on_diagnostic_page() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate diagnostic page load.
		set_current_screen( 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' );

		// Trigger asset enqueuing with diagnostic page hook.
		do_action( 'admin_enqueue_scripts', 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' );

		// Check that scripts are registered and enqueued on diagnostic page.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chartjs', 'registered' ), 'Chart.js should be registered on diagnostic page' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-pro-dashboard', 'registered' ), 'Pro Dashboard script should be registered on diagnostic page' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should be enqueued on diagnostic page' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-pro-dashboard', 'enqueued' ), 'Pro Dashboard script should be enqueued on diagnostic page' );
	}

	/**
	 * Test that scripts are not registered on unrelated pages.
	 */
	public function test_scripts_not_registered_on_unrelated_pages() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate an unrelated admin page.
		set_current_screen( 'edit-post' );

		// Trigger asset enqueuing with unrelated page hook.
		do_action( 'admin_enqueue_scripts', 'edit.php' );

		// Check that scripts are not enqueued on unrelated pages.
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-chartjs', 'enqueued' ), 'Chart.js should not be enqueued on unrelated pages' );
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-pro-dashboard', 'enqueued' ), 'Pro Dashboard script should not be enqueued on unrelated pages' );
	}

	/**
	 * Test that diagnostic test correctly identifies registered scripts.
	 */
	public function test_diagnostic_detects_registered_scripts() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate diagnostic page load to ensure scripts are registered.
		set_current_screen( 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' );
		do_action( 'admin_enqueue_scripts', 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' );

		// Run diagnostic tests.
		$results = WP_MCP_AI_Pro_Dashboard_Diagnostic::run_diagnostics();

		// Check scripts_registered test.
		$this->assertArrayHasKey( 'scripts_registered', $results['tests'], 'Diagnostic should include scripts_registered test' );

		$scripts_test = $results['tests']['scripts_registered'];

		// Both scripts should be registered now.
		$this->assertTrue( $scripts_test['wp-mcp-ai-chartjs'], 'Chart.js should be detected as registered by diagnostic' );
		$this->assertTrue( $scripts_test['pro_dashboard'], 'Pro Dashboard script should be detected as registered by diagnostic' );
		$this->assertEquals( 'pass', $scripts_test['status'], 'Scripts registration test should pass' );
	}

	/**
	 * Test that Chart.js has correct dependencies.
	 */
	public function test_chartjs_dependencies() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Trigger asset enqueuing.
		set_current_screen( 'toplevel_page_nvoos-pro-dashboard' );
		do_action( 'admin_enqueue_scripts', 'toplevel_page_nvoos-pro-dashboard' );

		global $wp_scripts;

		// Check Chart.js has no dependencies (it's standalone).
		$chartjs = $wp_scripts->registered['wp-mcp-ai-chartjs'];
		$this->assertEmpty( $chartjs->deps, 'Chart.js should have no dependencies' );
	}

	/**
	 * Test that Pro Dashboard script has correct dependencies.
	 */
	public function test_pro_dashboard_script_dependencies() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Trigger asset enqueuing.
		set_current_screen( 'toplevel_page_nvoos-pro-dashboard' );
		do_action( 'admin_enqueue_scripts', 'toplevel_page_nvoos-pro-dashboard' );

		global $wp_scripts;

		// Check Pro Dashboard script has correct dependencies.
		$pro_dashboard = $wp_scripts->registered['wp-mcp-ai-pro-dashboard'];
		$this->assertContains( 'jquery', $pro_dashboard->deps, 'Pro Dashboard script should depend on jQuery' );
		$this->assertContains( 'wp-mcp-ai-chartjs', $pro_dashboard->deps, 'Pro Dashboard script should depend on Chart.js' );
	}

	/**
	 * Test that scripts have proper version for cache busting.
	 */
	public function test_scripts_have_versions() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Trigger asset enqueuing.
		set_current_screen( 'toplevel_page_nvoos-pro-dashboard' );
		do_action( 'admin_enqueue_scripts', 'toplevel_page_nvoos-pro-dashboard' );

		global $wp_scripts;

		// Check Chart.js has a version.
		$this->assertNotEmpty( $wp_scripts->registered['wp-mcp-ai-chartjs']->ver, 'Chart.js should have a version' );

		// Check Pro Dashboard script has a version.
		$this->assertNotEmpty( $wp_scripts->registered['wp-mcp-ai-pro-dashboard']->ver, 'Pro Dashboard script should have a version' );
	}

	/**
	 * Test that styles are also registered on diagnostic page.
	 */
	public function test_styles_registered_on_diagnostic_page() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate diagnostic page load.
		set_current_screen( 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' );
		do_action( 'admin_enqueue_scripts', 'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic' );

		// Check that styles are registered and enqueued.
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-responsive-utilities', 'registered' ), 'Responsive utilities CSS should be registered' );
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-pro-dashboard', 'registered' ), 'Pro Dashboard CSS should be registered' );
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-responsive-utilities', 'enqueued' ), 'Responsive utilities CSS should be enqueued' );
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-pro-dashboard', 'enqueued' ), 'Pro Dashboard CSS should be enqueued' );
	}
}
