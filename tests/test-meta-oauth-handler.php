<?php
/**
 * Tests for Meta OAuth Handler
 *
 * @package WP_MCP_AI
 */

/**
 * Test Meta OAuth handler functionality.
 */
class Test_Meta_OAuth_Handler extends WP_UnitTestCase {
	/**
	 * Test that Meta OAuth handler class exists.
	 */
	public function test_meta_oauth_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Meta_OAuth_Handler' ) );
	}

	/**
	 * Test that Meta OAuth handler can be instantiated.
	 */
	public function test_meta_oauth_handler_instantiation() {
		$handler = new WP_MCP_AI_Meta_OAuth_Handler();
		$this->assertInstanceOf( 'WP_MCP_AI_Meta_OAuth_Handler', $handler );
	}

	/**
	 * Test that Meta OAuth redirect host filter is registered.
	 */
	public function test_meta_oauth_redirect_host_filter() {
		$handler = new WP_MCP_AI_Meta_OAuth_Handler();

		$allowed_hosts = array( 'example.com' );
		$result        = $handler->allow_meta_oauth_redirect_host( $allowed_hosts );

		$this->assertContains( 'www.facebook.com', $result );
	}

	/**
	 * Test that OAuth start requires manage_options capability.
	 */
	public function test_oauth_start_requires_capability() {
		// Create a user without manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$handler = new WP_MCP_AI_Meta_OAuth_Handler();

		// Set up nonce.
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_meta_oauth_start' );

		$this->expectException( 'WPDieException' );
		$handler->handle_meta_oauth_start();
	}

	/**
	 * Test OAuth callback state validation.
	 */
	public function test_oauth_callback_validates_state() {
		// Create an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$handler = new WP_MCP_AI_Meta_OAuth_Handler();

		// Set invalid state in request.
		$_GET['state'] = 'invalid_state';
		$_GET['code']  = 'test_code';

		// Mock the redirect to avoid actual redirect.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				// Verify redirect location contains settings page.
				$this->assertStringContainsString( 'wp-mcp-ai-dashboard', $location );
				// Prevent actual redirect.
				return false;
			}
		);

		// This should redirect due to invalid state.
		$this->expectException( 'WPDieException' );
		$handler->handle_meta_oauth_callback();
	}

	/**
	 * Test that Meta OAuth constants are defined correctly.
	 */
	public function test_meta_oauth_constants() {
		$this->assertStringContainsString( 'facebook.com', WP_MCP_AI_Meta_OAuth_Handler::META_OAUTH_AUTHORIZE_ENDPOINT );
		$this->assertStringContainsString( 'graph.facebook.com', WP_MCP_AI_Meta_OAuth_Handler::META_OAUTH_TOKEN_ENDPOINT );
		$this->assertStringContainsString( 'graph.facebook.com', WP_MCP_AI_Meta_OAuth_Handler::META_GRAPH_API_BASE );
	}

	/**
	 * Test that Meta OAuth scopes include required permissions.
	 */
	public function test_meta_oauth_scopes() {
		$scopes = WP_MCP_AI_Meta_OAuth_Handler::META_OAUTH_SCOPES;

		$this->assertStringContainsString( 'pages_manage_posts', $scopes );
		$this->assertStringContainsString( 'instagram_basic', $scopes );
		$this->assertStringContainsString( 'instagram_content_publish', $scopes );
	}
}
