<?php
/**
 * Tests for GitHub OAuth Handler
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Prevent the terminating exit and outbound network calls so the
		// handler can be exercised inside the single-process run.
		add_filter( 'wp_mcp_ai_github_oauth_redirect_terminate', '__return_false' );
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'body'     => wp_json_encode( array( 'access_token' => 'gho_test' ) ),
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
				);
			}
		);

		$redirected_location = null;

		// Mock the redirect to avoid actual redirect.
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirected_location ) {
				$redirected_location = $location;
				// Prevent actual redirect.
				return false;
			}
		);

		$handler->handle_github_oauth_callback();

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_github_oauth_redirect_terminate' );

		// Invalid state must redirect back to the dashboard connections tab.
		$this->assertNotNull( $redirected_location, 'An invalid state must trigger a redirect' );
		$this->assertStringContainsString( 'wp-mcp-ai-dashboard', $redirected_location );
		$this->assertStringContainsString( 'tab=tools', $redirected_location );
	}
}
