<?php
/**
 * Tests for ChatGPT Business/Enterprise API key support.
 *
 * Covers centralized header building (OpenAI-Organization, OpenAI-Project),
 * custom base URL endpoint resolution, and API key type settings.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for ChatGPT Business/Enterprise API key support.
 */
class WP_MCP_AI_OpenAI_Business_API_Key_Test extends WP_UnitTestCase {

	/**
	 * Clean up settings after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tearDown();
	}

	/**
	 * Test that default settings include the new Business API key fields.
	 */
	public function test_default_settings_include_business_api_key_fields() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'openai_api_key_type', $defaults );
		$this->assertSame( 'standard', $defaults['openai_api_key_type'] );

		$this->assertArrayHasKey( 'openai_project_id', $defaults );
		$this->assertSame( '', $defaults['openai_project_id'] );

		$this->assertArrayHasKey( 'openai_base_url', $defaults );
		$this->assertSame( '', $defaults['openai_base_url'] );
	}

	/**
	 * Test build_request_headers returns standard headers when no org/project configured.
	 */
	public function test_build_request_headers_standard_headers() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test123' );

		$this->assertSame( 'Bearer sk-test123', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertArrayNotHasKey( 'OpenAI-Organization', $headers );
		$this->assertArrayNotHasKey( 'OpenAI-Project', $headers );
	}

	/**
	 * Test build_request_headers includes OpenAI-Organization header when configured.
	 */
	public function test_build_request_headers_with_organization_id() {
		$settings                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_organization_id'] = 'org-testorg123';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test123' );

		$this->assertSame( 'org-testorg123', $headers['OpenAI-Organization'] );
	}

	/**
	 * Test build_request_headers includes OpenAI-Project header when configured.
	 */
	public function test_build_request_headers_with_project_id() {
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_project_id'] = 'proj_testproject456';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test123' );

		$this->assertSame( 'proj_testproject456', $headers['OpenAI-Project'] );
	}

	/**
	 * Test build_request_headers includes both org and project headers for Business/Enterprise accounts.
	 */
	public function test_build_request_headers_business_account_full_config() {
		$settings                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key_type']    = 'business';
		$settings['openai_organization_id'] = 'org-businessorg';
		$settings['openai_project_id']      = 'proj_businessproject';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-business-key' );

		$this->assertSame( 'Bearer sk-business-key', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'org-businessorg', $headers['OpenAI-Organization'] );
		$this->assertSame( 'proj_businessproject', $headers['OpenAI-Project'] );
	}

	/**
	 * Test build_request_headers with custom content type for multipart uploads.
	 */
	public function test_build_request_headers_custom_content_type() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test123', 'multipart/form-data; boundary=abc123' );

		$this->assertSame( 'multipart/form-data; boundary=abc123', $headers['Content-Type'] );
	}

	/**
	 * Test build_request_headers filter allows third-party modification.
	 */
	public function test_build_request_headers_filter() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		add_filter(
			'wp_mcp_ai_openai_request_headers',
			function ( $headers ) {
				$headers['X-Custom-Header'] = 'custom-value';
				return $headers;
			}
		);

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test123' );

		$this->assertSame( 'custom-value', $headers['X-Custom-Header'] );

		remove_all_filters( 'wp_mcp_ai_openai_request_headers' );
	}

	/**
	 * Test get_base_url returns default when no custom URL configured.
	 */
	public function test_get_base_url_default() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame( 'https://api.openai.com/v1', $client->get_base_url() );
	}

	/**
	 * Test get_base_url returns custom URL when configured.
	 */
	public function test_get_base_url_custom() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_base_url'] = 'https://proxy.example.com/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame( 'https://proxy.example.com/v1', $client->get_base_url() );
	}

	/**
	 * Test get_base_url strips trailing slash.
	 */
	public function test_get_base_url_strips_trailing_slash() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_base_url'] = 'https://proxy.example.com/v1/';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame( 'https://proxy.example.com/v1', $client->get_base_url() );
	}

	/**
	 * Test resolve_endpoint returns default URL when no custom base URL configured.
	 */
	public function test_resolve_endpoint_default() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame(
			'https://api.openai.com/v1/chat/completions',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT )
		);
	}

	/**
	 * Test resolve_endpoint replaces base URL for custom proxy endpoints.
	 */
	public function test_resolve_endpoint_custom_base_url() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_base_url'] = 'https://enterprise-proxy.company.com/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame(
			'https://enterprise-proxy.company.com/v1/chat/completions',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT )
		);
	}

	/**
	 * Test resolve_endpoint works with all major endpoint constants.
	 */
	public function test_resolve_endpoint_all_endpoints() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_base_url'] = 'https://custom.api.example.com/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame(
			'https://custom.api.example.com/v1/responses',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT )
		);

		$this->assertSame(
			'https://custom.api.example.com/v1/files',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT )
		);

		$this->assertSame(
			'https://custom.api.example.com/v1/images/generations',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT )
		);

		$this->assertSame(
			'https://custom.api.example.com/v1/audio/speech',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::AUDIO_SPEECH_ENDPOINT )
		);

		$this->assertSame(
			'https://custom.api.example.com/v1/moderations',
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::MODERATIONS_ENDPOINT )
		);
	}

	/**
	 * Test resolve_endpoint with inline URL string (models endpoint).
	 */
	public function test_resolve_endpoint_inline_url() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_base_url'] = 'https://proxy.test/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame(
			'https://proxy.test/v1/models',
			$client->resolve_endpoint( 'https://api.openai.com/v1/models' )
		);
	}

	/**
	 * Test that Enterprise key type setting is stored and retrievable.
	 */
	public function test_enterprise_api_key_type_setting() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key_type'] = 'enterprise';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$stored = WP_MCP_AI_Admin_Settings::get_settings();

		$this->assertSame( 'enterprise', $stored['openai_api_key_type'] );
	}

	/**
	 * Test that empty organization_id does not add the header.
	 */
	public function test_empty_organization_id_not_in_headers() {
		$settings                           = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_organization_id'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test' );

		$this->assertArrayNotHasKey( 'OpenAI-Organization', $headers );
	}

	/**
	 * Test that empty project_id does not add the header.
	 */
	public function test_empty_project_id_not_in_headers() {
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_project_id'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client  = new WP_MCP_AI_OpenAI_Client();
		$headers = $client->build_request_headers( 'sk-test' );

		$this->assertArrayNotHasKey( 'OpenAI-Project', $headers );
	}

	/**
	 * Test resolve_endpoint with empty base URL setting falls back to default.
	 */
	public function test_resolve_endpoint_empty_base_url_uses_default() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_base_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$client = new WP_MCP_AI_OpenAI_Client();

		$this->assertSame(
			WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT,
			$client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT )
		);
	}

	/**
	 * Test DEFAULT_BASE_URL constant value.
	 */
	public function test_default_base_url_constant() {
		$this->assertSame( 'https://api.openai.com/v1', WP_MCP_AI_OpenAI_Client::DEFAULT_BASE_URL );
	}
}
