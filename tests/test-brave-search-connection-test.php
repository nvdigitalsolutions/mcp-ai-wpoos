<?php
/**
 * Test Brave Search connection test functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Brave Search Connection Test.
 */
class Test_Brave_Search_Connection_Test extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an admin user.
		$this->admin_user = $this->as_admin();

		// Ensure admin classes are loaded.
		if ( ! did_action( 'admin_init' ) ) {
			do_action( 'admin_init' );
		}
	}

	/**
	 * Test that the Brave Search AJAX handler is registered.
	 */
	public function test_brave_search_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_brave_search_connection' ),
			'Brave Search connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that the test button is rendered even without saved API key.
	 */
	public function test_brave_search_test_button_renders_without_saved_key() {
		// Clear any saved API key.
		$settings                         = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['brave_search_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the brave_search connection page.
		$_GET['connection'] = 'brave_search';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-brave-search-connection',
			$output,
			'Test button should be rendered even without saved API key'
		);

		// Check that the test button has correct attributes.
		$this->assertStringContainsString(
			'id="wp-mcp-ai-test-brave-search-connection"',
			$output,
			'Test button should have correct ID'
		);
		$this->assertStringContainsString(
			'class="button button-secondary"',
			$output,
			'Test button should have correct classes'
		);

		// Check that the result span is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-brave-search-test-result',
			$output,
			'Result span should be rendered'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test that the test button is rendered with saved API key.
	 */
	public function test_brave_search_test_button_renders_with_saved_key() {
		// Set a saved API key.
		$settings                         = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['brave_search_api_key'] = 'test-api-key-12345';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the brave_search connection page.
		$_GET['connection'] = 'brave_search';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-brave-search-connection',
			$output,
			'Test button should be rendered with saved API key'
		);

		// Check that there's no conditional message about needing to save first.
		$this->assertStringNotContainsString(
			'save settings to enable the connection test',
			$output,
			'Old conditional message should not be present'
		);

		// Check that the new description is present.
		$this->assertStringContainsString(
			'You can test before saving',
			$output,
			'New description should indicate testing is possible before saving'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test AJAX handler with missing API key.
	 */
	public function test_brave_search_ajax_handler_requires_api_key() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_brave_search_connection',
			array(
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
				'api_key' => '',
			)
		);

		// Verify API key error.
		$this->assertFalse( $response['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $response['data'], 'Response should have error message' );
		$this->assertStringContainsString(
			'API key',
			$response['data']['message'],
			'Error message should mention API key'
		);
	}

	/**
	 * Tear down the test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_GET['connection'] );
		parent::tearDown();
	}
}
