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
	 * Test AJAX handler with missing nonce.
	 */
	public function test_brave_search_ajax_handler_requires_nonce() {
		// Clear $_POST.
		$_POST = array();

		// Try to call the handler without nonce.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
		
		// Expect wp_die to be called due to failed nonce check.
		$this->expectException( WPDieException::class );
		
		$ajax_handlers->handle_test_brave_search_connection();
	}

	/**
	 * Test AJAX handler with missing API key.
	 */
	public function test_brave_search_ajax_handler_requires_api_key() {
		// Set up valid nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['api_key'] = '';

		// Capture the JSON response.
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );

		try {
			$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
			$ajax_handlers->handle_test_brave_search_connection();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_error calls wp_die.
		}

		// Check the response.
		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $response['data'], 'Response should have error message' );
		$this->assertStringContainsString(
			'API key',
			$response['data']['message'],
			'Error message should mention API key'
		);

		// Cleanup.
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
	}

	/**
	 * Get a custom wp_die handler for AJAX tests.
	 *
	 * @return callable
	 */
	public function get_wp_die_handler() {
		return array( $this, 'wp_die_handler' );
	}

	/**
	 * Custom wp_die handler for capturing AJAX responses.
	 *
	 * @param string $message Die message.
	 * @throws WPAjaxDieContinueException Always.
	 */
	public function wp_die_handler( $message ) {
		$this->_last_response = $message;
		throw new WPAjaxDieContinueException( $message );
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
