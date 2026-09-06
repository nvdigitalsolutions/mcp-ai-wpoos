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

		// Construct the admin settings service so its constructor registers the
		// connection-test AJAX handlers. In production this happens inside the
		// is_admin() loader block; the CLI test context is never admin, so the
		// tests construct it explicitly instead of firing admin_init (which
		// would re-register WooCommerce/admin-only hooks and trip
		// _doing_it_wrong notices). A fresh instance is used per test because
		// WP_UnitTestCase restores the hook snapshot in tearDown, wiping
		// registrations made during setUp.
		new WP_MCP_AI_Admin_Settings();
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
	 * Test that FlowHub connections are managed by the Remote Sites admin.
	 *
	 * FlowHub moved out of the integrations section to the Remote Sites
	 * page. The manager still validates flowhub credentials: a connection
	 * missing the API key and client ID must be rejected with the
	 * flowhub-specific error code.
	 */
	public function test_flowhub_managed_by_remote_sites() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
		$method     = $reflection->getMethod( 'validate_connection_data' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			array(
				'name'            => 'FlowHub Test',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
			)
		);

		$this->assertWPError( $result, 'FlowHub connections without credentials must be rejected' );
		$this->assertSame( 'wp_mcp_ai_pro_missing_flowhub_credentials', $result->get_error_code() );
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

		// Create instance and call handler. The handler terminates via
		// wp_send_json, which the test framework converts into a
		// WPAjaxDieContinueException.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		try {
			$ajax_handlers->handle_test_flowhub_connection();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e ); // Expected: wp_send_json ends the request through the test die handler.
		}
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify credentials error.
		$this->assertFalse( $data['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $data['data'], 'Response should have error message' );
	}

	/**
	 * Test that Flowhub is no longer a base integrations subtab.
	 *
	 * FlowHub moved to the Remote Sites page; the base integrations section
	 * must not expose the connection group.
	 */
	public function test_flowhub_excluded_from_base_integrations() {
		$section    = new WP_MCP_AI_Section_Integrations();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayNotHasKey( 'flowhub', $subtab_groups, 'Flowhub must not be a base integrations subtab' );
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
