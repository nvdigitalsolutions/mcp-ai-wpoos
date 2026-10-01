<?php
/**
 * Tests for QuickBooks OAuth Handler
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test QuickBooks OAuth handler functionality.
 */
class Test_QuickBooks_OAuth_Handler extends WP_UnitTestCase {
	/**
	 * Test that QuickBooks OAuth handler class exists.
	 */
	public function test_quickbooks_oauth_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_QuickBooks_OAuth_Handler' ) );
	}

	/**
	 * Test that QuickBooks OAuth handler can be instantiated.
	 */
	public function test_quickbooks_oauth_handler_instantiation() {
		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$this->assertInstanceOf( 'WP_MCP_AI_QuickBooks_OAuth_Handler', $handler );
	}

	/**
	 * Test that the redirect host filter allows the Intuit authorize host.
	 */
	public function test_quickbooks_oauth_redirect_host_filter() {
		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();

		$allowed_hosts = array( 'example.com' );
		$result        = $handler->allow_quickbooks_oauth_redirect_host( $allowed_hosts );

		$this->assertContains( 'appcenter.intuit.com', $result );
		$this->assertContains( 'example.com', $result );
	}

	/**
	 * Test that the redirect host filter respects the authorize endpoint filter.
	 */
	public function test_quickbooks_oauth_redirect_host_respects_endpoint_filter() {
		$filter = static function () {
			return 'https://qb-enterprise.example.com/connect/oauth2';
		};
		add_filter( 'wp_mcp_ai_quickbooks_oauth_authorize_endpoint', $filter );

		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$result  = $handler->allow_quickbooks_oauth_redirect_host( array() );

		remove_filter( 'wp_mcp_ai_quickbooks_oauth_authorize_endpoint', $filter );

		$this->assertContains( 'qb-enterprise.example.com', $result );
	}
}
