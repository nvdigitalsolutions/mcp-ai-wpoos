<?php
/**
 * Tests for Provider Diagnostic Page Functionality
 *
 * Validates that the provider diagnostic AJAX endpoints work correctly
 * and return proper responses for testing AI provider connectivity.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Provider_Diagnostic_Endpoints_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	public function setUp(): void {
		parent::setUp();

		// Ensure the diagnostic class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Provider_Diagnostics' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the diagnostic AJAX action for testing providers exists.
	 */
	public function test_provider_test_ajax_action_exists() {
		$this->assertTrue( has_action( 'wp_ajax_wp_mcp_ai_test_provider' ) !== false, 'Provider test AJAX action should be registered' );
	}

	/**
	 * Test that the diagnostic page is registered.
	 */
	public function test_diagnostic_page_is_registered() {
		global $submenu;

		// Trigger admin_menu action to ensure pages are registered.
		set_current_screen( 'tools.php' );
		do_action( 'admin_menu' );

		// Check if the diagnostic page is registered under Tools menu.
		$this->assertArrayHasKey( 'tools.php', $submenu, 'Tools submenu should exist' );

		// Find the provider diagnostic page in the submenu.
		$found = false;
		foreach ( $submenu['tools.php'] as $item ) {
			if ( isset( $item[2] ) && 'wp-mcp-ai-provider-diagnostic' === $item[2] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Provider diagnostic page should be registered under Tools menu' );
	}

	/**
	 * Test provider test with missing provider parameter.
	 */
	public function test_provider_test_missing_parameter() {
		// Simulate AJAX request without provider parameter.
		$_POST['action'] = 'wp_mcp_ai_test_provider';
		$_POST['nonce']  = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Request without provider parameter should fail' );
		$this->assertStringContainsString( 'required', $response['data']['message'], 'Error message should mention required parameter' );
	}

	/**
	 * Test provider test with unknown provider.
	 */
	public function test_provider_test_unknown_provider() {
		// Simulate AJAX request with unknown provider.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'unknown_provider';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Request with unknown provider should fail' );
		$this->assertStringContainsString( 'Unknown', $response['data']['message'], 'Error message should mention unknown provider' );
	}

	/**
	 * Test OpenAI provider test without API key.
	 */
	public function test_openai_test_without_api_key() {
		// Ensure OpenAI API key is not set.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		unset( $settings['openai_api_key'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'OpenAI test without API key should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test Gemini provider test without API key.
	 */
	public function test_gemini_test_without_api_key() {
		// Ensure Gemini API key is not set.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		unset( $settings['gemini_api_key'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'gemini';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Gemini test without API key should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test Ollama provider test without endpoint URL.
	 */
	public function test_ollama_test_without_endpoint() {
		// Ensure Ollama endpoint is not set.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		unset( $settings['ollama_endpoint_url'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'ollama';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Ollama test without endpoint should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test LM Studio provider test without endpoint URL.
	 */
	public function test_lm_studio_test_without_endpoint() {
		// Ensure LM Studio endpoint is not set.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		unset( $settings['lm_studio_endpoint_url'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'lm_studio';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'LM Studio test without endpoint should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test that non-admin users cannot access the diagnostic test.
	 */
	public function test_non_admin_cannot_access_test() {
		// Create a subscriber user.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Non-admin user should not be able to run tests' );
		$this->assertStringContainsString( 'permissions', $response['data']['message'], 'Error message should mention permissions' );
	}
}
