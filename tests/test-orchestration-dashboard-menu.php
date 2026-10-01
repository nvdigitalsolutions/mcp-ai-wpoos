<?php
/**
 * Tests for Orchestration Dashboard Menu Registration
 *
 * Verifies that both base and Pro orchestration dashboards are properly
 * registered in the WordPress admin menu.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test orchestration dashboard menu registration.
 */
class Test_Orchestration_Dashboard_Menu extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test that base orchestration dashboard menu is registered.
	 */
	public function test_base_orchestration_dashboard_registered() {
		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check if the main NV oOS menu exists.
		$this->assertArrayHasKey(
			'wp-mcp-ai-dashboard',
			$submenu,
			'Main NV oOS menu should be registered'
		);

		// Find the orchestration dashboard in the submenu.
		$orchestration_found = false;
		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( isset( $item[2] ) && 'mcp-ai-orchestration' === $item[2] ) {
				$orchestration_found = true;
				$this->assertEquals( 'Orchestration', $item[0], 'Menu title should be "Orchestration"' );
				break;
			}
		}

		$this->assertTrue(
			$orchestration_found,
			'Base orchestration dashboard should be registered in submenu'
		);
	}

	/**
	 * Test that Pro orchestration dashboard menu is registered (if Pro is active).
	 */
	public function test_pro_orchestration_dashboard_registered() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// The Pro dashboard is normally constructed by the module registry's
		// admin-context gate, which never runs under PHPUnit (is_admin() is
		// false). Instantiate it directly, mirroring the module factory.
		new WP_MCP_AI_Orchestration_Dashboard();

		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check if the Pro Dashboard menu exists.
		$this->assertArrayHasKey(
			'nvoos-pro-dashboard',
			$submenu,
			'Pro Dashboard menu should be registered'
		);

		// Find the Pro orchestration dashboard in the Pro submenu.
		$pro_orchestration_found = false;
		foreach ( $submenu['nvoos-pro-dashboard'] as $item ) {
			if ( isset( $item[2] ) && 'mcp-ai-orchestration-pro' === $item[2] ) {
				$pro_orchestration_found = true;
				$this->assertStringContainsString(
					'Orchestration',
					$item[0],
					'Pro orchestration menu title should contain "Orchestration"'
				);
				break;
			}
		}

		$this->assertTrue(
			$pro_orchestration_found,
			'Pro orchestration dashboard should be registered in Pro submenu'
		);
	}

	/**
	 * Test that both orchestration pages use different slugs.
	 */
	public function test_orchestration_pages_use_different_slugs() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// The Pro dashboard is normally constructed by the module registry's
		// admin-context gate, which never runs under PHPUnit (is_admin() is
		// false). Instantiate it directly, mirroring the module factory.
		new WP_MCP_AI_Orchestration_Dashboard();

		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Get base orchestration slug from base menu.
		$base_slug = null;
		if ( isset( $submenu['wp-mcp-ai-dashboard'] ) ) {
			foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
				if ( isset( $item[2] ) && false !== strpos( $item[2], 'orchestration' ) ) {
					$base_slug = $item[2];
					break;
				}
			}
		}

		// Get Pro orchestration slug from Pro menu.
		$pro_slug = null;
		if ( isset( $submenu['nvoos-pro-dashboard'] ) ) {
			foreach ( $submenu['nvoos-pro-dashboard'] as $item ) {
				if ( isset( $item[2] ) && false !== strpos( $item[2], 'orchestration' ) ) {
					$pro_slug = $item[2];
					break;
				}
			}
		}

		// Both should exist.
		$this->assertNotNull( $base_slug, 'Base orchestration page should exist' );
		$this->assertNotNull( $pro_slug, 'Pro orchestration page should exist' );

		// Slugs should be different.
		$this->assertNotEquals(
			$base_slug,
			$pro_slug,
			'Base and Pro orchestration pages should use different slugs'
		);
	}

	/**
	 * Test that base orchestration dashboard has proper page title.
	 */
	public function test_base_orchestration_page_title() {
		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check if the main NV oOS menu exists.
		$this->assertArrayHasKey(
			'wp-mcp-ai-dashboard',
			$submenu,
			'Main NV oOS menu should be registered'
		);

		$page_found = false;
		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( isset( $item[2] ) && 'mcp-ai-orchestration' === $item[2] ) {
				$page_found = true;
				// Page title is in $item[3].
				$this->assertEquals(
					'Orchestration Dashboard',
					$item[3],
					'Base orchestration page title should be "Orchestration Dashboard"'
				);
				break;
			}
		}

		$this->assertTrue(
			$page_found,
			'Base orchestration dashboard menu item should exist to verify page title'
		);
	}

	/**
	 * Test that the orchestration dashboard page renders exactly once.
	 *
	 * Regression guard for the double instantiation of
	 * WP_MCP_AI_Admin_Orchestration_Dashboard: the class file
	 * self-instantiates at the bottom AND the bootstrap loader used to
	 * `new` it again. Two distinct instances register render_dashboard
	 * twice on the same page hook, so WordPress fired it twice and the
	 * dashboard rendered twice.
	 */
	public function test_orchestration_dashboard_renders_once() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Orchestration dashboard class not loaded' );
		}

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		$hook = get_plugin_page_hookname( 'mcp-ai-orchestration', 'wp-mcp-ai-dashboard' );

		global $wp_filter;
		$render_callbacks = 0;
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority_callbacks ) {
				foreach ( $priority_callbacks as $callback ) {
					if (
						isset( $callback['function'] ) &&
						is_array( $callback['function'] ) &&
						2 === count( $callback['function'] ) &&
						'WP_MCP_AI_Admin_Orchestration_Dashboard' === get_class( $callback['function'][0] ) &&
						'render_dashboard' === $callback['function'][1]
					) {
						++$render_callbacks;
					}
				}
			}
		}

		$this->assertSame(
			1,
			$render_callbacks,
			'render_dashboard must be registered exactly once on the orchestration page hook'
		);

		// Fire the page hook and assert the dashboard wrapper renders exactly
		// once — the user-visible symptom of the double render.
		ob_start();
		do_action( $hook );
		$output = ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $output, 'class="wrap wp-mcp-ai-orchestration-dashboard"' ),
			'Orchestration dashboard wrapper must be rendered exactly once'
		);
	}
}
