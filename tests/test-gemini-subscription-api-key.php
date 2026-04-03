<?php
/**
 * Tests for Gemini Enterprise/Vertex AI API key support.
 *
 * Covers centralized header building, custom base URL endpoint resolution,
 * and API key type settings for the Gemini provider.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for Gemini Enterprise/Vertex AI API key support.
 */
class WP_MCP_AI_Gemini_Subscription_API_Key_Test extends WP_UnitTestCase {

	/**
	 * Clean up settings after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tearDown();
	}

	/**
	 * Test that default settings include the new Gemini subscription fields.
	 */
	public function test_default_settings_include_gemini_subscription_fields() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'gemini_api_key_type', $defaults );
		$this->assertSame( 'standard', $defaults['gemini_api_key_type'] );

		$this->assertArrayHasKey( 'gemini_base_url', $defaults );
		$this->assertSame( '', $defaults['gemini_base_url'] );
	}

	/**
	 * Test build_request_headers returns standard Gemini headers.
	 */
	public function test_build_request_headers_standard_headers() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client  = new WP_MCP_AI_Gemini_Client();
		$headers = $client->build_request_headers( 'AIzaTestKey123' );

		$this->assertSame( 'AIzaTestKey123', $headers['x-goog-api-key'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
	}

	/**
	 * Test build_request_headers filter allows third-party modification.
	 */
	public function test_build_request_headers_filter() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		add_filter(
			'wp_mcp_ai_gemini_request_headers',
			function ( $headers ) {
				$headers['X-Vertex-Region'] = 'us-central1';
				return $headers;
			}
		);

		$client  = new WP_MCP_AI_Gemini_Client();
		$headers = $client->build_request_headers( 'AIzaTest' );

		$this->assertSame( 'us-central1', $headers['X-Vertex-Region'] );

		remove_all_filters( 'wp_mcp_ai_gemini_request_headers' );
	}

	/**
	 * Test get_custom_base_url returns default when no custom URL configured.
	 */
	public function test_get_custom_base_url_default() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_Gemini_Client();

		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta', $client->get_custom_base_url() );
	}

	/**
	 * Test get_custom_base_url returns custom URL when configured.
	 */
	public function test_get_custom_base_url_custom() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_base_url'] = 'https://vertex-proxy.company.com/v1beta';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Gemini_Client();

		$this->assertSame( 'https://vertex-proxy.company.com/v1beta', $client->get_custom_base_url() );
	}

	/**
	 * Test resolve_endpoint returns default URL when no custom base URL configured.
	 */
	public function test_resolve_endpoint_default() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client   = new WP_MCP_AI_Gemini_Client();
		$endpoint = sprintf( WP_MCP_AI_Gemini_Client::API_ENDPOINT, 'gemini-2.5-flash' );

		$this->assertSame( $endpoint, $client->resolve_endpoint( $endpoint ) );
	}

	/**
	 * Test resolve_endpoint replaces base URL for custom Vertex AI endpoints.
	 */
	public function test_resolve_endpoint_custom_base_url() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_base_url'] = 'https://custom-vertex.example.com/v1beta';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Gemini_Client();

		$default_endpoint = sprintf( WP_MCP_AI_Gemini_Client::API_ENDPOINT, 'gemini-2.5-flash' );
		$resolved         = $client->resolve_endpoint( $default_endpoint );

		$this->assertSame(
			'https://custom-vertex.example.com/v1beta/models/gemini-2.5-flash:generateContent',
			$resolved
		);
	}

	/**
	 * Test resolve_endpoint works with list models endpoint.
	 */
	public function test_resolve_endpoint_list_models() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_base_url'] = 'https://proxy.test/v1beta';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Gemini_Client();

		$this->assertSame(
			'https://proxy.test/v1beta/models',
			$client->resolve_endpoint( WP_MCP_AI_Gemini_Client::API_LIST_MODELS )
		);
	}

	/**
	 * Test resolve_endpoint works with corpora endpoint.
	 */
	public function test_resolve_endpoint_corpora() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_base_url'] = 'https://proxy.test/v1beta';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_Gemini_Client();

		$this->assertSame(
			'https://proxy.test/v1beta/corpora',
			$client->resolve_endpoint( WP_MCP_AI_Gemini_Client::API_CORPORA_ENDPOINT )
		);
	}

	/**
	 * Test that Enterprise key type setting is stored and retrievable.
	 */
	public function test_enterprise_api_key_type_setting() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key_type'] = 'enterprise';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$stored = WP_MCP_AI_Admin_Settings::get_settings();

		$this->assertSame( 'enterprise', $stored['gemini_api_key_type'] );
	}

	/**
	 * Test DEFAULT_BASE_URL constant value.
	 */
	public function test_default_base_url_constant() {
		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta', WP_MCP_AI_Gemini_Client::DEFAULT_BASE_URL );
	}

	/**
	 * Test resolve_endpoint with empty base URL setting falls back to default.
	 */
	public function test_resolve_endpoint_empty_base_url_uses_default() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_base_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client   = new WP_MCP_AI_Gemini_Client();
		$endpoint = sprintf( WP_MCP_AI_Gemini_Client::API_ENDPOINT, 'gemini-2.5-flash' );

		$this->assertSame( $endpoint, $client->resolve_endpoint( $endpoint ) );
	}
}
