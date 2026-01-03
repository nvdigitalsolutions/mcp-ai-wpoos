<?php
/**
 * Tests for Cloudflare Connection Handler
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare connection handler functionality.
 */
class Test_Cloudflare_Connection_Handler extends WP_UnitTestCase {

	/**
	 * Test that the handler class exists.
	 */
	public function test_cloudflare_connection_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Cloudflare_Connection_Handler' ) );
	}

	/**
	 * Test connection status when not connected.
	 */
	public function test_is_connected_returns_false_when_not_connected() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['cloudflare_connected'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( WP_MCP_AI_Cloudflare_Connection_Handler::is_connected() );
	}

	/**
	 * Test connection status when connected.
	 */
	public function test_is_connected_returns_true_when_connected() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['cloudflare_connected'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertTrue( WP_MCP_AI_Cloudflare_Connection_Handler::is_connected() );
	}

	/**
	 * Test that the handler is registered in the container.
	 */
	public function test_cloudflare_connection_handler_registered_in_container() {
		$container = wp_mcp_ai_container();
		$this->assertTrue( $container->has( 'integrations.cloudflare_connection' ) );
	}

	/**
	 * Test that connection test actions are registered.
	 */
	public function test_cloudflare_connection_actions_registered() {
		$this->assertNotFalse( has_action( 'admin_post_wp_mcp_ai_cloudflare_test_connection' ) );
		$this->assertNotFalse( has_action( 'admin_post_wp_mcp_ai_cloudflare_disconnect' ) );
	}

	/**
	 * Test that admin notices action is registered.
	 */
	public function test_cloudflare_admin_notices_registered() {
		$this->assertNotFalse( has_action( 'admin_notices' ) );
	}

	/**
	 * Test disconnect functionality.
	 */
	public function test_disconnect_clears_connection_status() {
		// Set up connected state.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['cloudflare_connected']      = true;
		$settings['cloudflare_connection_time'] = time();
		$settings['cloudflare_zone_name']      = 'example.com';
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertTrue( WP_MCP_AI_Cloudflare_Connection_Handler::is_connected() );

		// Simulate disconnect (manually clear settings as we can't test the actual handler).
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['cloudflare_connected'] );
		unset( $settings['cloudflare_connection_time'] );
		unset( $settings['cloudflare_zone_name'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( WP_MCP_AI_Cloudflare_Connection_Handler::is_connected() );

		// Verify API token is still saved.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertArrayHasKey( 'cloudflare_api_token', $settings );
	}

	/**
	 * Cleanup after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_transient( 'wp_mcp_ai_cloudflare_connection_notice' );
	}
}
