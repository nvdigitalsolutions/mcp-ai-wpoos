<?php
/**
 * Test Remove.bg connection test functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Remove.bg Connection Test.
 */
class Test_Removebg_Connection_Test extends WP_UnitTestCase {

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
	 * Test that the Remove.bg AJAX handler is registered.
	 */
	public function test_removebg_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_removebg_connection' ),
			'Remove.bg connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that the test button is rendered even without saved API key.
	 */
	public function test_removebg_test_button_renders_without_saved_key() {
		// Clear any saved API key.
		$settings                     = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['removebg_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the removebg connection page.
		$_GET['connection'] = 'removebg';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-removebg-connection',
			$output,
			'Test button should be rendered even without saved API key'
		);

		// Check that the test button has correct attributes.
		$this->assertStringContainsString(
			'id="wp-mcp-ai-test-removebg-connection"',
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
			'wp-mcp-ai-removebg-test-result',
			$output,
			'Result span should be rendered'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test that the test button is rendered with saved API key.
	 */
	public function test_removebg_test_button_renders_with_saved_key() {
		// Set a saved API key.
		$settings                     = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['removebg_api_key'] = 'test-api-key-12345';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the removebg connection page.
		$_GET['connection'] = 'removebg';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-removebg-connection',
			$output,
			'Test button should be rendered with saved API key'
		);

		// Check that the result span is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-removebg-test-result',
			$output,
			'Result span should be rendered'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test AJAX handler with missing API key.
	 */
	public function test_removebg_ajax_handler_requires_api_key() {
		// Set up valid nonce.
		$_POST['nonce']   = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['api_key'] = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		$ajax_handlers->handle_test_removebg_connection();
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
	 * Test that Remove.bg is in the connections subtab groups.
	 */
	public function test_removebg_in_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Integrations();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayHasKey( 'removebg', $subtab_groups, 'Remove.bg should be in subtab groups' );

		$removebg_group = $subtab_groups['removebg'];
		$this->assertEquals( 'removebg', $removebg_group['id'] );
		$this->assertContains( 'removebg_api_key', $removebg_group['fields'] );
	}

	/**
	 * Test that RemoveBG API key persists after save.
	 *
	 * This test verifies the fix for the issue where connection settings
	 * were not being saved properly due to incorrect subtab detection.
	 */
	public function test_removebg_api_key_persists_after_save() {
		// Clear any existing settings.
		delete_option( 'wp_mcp_ai_settings' );

		// Simulate form submission for RemoveBG connection.
		$_POST['wp_mcp_ai_settings']                 = array(
			'removebg_api_key' => 'test-api-key-12345',
		);
		$_POST['active_tab']                         = 'tools';
		$_POST['subtab']                             = 'connections';
		$_POST['connection']                         = 'removebg';
		$_POST['subtab_integrations_gmail_crawl4ai'] = 'removebg';
		$_POST['_wpnonce']                           = wp_create_nonce( 'wp_mcp_ai_save_settings' );
		$_POST['action']                             = 'wp_mcp_ai_save_settings';

		// Get the settings dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Call sanitize_settings directly to test the sanitization.
		$sanitized = $dashboard->sanitize_settings( $_POST['wp_mcp_ai_settings'], 'tools' );

		// Verify the API key was sanitized.
		$this->assertArrayHasKey( 'removebg_api_key', $sanitized, 'RemoveBG API key should be in sanitized array' );
		$this->assertEquals( 'test-api-key-12345', $sanitized['removebg_api_key'], 'RemoveBG API key should match the submitted value' );

		// Now simulate the full save process.
		update_option( 'wp_mcp_ai_settings', $sanitized );

		// Verify the settings were saved to the database.
		$saved_settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertArrayHasKey( 'removebg_api_key', $saved_settings, 'RemoveBG API key should be saved in database' );
		$this->assertEquals( 'test-api-key-12345', $saved_settings['removebg_api_key'], 'Saved RemoveBG API key should match' );

		// Cleanup.
		unset( $_POST['wp_mcp_ai_settings'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['subtab'] );
		unset( $_POST['connection'] );
		unset( $_POST['subtab_integrations_gmail_crawl4ai'] );
		unset( $_POST['_wpnonce'] );
		unset( $_POST['action'] );
	}

	/**
	 * Test that the active subtab is correctly determined from connection parameter.
	 */
	public function test_active_subtab_from_connection_parameter() {
		$section = new WP_MCP_AI_Section_Integrations();

		// Simulate being on the removebg connection page.
		$_GET['connection'] = 'removebg';

		// Use reflection to call the protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );
		$active_subtab = $method->invoke( $section );

		$this->assertEquals( 'removebg', $active_subtab, 'Active subtab should be determined from connection parameter' );

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test that the active subtab prioritizes POST connection over GET subtab.
	 */
	public function test_active_subtab_prioritizes_connection() {
		$section = new WP_MCP_AI_Section_Integrations();

		// Simulate conflicting parameters (this was the bug).
		$_GET['subtab']      = 'connections'; // Tools section's subtab.
		$_POST['connection'] = 'removebg';    // Integration section's connection.

		// Use reflection to call the protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );
		$active_subtab = $method->invoke( $section );

		$this->assertEquals( 'removebg', $active_subtab, 'Active subtab should prioritize POST connection over GET subtab' );

		// Cleanup.
		unset( $_GET['subtab'] );
		unset( $_POST['connection'] );
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
