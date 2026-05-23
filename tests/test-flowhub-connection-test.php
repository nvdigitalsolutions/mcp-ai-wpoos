<?php
/**
 * Test Flowhub connection test functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Flowhub Connection Test.
 */
class Test_Flowhub_Connection_Test extends WP_MCP_AI_Ajax_TestCase {

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
	 * Test that the Flowhub AJAX handler is registered.
	 */
	public function test_flowhub_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_flowhub_connection' ),
			'Flowhub connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that the test button is rendered.
	 */
	public function test_flowhub_test_button_renders() {
		// Set credentials.
		$settings                          = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['flowhub_api_key']       = '';
		$settings['flowhub_client_id']     = '';
		$settings['flowhub_client_secret'] = '';
		$settings['flowhub_location_id']   = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate being on the flowhub connection page.
		$_GET['connection'] = 'flowhub';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// Check that the test button is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-flowhub-connection',
			$output,
			'Test button should be rendered'
		);

		// Check that the test button has correct attributes.
		$this->assertStringContainsString(
			'id="wp-mcp-ai-test-flowhub-connection"',
			$output,
			'Test button should have correct ID'
		);

		// Check that the result span is present.
		$this->assertStringContainsString(
			'wp-mcp-ai-flowhub-test-result',
			$output,
			'Result span should be rendered'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test AJAX handler with missing credentials.
	 */
	public function test_flowhub_ajax_handler_requires_credentials() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_flowhub_connection',
			array(
				'nonce'         => wp_create_nonce( 'wp-mcp-ai-settings' ),
				'api_key'       => '',
				'client_id'     => '',
				'client_secret' => '',
				'location_id'   => '',
			)
		);

		// Verify credentials error.
		$this->assertFalse( $response['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $response, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $response['data'], 'Response should have error message' );
	}

	/**
	 * Test that Flowhub is in the connections subtab groups.
	 */
	public function test_flowhub_in_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Integrations();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayHasKey( 'flowhub', $subtab_groups, 'Flowhub should be in subtab groups' );

		$flowhub_group = $subtab_groups['flowhub'];
		$this->assertEquals( 'flowhub', $flowhub_group['id'] );
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
