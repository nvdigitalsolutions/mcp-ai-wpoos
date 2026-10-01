<?php
/**
 * Tests for Mailjet OAuth Handler
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Mailjet OAuth handler functionality.
 */
class Test_Mailjet_OAuth_Handler extends WP_UnitTestCase {
	/**
	 * Test that Mailjet OAuth handler class exists.
	 */
	public function test_mailjet_oauth_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Mailjet_OAuth_Handler' ) );
	}

	/**
	 * Test that Mailjet OAuth handler can be instantiated.
	 */
	public function test_mailjet_oauth_handler_instantiation() {
		$handler = new WP_MCP_AI_Mailjet_OAuth_Handler();
		$this->assertInstanceOf( 'WP_MCP_AI_Mailjet_OAuth_Handler', $handler );
	}

	/**
	 * Test that the redirect host filter allows the Mailjet authorize host.
	 */
	public function test_mailjet_oauth_redirect_host_filter() {
		$handler = new WP_MCP_AI_Mailjet_OAuth_Handler();

		$allowed_hosts = array( 'example.com' );
		$result        = $handler->allow_mailjet_oauth_redirect_host( $allowed_hosts );

		$this->assertContains( 'app.mailjet.com', $result );
		$this->assertContains( 'example.com', $result );
	}

	/**
	 * Test that the redirect host filter respects the authorize endpoint filter.
	 */
	public function test_mailjet_oauth_redirect_host_respects_endpoint_filter() {
		$filter = static function () {
			return 'https://mailjet-enterprise.example.com/oauth/authorize';
		};
		add_filter( 'wp_mcp_ai_mailjet_oauth_authorize_endpoint', $filter );

		$handler = new WP_MCP_AI_Mailjet_OAuth_Handler();
		$result  = $handler->allow_mailjet_oauth_redirect_host( array() );

		remove_filter( 'wp_mcp_ai_mailjet_oauth_authorize_endpoint', $filter );

		$this->assertContains( 'mailjet-enterprise.example.com', $result );
	}
}
