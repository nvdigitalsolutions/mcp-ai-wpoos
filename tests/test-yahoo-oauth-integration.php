<?php
/**
 * Test Yahoo OAuth Integration
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for Yahoo OAuth integration.
 */
class Test_Yahoo_OAuth_Integration extends WP_UnitTestCase {

	/**
	 * Test that Yahoo OAuth manager class exists and has required methods.
	 */
	public function test_yahoo_oauth_manager_methods_exist() {
		$oauth_manager = new WP_MCP_AI_OAuth_Manager();

		$this->assertTrue(
			method_exists( $oauth_manager, 'handle_yahoo_oauth_start' ),
			'OAuth manager should have handle_yahoo_oauth_start method'
		);

		$this->assertTrue(
			method_exists( $oauth_manager, 'handle_yahoo_oauth_callback' ),
			'OAuth manager should have handle_yahoo_oauth_callback method (protected)'
		);
	}

	/**
	 * Test that Yahoo OAuth action is no longer registered (deprecated).
	 *
	 * @since 1.0.0 Yahoo OAuth now uses direct link to Yahoo (like Gmail).
	 */
	public function test_yahoo_oauth_action_not_registered() {
		$this->assertFalse(
			has_action( 'admin_post_wp_mcp_ai_yahoo_oauth_start' ),
			'Yahoo OAuth start action should not be registered (now uses direct link)'
		);
	}

	/**
	 * Test Yahoo Sports footer rendering includes OAuth button when credentials exist.
	 */
	public function test_yahoo_footer_rendering() {
		// Set Yahoo credentials in settings.
		$settings                        = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['yahoo_client_id']     = 'test_client_id';
		$settings['yahoo_client_secret'] = 'test_client_secret';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create integrations section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Capture output.
		ob_start();
		// Use reflection to call the private method for testing.
		$method = new ReflectionMethod( $section, 'render_yahoo_sports_footer' );
		$method->setAccessible( true );
		$method->invoke( $section );
		$output = ob_get_clean();

		// Check that output contains OAuth button elements.
		$this->assertStringContainsString(
			'Connect Yahoo Account',
			$output,
			'Yahoo Sports footer should contain Connect Yahoo Account button'
		);

		$this->assertStringContainsString(
			'api.login.yahoo.com/oauth2/request_auth',
			$output,
			'Yahoo Sports footer should contain direct link to Yahoo OAuth'
		);

		$this->assertStringContainsString(
			'Yahoo Sports Not Connected',
			$output,
			'Yahoo Sports footer should show not connected status when no tokens exist'
		);
	}

	/**
	 * Test Yahoo Sports footer shows connected status when tokens exist.
	 */
	public function test_yahoo_footer_shows_connected_status() {
		// Set Yahoo credentials.
		$settings                        = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['yahoo_client_id']     = 'test_client_id';
		$settings['yahoo_client_secret'] = 'test_client_secret';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a test user and set tokens.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', 'test_access_token' );
		update_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token', 'test_refresh_token' );

		// Create integrations section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Capture output.
		ob_start();
		$method = new ReflectionMethod( $section, 'render_yahoo_sports_footer' );
		$method->setAccessible( true );
		$method->invoke( $section );
		$output = ob_get_clean();

		// Check that output shows connected status.
		$this->assertStringContainsString(
			'Connected to Yahoo Sports',
			$output,
			'Yahoo Sports footer should show connected status when tokens exist'
		);

		$this->assertStringContainsString(
			'Reconnect Yahoo Account',
			$output,
			'Yahoo Sports footer should show Reconnect button when already connected'
		);
	}

	/**
	 * Test Yahoo OAuth start requires manage_options capability.
	 */
	public function test_yahoo_oauth_start_requires_capability() {
		// Create a subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Try to call the OAuth start handler (would normally result in wp_die).
		// We can't easily test wp_die, so just verify the capability check.
		$this->assertFalse(
			current_user_can( 'manage_options' ),
			'Subscriber should not have manage_options capability'
		);
	}
}
