<?php
/**
 * Tests for Anthropic Claude Team/Enterprise API key support.
 *
 * Covers centralized header building, custom base URL endpoint resolution,
 * and API key type settings for the Anthropic provider.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for Anthropic Claude Team/Enterprise API key support.
 */
class WP_MCP_AI_Anthropic_Subscription_API_Key_Test extends WP_UnitTestCase {

	/**
	 * Clean up settings after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tearDown();
	}

	/**
	 * Test that default settings include the new Anthropic subscription fields.
	 */
	public function test_default_settings_include_anthropic_subscription_fields() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'anthropic_api_key_type', $defaults );
		$this->assertSame( 'standard', $defaults['anthropic_api_key_type'] );

		$this->assertArrayHasKey( 'anthropic_base_url', $defaults );
		$this->assertSame( '', $defaults['anthropic_base_url'] );
	}

	/**
	 * Test build_request_headers returns standard Anthropic headers.
	 */
	public function test_build_request_headers_standard_headers() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client  = new WP_MCP_AI_Anthropic_Client();
		$headers = $client->build_request_headers( 'sk-ant-test123' );

		$this->assertSame( 'sk-ant-test123', $headers['x-api-key'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( '2023-06-01', $headers['anthropic-version'] );
	}

	/**
	 * Test build_request_headers filter allows third-party modification.
	 */
	public function test_build_request_headers_filter() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		add_filter(
			'wp_mcp_ai_anthropic_request_headers',
			function ( $headers ) {
				$headers['X-Custom-Proxy'] = 'proxy-value';
				return $headers;
			}
		);

		$client  = new WP_MCP_AI_Anthropic_Client();
		$headers = $client->build_request_headers( 'sk-ant-test' );

		$this->assertSame( 'proxy-value', $headers['X-Custom-Proxy'] );

		remove_all_filters( 'wp_mcp_ai_anthropic_request_headers' );
	}

	/**
	 * Test get_base_url returns default when no custom URL configured.
	 */
	public function test_get_base_url_default() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_Anthropic_Client();

		$this->assertSame( 'https://api.anthropic.com/v1', $client->get_base_url() );
	}

	/**
	 * Test get_base_url returns custom URL when configured.
	 */
	public function test_get_base_url_custom() {
		$settings                       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['anthropic_base_url'] = 'https://claude-proxy.company.com/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Anthropic_Client();

		$this->assertSame( 'https://claude-proxy.company.com/v1', $client->get_base_url() );
	}

	/**
	 * Test resolve_endpoint returns default URL when no custom base URL configured.
	 */
	public function test_resolve_endpoint_default() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_Anthropic_Client();

		$this->assertSame(
			'https://api.anthropic.com/v1/messages',
			$client->resolve_endpoint( WP_MCP_AI_Anthropic_Client::API_ENDPOINT )
		);
	}

	/**
	 * Test resolve_endpoint replaces base URL for custom proxy endpoints.
	 */
	public function test_resolve_endpoint_custom_base_url() {
		$settings                       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['anthropic_base_url'] = 'https://enterprise.proxy.com/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Anthropic_Client();

		$this->assertSame(
			'https://enterprise.proxy.com/v1/messages',
			$client->resolve_endpoint( WP_MCP_AI_Anthropic_Client::API_ENDPOINT )
		);

		$this->assertSame(
			'https://enterprise.proxy.com/v1/messages/count_tokens',
			$client->resolve_endpoint( WP_MCP_AI_Anthropic_Client::API_COUNT_TOKENS )
		);
	}

	/**
	 * Test that Team key type setting is stored and retrievable.
	 */
	public function test_team_api_key_type_setting() {
		$settings                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['anthropic_api_key_type'] = 'team';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$stored = WP_MCP_AI_Admin_Settings::get_settings();

		$this->assertSame( 'team', $stored['anthropic_api_key_type'] );
	}

	/**
	 * Test DEFAULT_BASE_URL constant value.
	 */
	public function test_default_base_url_constant() {
		$this->assertSame( 'https://api.anthropic.com/v1', WP_MCP_AI_Anthropic_Client::DEFAULT_BASE_URL );
	}

	/**
	 * Test resolve_endpoint with empty base URL setting falls back to default.
	 */
	public function test_resolve_endpoint_empty_base_url_uses_default() {
		$settings                       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['anthropic_base_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Anthropic_Client();

		$this->assertSame(
			WP_MCP_AI_Anthropic_Client::API_ENDPOINT,
			$client->resolve_endpoint( WP_MCP_AI_Anthropic_Client::API_ENDPOINT )
		);
	}
}
