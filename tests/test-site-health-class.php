<?php
/**
 * Site Health Integration Tests
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for the Site Health integration class.
 */
class WP_MCP_AI_Site_Health_Class_Test extends WP_UnitTestCase {
	/**
	 * Site Health instance.
	 *
	 * @var WP_MCP_AI_Site_Health
	 */
	private $site_health;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->site_health = new WP_MCP_AI_Site_Health();
	}

	/**
	 * Test that Site Health checks properly detect configured OpenAI provider.
	 */
	public function test_api_connectivity_detects_openai_provider() {
		// Set up OpenAI API key in settings.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['openai_api_key'] = 'sk-test-key-1234567890123456789012345678901234567890';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_api_connectivity();

		$this->assertSame( 'good', $result['status'], 'Should show good status with OpenAI configured' );
		$this->assertStringContainsString( 'AI providers are working', $result['label'] );
	}

	/**
	 * Test that Site Health checks properly detect configured Gemini provider.
	 */
	public function test_api_connectivity_detects_gemini_provider() {
		// Set up Gemini API key in settings.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['gemini_api_key'] = 'AIzaSyTest1234567890123456789012345678';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_api_connectivity();

		$this->assertSame( 'good', $result['status'], 'Should show good status with Gemini configured' );
		$this->assertStringContainsString( 'AI providers are working', $result['label'] );
	}

	/**
	 * Test that Site Health checks properly detect configured Ollama provider.
	 */
	public function test_api_connectivity_detects_ollama_provider() {
		// Set up Ollama URL in settings.
		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['ollama_endpoint_url'] = 'http://localhost:11434';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_api_connectivity();

		$this->assertSame( 'good', $result['status'], 'Should show good status with Ollama configured' );
		$this->assertStringContainsString( 'AI providers are working', $result['label'] );
	}

	/**
	 * Test that Site Health checks show critical status when no providers configured.
	 */
	public function test_api_connectivity_shows_critical_when_no_providers() {
		// Clear all provider settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['openai_api_key'] );
		unset( $settings['gemini_api_key'] );
		unset( $settings['ollama_endpoint_url'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_api_connectivity();

		$this->assertSame( 'critical', $result['status'], 'Should show critical status with no providers' );
		$this->assertSame( 'No AI providers configured', $result['label'] );
		$this->assertStringContainsString( 'configure at least one AI provider', $result['description'] );
	}

	/**
	 * Test that model availability check properly detects OpenAI models.
	 */
	public function test_model_availability_detects_openai_models() {
		// Set up OpenAI API key in settings.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['openai_api_key'] = 'sk-test-key-1234567890123456789012345678901234567890';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_model_availability();

		$this->assertSame( 'good', $result['status'], 'Should show good status with OpenAI models available' );
		$this->assertStringContainsString( 'OpenAI GPT', $result['label'] );
	}

	/**
	 * Test that model availability check properly detects Gemini models.
	 */
	public function test_model_availability_detects_gemini_models() {
		// Set up Gemini API key in settings.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['gemini_api_key'] = 'AIzaSyTest1234567890123456789012345678';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_model_availability();

		$this->assertSame( 'good', $result['status'], 'Should show good status with Gemini models available' );
		$this->assertStringContainsString( 'Google Gemini', $result['label'] );
	}

	/**
	 * Test that model availability check properly detects Ollama models.
	 */
	public function test_model_availability_detects_ollama_models() {
		// Set up Ollama URL in settings.
		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['ollama_endpoint_url'] = 'http://localhost:11434';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_model_availability();

		$this->assertSame( 'good', $result['status'], 'Should show good status with Ollama models available' );
		$this->assertStringContainsString( 'Ollama (Local)', $result['label'] );
	}

	/**
	 * Test that model availability check shows critical status when no models available.
	 */
	public function test_model_availability_shows_critical_when_no_models() {
		// Clear all provider settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['openai_api_key'] );
		unset( $settings['gemini_api_key'] );
		unset( $settings['ollama_endpoint_url'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Run the test.
		$result = $this->site_health->test_model_availability();

		$this->assertSame( 'critical', $result['status'], 'Should show critical status with no models' );
		$this->assertSame( 'No AI models available', $result['label'] );
		$this->assertStringContainsString( 'No AI models are currently available', $result['description'] );
	}

	/**
	 * Test that debug fields properly display configured providers.
	 */
	public function test_debug_fields_show_configured_providers() {
		// Set up multiple providers in settings.
		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['openai_api_key']      = 'sk-test-key-1234567890123456789012345678901234567890';
		$settings['gemini_api_key']      = 'AIzaSyTest1234567890123456789012345678';
		$settings['ollama_endpoint_url'] = 'http://localhost:11434';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Get debug info.
		$debug_info = array();
		$debug_info = apply_filters( 'debug_information', $debug_info );

		$this->assertArrayHasKey( 'mcp-ai-wpoos', $debug_info, 'Should have MCP AI debug section' );
		$this->assertArrayHasKey( 'fields', $debug_info['mcp-ai-wpoos'], 'Should have fields array' );
		$this->assertArrayHasKey( 'providers', $debug_info['mcp-ai-wpoos']['fields'], 'Should have providers field' );

		$providers_value = $debug_info['mcp-ai-wpoos']['fields']['providers']['value'];
		$this->assertStringContainsString( 'OpenAI', $providers_value, 'Should list OpenAI' );
		$this->assertStringContainsString( 'Google Gemini', $providers_value, 'Should list Gemini' );
		$this->assertStringContainsString( 'Ollama', $providers_value, 'Should list Ollama' );
	}

	/**
	 * Test that debug fields show "None" when no providers configured.
	 */
	public function test_debug_fields_show_none_when_no_providers() {
		// Clear all provider settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['openai_api_key'] );
		unset( $settings['gemini_api_key'] );
		unset( $settings['ollama_endpoint_url'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Get debug info.
		$debug_info = array();
		$debug_info = apply_filters( 'debug_information', $debug_info );

		$this->assertArrayHasKey( 'mcp-ai-wpoos', $debug_info, 'Should have MCP AI debug section' );
		$providers_value = $debug_info['mcp-ai-wpoos']['fields']['providers']['value'];
		$this->assertSame( 'None', $providers_value, 'Should show "None" when no providers configured' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tearDown();
	}
}
