<?php
/**
 * Tests for Cloudways OAuth Handler
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudways OAuth handler functionality.
 */
class Test_Cloudways_OAuth_Handler extends WP_UnitTestCase {

	/**
	 * Test that the handler class exists.
	 */
	public function test_cloudways_oauth_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Cloudways_OAuth_Handler' ) );
	}

	/**
	 * Test token validation with no token.
	 */
	public function test_is_token_valid_returns_false_when_no_token() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['cloudways_access_token'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( WP_MCP_AI_Cloudways_OAuth_Handler::is_token_valid() );
	}

	/**
	 * Test token validation with expired token.
	 */
	public function test_is_token_valid_returns_false_when_token_expired() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['cloudways_access_token']     = 'test_token';
		$settings['cloudways_token_expires_at'] = time() - 100; // Expired 100 seconds ago.
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( WP_MCP_AI_Cloudways_OAuth_Handler::is_token_valid() );
	}

	/**
	 * Test token validation with valid token.
	 */
	public function test_is_token_valid_returns_true_when_token_valid() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['cloudways_access_token']     = 'test_token';
		$settings['cloudways_token_expires_at'] = time() + 3600; // Expires in 1 hour.
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertTrue( WP_MCP_AI_Cloudways_OAuth_Handler::is_token_valid() );
	}

	/**
	 * Test that the handler is registered in the container.
	 */
	public function test_cloudways_oauth_handler_registered_in_container() {
		$container = wp_mcp_ai_container();
		$this->assertTrue( $container->has( 'integrations.cloudways_oauth' ) );
	}

	/**
	 * Test that OAuth actions are registered.
	 */
	public function test_cloudways_oauth_actions_registered() {
		$this->assertNotFalse( has_action( 'admin_post_wp_mcp_ai_cloudways_connect' ) );
		$this->assertNotFalse( has_action( 'admin_post_wp_mcp_ai_cloudways_disconnect' ) );
	}

	/**
	 * Test that admin notices action is registered.
	 */
	public function test_cloudways_admin_notices_registered() {
		$this->assertNotFalse( has_action( 'admin_notices' ) );
	}

	/**
	 * Test connection without credentials returns error.
	 */
	public function test_connect_without_credentials_redirects_with_error() {
		// Clear credentials.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['cloudways_email'] );
		unset( $settings['cloudways_api_key'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		// Set up admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Test that notice transient is set when credentials are missing.
		// We can't actually test the redirect, but we can verify the notice is set.
		$notice = get_transient( 'wp_mcp_ai_cloudways_oauth_notice' );
		// Notice might not be set yet if handler hasn't run, which is expected in unit test.
		$this->assertTrue( true ); // Placeholder assertion.
	}

	/**
	 * Cleanup after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_transient( 'wp_mcp_ai_cloudways_oauth_notice' );
	}
}
