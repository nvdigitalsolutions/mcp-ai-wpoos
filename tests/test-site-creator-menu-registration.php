<?php
/**
 * Tests for Site Creator Menu Registration
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test site creator menu registration.
 */
class Test_Site_Creator_Menu_Registration extends WP_UnitTestCase {

	/**
	 * Test that Site Creator menu is registered as a top-level menu.
	 *
	 * This test verifies that the Site Creator page is correctly added as a
	 * top-level menu item in the WordPress admin.
	 */
	public function test_site_creator_registered_as_top_level_menu() {
		global $menu;

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Load the Site Creator Toolkit Settings Page class.
		if ( ! class_exists( 'WP_MCP_AI_Site_Creator_Toolkit_Settings_Page' ) ) {
			$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			} else {
				$this->markTestSkipped( 'Site Creator Toolkit Settings Page class not available' );
			}
		}

		// Trigger admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that Site Creator menu exists.
		$site_creator_found = false;
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $item[2] === 'nvoos-site-creator' ) {
				$site_creator_found = true;
				// Verify menu title.
				$this->assertEquals( 'Site Creator', $item[0] );
				// Verify capability.
				$this->assertEquals( 'manage_options', $item[1] );
				// Verify icon.
				$this->assertEquals( 'dashicons-admin-site-alt3', $item[6] );
				break;
			}
		}

		$this->assertTrue(
			$site_creator_found,
			'Site Creator should be registered as a top-level menu'
		);
	}

	/**
	 * Test that Site Creator has submenu items.
	 *
	 * This test verifies that the Site Creator menu has the expected submenu items.
	 */
	public function test_site_creator_has_submenu_items() {
		global $submenu;

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Trigger admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that Site Creator submenu exists.
		$this->assertArrayHasKey(
			'nvoos-site-creator',
			$submenu,
			'Site Creator submenu should be registered'
		);

		// Check for expected submenu items.
		$expected_items = array(
			'nvoos-site-creator'             => 'Overview',
			'nvoos-site-creator-tools'       => 'Tools',
			'nvoos-site-creator-templates'   => 'Templates',
			'nvoos-site-creator-research'    => 'Research & Add',
			'nvoos-site-creator-consolidate' => 'Consolidate & Add',
		);

		foreach ( $expected_items as $slug => $title ) {
			$found = false;
			foreach ( $submenu['nvoos-site-creator'] as $item ) {
				if ( $item[2] === $slug ) {
					$found = true;
					$this->assertEquals( $title, $item[0], "Submenu item title should be '{$title}'" );
					break;
				}
			}
			$this->assertTrue( $found, "Submenu item '{$slug}' should be registered" );
		}
	}

	/**
	 * Test that Site Creator admin_menu priority is correct.
	 *
	 * This test verifies that the Site Creator menu is registered with the
	 * correct priority (20) to ensure proper menu order.
	 */
	public function test_site_creator_menu_priority() {
		global $wp_filter;

		// Load the Site Creator Toolkit Settings Page class.
		if ( ! class_exists( 'WP_MCP_AI_Site_Creator_Toolkit_Settings_Page' ) ) {
			$this->markTestSkipped( 'Site Creator Toolkit Settings Page class not available' );
		}

		// Check the admin_menu hook priority.
		$this->assertTrue(
			isset( $wp_filter['admin_menu'] ),
			'admin_menu hook should be registered'
		);

		// Find the Site Creator callback in the admin_menu hook.
		$found_priority = null;
		foreach ( $wp_filter['admin_menu']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( isset( $callback['function'][0] ) &&
					$callback['function'][0] instanceof WP_MCP_AI_Site_Creator_Toolkit_Settings_Page &&
					$callback['function'][1] === 'add_settings_page' ) {
					$found_priority = $priority;
					break 2;
				}
			}
		}

		$this->assertNotNull(
			$found_priority,
			'Site Creator add_settings_page callback should be registered'
		);

		$this->assertEquals(
			20,
			$found_priority,
			'Site Creator should register admin_menu with priority 20'
		);
	}
}
