<?php
/**
 * Tests for Auth0 GitHub Bridge Toggle Functionality
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Auth0 GitHub Bridge toggle feature.
 */
class Test_Auth0_Bridge_Toggle extends WP_UnitTestCase {

	/**
	 * Invoke the toggle handler the way an admin-ajax request would, without
	 * killing the phpunit process.
	 *
	 * The handler ends via wp_send_json_*(). WP 6.9's wp_send_json() contains
	 * a bare die() when wp_doing_ajax() is false, which would terminate the
	 * whole suite. The wp_doing_ajax filter routes termination through
	 * wp_die(), which the test bootstrap converts into a catchable
	 * WPDieException. The echoed JSON is captured for assertions.
	 *
	 * @param string $nonce   Nonce to submit.
	 * @param string $enabled '1' or '0' toggle value.
	 * @return array Decoded JSON response body.
	 */
	protected function invoke_toggle_handler( $nonce, $enabled ) {
		$_POST['nonce']        = $nonce;
		$_POST['enabled']      = $enabled;
		// check_ajax_referer() reads $_REQUEST, not $_POST.
		$_REQUEST['nonce']     = $nonce;
		$_REQUEST['enabled']   = $enabled;

		add_filter( 'wp_doing_ajax', '__return_true' );

		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		ob_start();
		try {
			$auth0_setup->handle_toggle_bridge();
		} catch ( WPDieException $e ) {
			// Expected — wp_send_json_*() terminates via wp_die().
			unset( $e );
		}
		$response = ob_get_clean();

		remove_filter( 'wp_doing_ajax', '__return_true' );

		return json_decode( $response, true );
	}

	/**
	 * Test that toggle handler requires manage_options capability.
	 */
	public function test_toggle_requires_manage_options() {
		// Create a subscriber (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$data = $this->invoke_toggle_handler( wp_create_nonce( 'wp-mcp-ai-auth0-setup' ), '1' );

		// Verify permission error.
		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Insufficient permissions', $data['data']['message'] );
	}

	/**
	 * Test that toggle handler requires valid nonce.
	 */
	public function test_toggle_requires_valid_nonce() {
		// Create an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$data = $this->invoke_toggle_handler( 'invalid_nonce', '1' );

		// Verify nonce error.
		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Invalid security token', $data['data']['message'] );
	}

	/**
	 * Test that toggle handler successfully enables the bridge.
	 */
	public function test_toggle_enables_bridge() {
		// Create an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$data = $this->invoke_toggle_handler( wp_create_nonce( 'wp-mcp-ai-auth0-setup' ), '1' );

		// Verify success.
		$this->assertTrue( $data['success'] );
		$this->assertStringContainsString( 'enabled successfully', $data['data']['message'] );
		$this->assertTrue( $data['data']['enabled'] );

		// Verify setting was saved.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue( ! empty( $settings['enable_auth0_github_bridge'] ) );
	}

	/**
	 * Test that toggle handler successfully disables the bridge.
	 */
	public function test_toggle_disables_bridge() {
		// Create an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// First, enable the bridge.
		$settings                               = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$settings['enable_auth0_github_bridge'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$data = $this->invoke_toggle_handler( wp_create_nonce( 'wp-mcp-ai-auth0-setup' ), '0' );

		// Verify success.
		$this->assertTrue( $data['success'] );
		$this->assertStringContainsString( 'disabled successfully', $data['data']['message'] );
		$this->assertFalse( $data['data']['enabled'] );

		// Verify setting was saved.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse( ! empty( $settings['enable_auth0_github_bridge'] ) );
	}

	/**
	 * Test that AJAX action is properly registered.
	 */
	public function test_ajax_action_registered() {
		global $wp_filter;

		// The AJAX hook is registered in the constructor; instantiate the class
		// so the registration is present in this test's hook table (the hook
		// table is restored between tests).
		new WP_MCP_AI_Auth0_Setup();

		$this->assertTrue(
			isset( $wp_filter['wp_ajax_wp_mcp_ai_toggle_auth0_bridge'] ),
			'wp_ajax_wp_mcp_ai_toggle_auth0_bridge action should be registered'
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up $_POST.
		unset( $_POST['nonce'] );
		unset( $_POST['enabled'] );

		parent::tearDown();
	}
}
