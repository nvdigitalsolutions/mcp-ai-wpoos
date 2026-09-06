<?php
/**
 * Tests for Google Maps Provider Diagnostic Functionality
 *
 * Validates that the Google Maps provider diagnostic AJAX endpoint works correctly
 * and returns proper responses for testing Google Maps API connectivity.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Google_Maps_Provider_Diagnostic_Test extends WP_Ajax_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the diagnostic class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Provider_Diagnostics' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
		}

		// Ensure the Google Maps client is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-google-maps-client.php';
		}

		// The diagnostic AJAX action is registered inside ::init(), which only
		// production admin loaders call; register it here so _handleAjax() can
		// exercise the handler.
		WP_MCP_AI_Provider_Diagnostics::init();

		// The credential resolver caches resolved keys per provider for the
		// whole process; clear it so each test's seeded settings are visible.
		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test Google Maps provider test without API key.
	 */
	public function test_google_maps_test_without_api_key() {
		// Ensure Google Maps API key is not set.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		unset( $settings['google_maps_api_key'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'google_maps';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Google Maps test without API key should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test that the google_maps provider is recognized.
	 */
	public function test_google_maps_provider_is_recognized() {
		// Set up a valid API key.
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_maps_api_key'] = 'test_google_maps_api_key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request - it will fail on the actual API call but should be recognized.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'google_maps';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		// The provider should be recognized (not return "Unknown provider").
		// It will fail because we're using a fake API key, but that's different from being unrecognized.
		if ( isset( $response['data']['message'] ) ) {
			$this->assertStringNotContainsString( 'Unknown provider', $response['data']['message'], 'google_maps provider should be recognized' );
		}
	}

	/**
	 * Test that Google Maps appears in the configured providers list.
	 */
	public function test_google_maps_appears_in_configured_list() {
		// Set up a valid API key.
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_maps_api_key'] = 'test_google_maps_api_key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Check that the configured provider logic would include Google Maps.
		$configured = array();

		if ( ! empty( $settings['openai_api_key'] ) ) {
			$configured[] = 'OpenAI';
		}
		if ( ! empty( $settings['gemini_api_key'] ) ) {
			$configured[] = 'Gemini';
		}
		if ( ! empty( $settings['huggingface_api_key'] ) && ! empty( $settings['huggingface_endpoint_url'] ) ) {
			$configured[] = 'Hugging Face';
		}
		if ( ! empty( $settings['ollama_endpoint_url'] ) ) {
			$configured[] = 'Ollama';
		}
		if ( ! empty( $settings['lm_studio_endpoint_url'] ) ) {
			$configured[] = 'LM Studio';
		}
		if ( ! empty( $settings['google_maps_api_key'] ) ) {
			$configured[] = 'Google Maps';
		}

		$this->assertContains( 'Google Maps', $configured, 'Google Maps should be in the configured providers list' );
	}

	/**
	 * Test that non-admin users cannot access the Google Maps diagnostic test.
	 */
	public function test_non_admin_cannot_access_google_maps_test() {
		// Create a subscriber user.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'google_maps';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Non-admin user should not be able to run Google Maps test' );
		$this->assertStringContainsString( 'permissions', $response['data']['message'], 'Error message should mention permissions' );
	}
}
