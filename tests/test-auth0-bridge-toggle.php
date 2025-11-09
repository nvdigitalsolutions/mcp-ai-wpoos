<?php
/**
 * Tests for Auth0 GitHub Bridge Toggle Functionality
 *
 * @package WP_MCP_AI
 */

/**
 * Test Auth0 GitHub Bridge toggle feature.
 */
class Test_Auth0_Bridge_Toggle extends WP_UnitTestCase {

	/**
	 * Test that toggle handler requires manage_options capability.
	 */
	public function test_toggle_requires_manage_options() {
		// Create a subscriber (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up the AJAX request.
		$_POST['nonce']   = wp_create_nonce( 'wp-mcp-ai-auth0-setup' );
		$_POST['enabled'] = '1';

		// Create instance and call handler.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Capture output.
		ob_start();
		$auth0_setup->handle_toggle_bridge();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

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

		// Set up the AJAX request with invalid nonce.
		$_POST['nonce']   = 'invalid_nonce';
		$_POST['enabled'] = '1';

		// Create instance and call handler.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Capture output.
		ob_start();
		$auth0_setup->handle_toggle_bridge();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

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

		// Set up the AJAX request with valid nonce.
		$_POST['nonce']   = wp_create_nonce( 'wp-mcp-ai-auth0-setup' );
		$_POST['enabled'] = '1';

		// Create instance and call handler.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Capture output.
		ob_start();
		$auth0_setup->handle_toggle_bridge();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

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

		// Set up the AJAX request to disable.
		$_POST['nonce']   = wp_create_nonce( 'wp-mcp-ai-auth0-setup' );
		$_POST['enabled'] = '0';

		// Create instance and call handler.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Capture output.
		ob_start();
		$auth0_setup->handle_toggle_bridge();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

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

		$this->assertTrue(
			isset( $wp_filter['wp_ajax_wp_mcp_ai_toggle_auth0_bridge'] ),
			'wp_ajax_wp_mcp_ai_toggle_auth0_bridge action should be registered'
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up $_POST.
		unset( $_POST['nonce'] );
		unset( $_POST['enabled'] );
	}
}
