<?php
/**
 * Test Mubert connection test functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Mubert Connection Test.
 */
class Test_Mubert_Connection_Test extends WP_UnitTestCase {

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

		// WooCommerce registers privacy-policy content on admin_init without
		// an is_admin() guard; WP 6.9 flags that as incorrect usage in the
		// non-admin test context. The notice is environmental, not from the
		// code under test.
		$this->setExpectedIncorrectUsage( 'wp_add_privacy_policy_content' );
	}

	/**
	 * Test that the Mubert AJAX handler is registered.
	 */
	public function test_mubert_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_mubert_connection' ),
			'Mubert connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that the test button is rendered even without saved API key.
	 */
	public function test_mubert_test_button_renders_without_saved_key() {
		// Clear any saved API key.
		$settings                   = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['mubert_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the mubert connection page within the
		// Tools > Connections subtab; render_wrapper() only renders when
		// the 'connections' subtab is active.
		$_GET['subtab']     = 'connections';
		$_GET['connection'] = 'mubert';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-mubert-connection',
			$output,
			'Test button should be rendered even without saved API key'
		);

		// Check that the test button has correct attributes.
		$this->assertStringContainsString(
			'id="wp-mcp-ai-test-mubert-connection"',
			$output,
			'Test button should have correct ID'
		);

		// Check that the result span is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-mubert-test-result',
			$output,
			'Result span should be rendered'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test AJAX handler with missing API key.
	 */
	public function test_mubert_ajax_handler_requires_api_key() {
		// Set up valid nonce.
		$_POST['nonce']   = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['api_key'] = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		try {
			$ajax_handlers->handle_test_mubert_connection();
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Expected: the handler terminates via wp_send_json_error(), which the test bootstrap converts into a throwable WPDieException.
		} catch ( WPDieException $e ) {
			// Expected: the handler terminates via wp_send_json_error(), which
			// the test bootstrap converts into a throwable WPDieException.
		}
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
	 * Test that Mubert is in the connections subtab groups.
	 */
	public function test_mubert_in_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Integrations();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayHasKey( 'mubert', $subtab_groups, 'Mubert should be in subtab groups' );

		$mubert_group = $subtab_groups['mubert'];
		$this->assertEquals( 'mubert', $mubert_group['id'] );
		$this->assertContains( 'mubert_api_key', $mubert_group['fields'] );
	}

	/**
	 * Tear down the test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_GET['subtab'] );
		unset( $_GET['connection'] );
		unset( $_POST['nonce'] );
		unset( $_POST['api_key'] );

		parent::tearDown();
	}
}
