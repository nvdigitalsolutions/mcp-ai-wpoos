<?php
/**
 * Test Flowhub connection test functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Flowhub Connection Test.
 */
class Test_Flowhub_Connection_Test extends WP_UnitTestCase {

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
		// Set up valid nonce but missing credentials.
		$_POST['nonce']         = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['api_key']       = '';
		$_POST['client_id']     = '';
		$_POST['client_secret'] = '';
		$_POST['location_id']   = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		$ajax_handlers->handle_test_flowhub_connection();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify credentials error.
		$this->assertFalse( $data['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $data['data'], 'Response should have error message' );
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
		unset( $_POST['nonce'] );
		unset( $_POST['api_key'] );
		unset( $_POST['client_id'] );
		unset( $_POST['client_secret'] );
		unset( $_POST['location_id'] );

		parent::tearDown();
	}
}
