<?php
/**
 * Tests for GitHub OAuth Handler
 *
 * @package WP_MCP_AI
 */

/**
 * Test GitHub OAuth handler functionality.
 */
class Test_Github_OAuth_Handler extends WP_UnitTestCase {
	/**
	 * Test that GitHub OAuth handler class exists.
	 */
	public function test_github_oauth_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Github_OAuth_Handler' ) );
	}

	/**
	 * Test that GitHub OAuth handler can be instantiated.
	 */
	public function test_github_oauth_handler_instantiation() {
		$handler = new WP_MCP_AI_Github_OAuth_Handler();
		$this->assertInstanceOf( 'WP_MCP_AI_Github_OAuth_Handler', $handler );
	}

	/**
	 * Test that GitHub OAuth redirect host filter is registered.
	 */
	public function test_github_oauth_redirect_host_filter() {
		$handler = new WP_MCP_AI_Github_OAuth_Handler();

		$allowed_hosts = array( 'example.com' );
		$result        = $handler->allow_github_oauth_redirect_host( $allowed_hosts );

		$this->assertContains( 'github.com', $result );
	}

	/**
	 * Test that OAuth start requires manage_options capability.
	 */
	public function test_oauth_start_requires_capability() {
		// Create a user without manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$handler = new WP_MCP_AI_Github_OAuth_Handler();

		// Set up nonce.
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_github_oauth_start' );

		$this->expectException( 'WPDieException' );
		$handler->handle_github_oauth_start();
	}

	/**
	 * Test OAuth callback state validation.
	 */
	public function test_oauth_callback_validates_state() {
		// Create an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$handler = new WP_MCP_AI_Github_OAuth_Handler();

		// Set invalid state in request.
		$_GET['state'] = 'invalid_state';
		$_GET['code']  = 'test_code';

		// Mock the redirect to avoid actual redirect.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				// Verify redirect location contains settings page.
				$this->assertStringContainsString( 'wp-mcp-ai-settings', $location );
				// Prevent actual redirect.
				return false;
			}
		);

		// This should redirect due to invalid state.
		$this->expectException( 'WPDieException' );
		$handler->handle_github_oauth_callback();
	}
}
