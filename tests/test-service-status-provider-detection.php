<?php
/**
 * Tests for AI-provider detection in the default Service Status sources.
 *
 * @package WP_MCP_AI
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Service_Status_AI_Providers.
 */
class Test_Service_Status_Provider_Detection extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Disable the WP 7.0 connector bridge: the test suite's WP 7.0 shim
		// reports unregistered connectors as "incorrect usage", which the
		// WP test framework converts into failures. Provider detection must
		// be tested against plugin settings only.
		add_filter( 'wp_mcp_ai_use_wp70_bridge', '__return_false' );
		$this->reset_bridge_availability_cache();

		// Start each test with no provider configuration.
		update_option( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME, array() );
		update_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		$this->reset_resolver_cache();
	}

	/**
	 * Reset the WP70 bridge's cached availability flag.
	 *
	 * `WP_MCP_AI_WP70_Bridge::is_available()` memoizes its result for the
	 * lifetime of the request; the opt-out filter added in setUp() only takes
	 * effect once that cache is cleared.
	 */
	private function reset_bridge_availability_cache() {
		if ( ! class_exists( 'WP_MCP_AI_WP70_Bridge' ) ) {
			return;
		}

		$ref = new ReflectionProperty( 'WP_MCP_AI_WP70_Bridge', 'available' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Reset the credential resolver's per-request static cache.
	 *
	 * The resolver memoizes key lookups for the lifetime of the request;
	 * tests in the same process must clear it between scenarios.
	 */
	private function reset_resolver_cache() {
		if ( ! class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			return;
		}

		$ref = new ReflectionProperty( 'WP_MCP_AI_Credential_Resolver', 'key_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, array() );
	}

	/**
	 * Test that no providers reports the not-configured message.
	 */
	public function test_no_providers_reports_not_configured() {
		$source = new WP_MCP_AI_Service_Status_AI_Providers();

		$result = $source->check_health();

		$this->assertSame( 'No AI providers configured.', $result['message'] );
	}

	/**
	 * Test that an OpenAI key stored in settings is detected as configured.
	 */
	public function test_openai_key_in_settings_is_detected() {
		update_option(
			WP_MCP_AI_Admin_Settings_Base::OPTION_NAME,
			array( 'openai_api_key' => 'sk-test-1234567890' )
		);
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		$this->reset_resolver_cache();

		$source = new WP_MCP_AI_Service_Status_AI_Providers();

		$result = $source->check_health();

		$this->assertNotSame( 'No AI providers configured.', $result['message'] );
		$this->assertSame( 'operational', $result['status'] );
	}

	/**
	 * Test that a Gemini key in the credentials option is detected.
	 */
	public function test_gemini_key_in_credentials_is_detected() {
		update_option(
			WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME,
			array( 'gemini_api_key' => 'AIza-test-1234567890' )
		);
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		$this->reset_resolver_cache();

		$source = new WP_MCP_AI_Service_Status_AI_Providers();

		$result = $source->check_health();

		$this->assertNotSame( 'No AI providers configured.', $result['message'] );
		$this->assertSame( 'operational', $result['status'] );
	}

	/**
	 * Test that an Ollama base URL is detected as configured.
	 */
	public function test_ollama_base_url_is_detected() {
		update_option(
			WP_MCP_AI_Admin_Settings_Base::OPTION_NAME,
			array( 'ollama_base_url' => 'http://localhost:11434' )
		);
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();

		$source = new WP_MCP_AI_Service_Status_AI_Providers();

		$result = $source->check_health();

		$this->assertNotSame( 'No AI providers configured.', $result['message'] );
		$this->assertSame( 'operational', $result['status'] );
	}
}
