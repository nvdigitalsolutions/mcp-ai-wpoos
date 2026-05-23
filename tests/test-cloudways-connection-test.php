<?php
/**
 * Test Cloudways connection test functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Cloudways Connection Test.
 */
class Test_Cloudways_Connection_Test extends WP_MCP_AI_Ajax_TestCase {

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
	 * Test that the Cloudways AJAX handler is registered.
	 */
	public function test_cloudways_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_cloudways_connection' ),
			'Cloudways connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that the test button is rendered.
	 */
	public function test_cloudways_test_button_renders() {
		// Clear credentials.
		$settings                      = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['cloudways_email']   = '';
		$settings['cloudways_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the cloudways connection page.
		$_GET['connection'] = 'cloudways';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-cloudways-connection',
			$output,
			'Test button should be rendered'
		);

		// Check that the test button has correct attributes.
		$this->assertStringContainsString(
			'id="wp-mcp-ai-test-cloudways-connection"',
			$output,
			'Test button should have correct ID'
		);

		// Check that the result span is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-cloudways-test-result',
			$output,
			'Result span should be rendered'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test AJAX handler with missing credentials.
	 */
	public function test_cloudways_ajax_handler_requires_credentials() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_cloudways_connection',
			array(
				'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
				'email'   => '',
				'api_key' => '',
			)
		);

		// Verify credentials error.
		$this->assertFalse( $response['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $response['data'], 'Response should have error message' );
	}

	/**
	 * Test that Cloudways is in the connections subtab groups.
	 */
	public function test_cloudways_in_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Integrations();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayHasKey( 'cloudways', $subtab_groups, 'Cloudways should be in subtab groups' );

		$cloudways_group = $subtab_groups['cloudways'];
		$this->assertEquals( 'cloudways', $cloudways_group['id'] );
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
