<?php
/**
 * Test Cloudways connection test functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudways Connection Test.
 */
class Test_Cloudways_Connection_Test extends WP_UnitTestCase {

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
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
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
		// Set up valid nonce but missing credentials.
		$_POST['nonce']   = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['email']   = '';
		$_POST['api_key'] = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		$ajax_handlers->handle_test_cloudways_connection();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify credentials error.
		$this->assertFalse( $data['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $data['data'], 'Response should have error message' );
	}

	/**
	 * Test that Cloudways is in the connections subtab groups.
	 */
	public function test_cloudways_in_subtab_groups() {
		$section       = new WP_MCP_AI_Section_Integrations();
		$reflection    = new ReflectionClass( $section );
		$method        = $reflection->getMethod( 'get_subtab_groups' );
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
		unset( $_POST['nonce'] );
		unset( $_POST['email'] );
		unset( $_POST['api_key'] );

		parent::tearDown();
	}
}
