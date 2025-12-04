<?php
/**
 * Test Brave Search connection test functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Brave Search Connection Test.
 */
class Test_Brave_Search_Connection_Test extends WP_UnitTestCase {

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an admin user.
		$this->admin_user = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_user );

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
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
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
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
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
		// Set up valid nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['api_key'] = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		$ajax_handlers->handle_test_brave_search_connection();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify API key error.
		$this->assertFalse( $data['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $data['data'], 'Response should have error message' );
		$this->assertStringContainsString(
			'API key',
			$data['data']['message'],
			'Error message should mention API key'
		);
	}

	/**
	 * Tear down the test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_GET['connection'] );
		unset( $_POST['nonce'] );
		unset( $_POST['api_key'] );
		
		parent::tearDown();
	}
}
