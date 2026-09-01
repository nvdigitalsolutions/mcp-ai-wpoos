<?php
/**
 * Tests for Remote Sites Menu Registration
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test remote sites menu registration order.
 */
class Test_Remote_Sites_Menu_Registration extends WP_UnitTestCase {

	/**
	 * Test that Remote Sites menu is registered as a submenu of Pro Dashboard.
	 *
	 * This test verifies that the Remote Sites page is correctly added as a
	 * submenu item under the Pro Dashboard menu, ensuring the URL format is
	 * correct (/wp-admin/admin.php?page=wp-mcp-ai-remote-sites).
	 */
	public function test_remote_sites_registered_under_pro_dashboard() {
		global $submenu;

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Ensure the Remote Sites admin class has registered its admin_menu hook.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			new WP_MCP_AI_Pro_Remote_Sites_Admin();
		}

		// Trigger admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that Pro Dashboard menu exists.
		$this->assertArrayHasKey(
			'nvoos-pro-dashboard',
			$submenu,
			'Pro Dashboard parent menu should be registered'
		);

		// Check that Remote Sites is registered under Pro Dashboard.
		$remote_sites_found = false;
		if ( isset( $submenu['nvoos-pro-dashboard'] ) ) {
			foreach ( $submenu['nvoos-pro-dashboard'] as $item ) {
				if ( $item[2] === 'wp-mcp-ai-remote-sites' ) {
					$remote_sites_found = true;
					// Verify menu title.
					$this->assertEquals( 'Remote Sites', $item[0] );
					// Verify capability.
					$this->assertEquals( 'manage_options', $item[1] );
					break;
				}
			}
		}

		$this->assertTrue(
			$remote_sites_found,
			'Remote Sites should be registered as a submenu under Pro Dashboard'
		);
	}

	/**
	 * Test that Remote Sites admin_menu priority is correct.
	 *
	 * This test verifies that the Remote Sites menu is registered with the
	 * correct priority (30) to ensure it runs after the Pro Dashboard menu
	 * registration (priority 25).
	 */
	public function test_remote_sites_menu_priority() {
		global $wp_filter;

		// Load the Remote Sites admin class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Remote Sites admin class not available' );
		}

		// Create an instance to register hooks.
		$instance = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		// Check the admin_menu hook priority.
		$this->assertTrue(
			isset( $wp_filter['admin_menu'] ),
			'admin_menu hook should be registered'
		);

		// Find the Remote Sites callback in the admin_menu hook.
		$found_priority = null;
		foreach ( $wp_filter['admin_menu']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				// Closures registered by other plugins cannot be inspected here.
				if ( ! is_array( $callback ) || ! isset( $callback['function'] ) || ! is_array( $callback['function'] ) ) {
					continue;
				}
				if ( isset( $callback['function'][0] ) &&
					$callback['function'][0] instanceof WP_MCP_AI_Pro_Remote_Sites_Admin &&
					$callback['function'][1] === 'add_admin_menu' ) {
					$found_priority = $priority;
					break 2;
				}
			}
		}

		$this->assertNotNull(
			$found_priority,
			'Remote Sites add_admin_menu callback should be registered'
		);

		$this->assertEquals(
			30,
			$found_priority,
			'Remote Sites should register admin_menu with priority 30 (after Pro Dashboard at 25)'
		);
	}
}
