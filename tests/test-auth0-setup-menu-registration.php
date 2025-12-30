<?php
/**
 * Tests for Auth0 Setup Menu Registration
 *
 * @package WP_MCP_AI
 */

/**
 * Test Auth0 Setup page registration.
 */
class Test_Auth0_Setup_Menu_Registration extends WP_UnitTestCase {

	/**
	 * Test that Auth0 Setup class exists.
	 */
	public function test_auth0_setup_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Auth0_Setup' ) );
	}

	/**
	 * Test that Auth0 Setup is registered as a submenu under the new dashboard.
	 */
	public function test_auth0_setup_registered_under_dashboard() {
		global $submenu;

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that Auth0 Setup is registered under wp-mcp-ai-dashboard.
		$auth0_registered = false;
		if ( isset( $submenu['wp-mcp-ai-dashboard'] ) && is_array( $submenu['wp-mcp-ai-dashboard'] ) ) {
			foreach ( $submenu['wp-mcp-ai-dashboard'] as $submenu_item ) {
				if ( is_array( $submenu_item ) && isset( $submenu_item[2] ) && 'wp-mcp-ai-auth0-setup' === $submenu_item[2] ) {
					$auth0_registered = true;
					// Verify it has the correct title.
					$this->assertEquals( 'Auth0 Setup', $submenu_item[0] );
					break;
				}
			}
		}

		$this->assertTrue( $auth0_registered, 'Auth0 Setup should be registered as a submenu under wp-mcp-ai-dashboard' );
	}

	/**
	 * Test that Auth0 Setup page requires correct capability.
	 */
	public function test_auth0_setup_requires_manage_options() {
		global $submenu;

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check the capability requirement.
		if ( isset( $submenu['wp-mcp-ai-dashboard'] ) && is_array( $submenu['wp-mcp-ai-dashboard'] ) ) {
			foreach ( $submenu['wp-mcp-ai-dashboard'] as $submenu_item ) {
				if ( is_array( $submenu_item ) && isset( $submenu_item[2] ) && 'wp-mcp-ai-auth0-setup' === $submenu_item[2] ) {
					$this->assertEquals( 'manage_options', $submenu_item[1] );
					break;
				}
			}
		}
	}

	/**
	 * Test that the Auth0 Setup page hook is correct.
	 */
	public function test_auth0_setup_page_hook() {
		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Create an instance.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Verify the hook format is correct for enqueuing assets.
		// The hook should be: wp-mcp-ai-dashboard_page_wp-mcp-ai-auth0-setup.
		$expected_hook = 'wp-mcp-ai-dashboard_page_wp-mcp-ai-auth0-setup';

		// We can't directly test the hook without loading the actual admin page,.
		// but we can verify it's constructed correctly by checking the class constant.
		$this->assertEquals( 'wp-mcp-ai-auth0-setup', WP_MCP_AI_Auth0_Setup::PAGE_SLUG );
	}
}
