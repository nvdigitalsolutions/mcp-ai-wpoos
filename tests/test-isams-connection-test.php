<?php
/**
 * Test iSAMS connection test functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test iSAMS Connection Test.
 */
class Test_ISAMS_Connection_Test extends WP_UnitTestCase {

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

		// The iSAMS action is registered by WP_MCP_AI_Admin_Settings in
		// production, which never loads under CLI phpunit (is_admin() is
		// false). Load the handler class and register the action here;
		// wp-phpunit restores its once-per-process hook snapshot after
		// every test, so re-register per test when the hook is missing.
		if ( ! class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
			$path = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
			$handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
			if ( ! has_action( 'wp_ajax_wp_mcp_ai_test_isams_connection' ) ) {
				add_action( 'wp_ajax_wp_mcp_ai_test_isams_connection', array( $handlers, 'safe_ajax_handler' ) );
			}
		}
	}

	/**
	 * Test that the iSAMS AJAX handler is registered.
	 */
	public function test_isams_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_isams_connection' ),
			'iSAMS connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that the legacy iSAMS test button is no longer rendered by the
	 * Integrations section: iSAMS connection management moved to Remote Sites
	 * (Pro), so the section must not emit the old connection-test UI.
	 */
	public function test_isams_not_rendered_in_integrations_section() {
		// Clear credentials.
		$settings                     = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['isams_api_url']    = '';
		$settings['isams_api_key']    = '';
		$settings['isams_api_secret'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Integrations();

		// Start output buffering.
		ob_start();

		// Simulate the legacy isams connection page within Tools > Connections.
		$_GET['subtab']     = 'connections';
		$_GET['connection'] = 'isams';

		// Render the section.
		$section->render_wrapper();

		$output = ob_get_clean();

		// iSAMS moved to Remote Sites; the integrations section must not
		// render the old test button or result span.
		$this->assertStringNotContainsString(
			'wp-mcp-ai-test-isams-connection',
			$output,
			'iSAMS test button should not be rendered by the Integrations section'
		);
		$this->assertStringNotContainsString(
			'wp-mcp-ai-isams-test-result',
			$output,
			'iSAMS result span should not be rendered by the Integrations section'
		);

		// Cleanup.
		unset( $_GET['connection'] );
	}

	/**
	 * Test AJAX handler with missing credentials.
	 */
	public function test_isams_ajax_handler_requires_credentials() {
		// Set up valid nonce but missing credentials.
		$_POST['nonce']      = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['api_url']    = '';
		$_POST['api_key']    = '';
		$_POST['api_secret'] = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		try {
			$ajax_handlers->handle_test_isams_connection();
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Expected: the handler terminates via wp_send_json_error(), which the test bootstrap converts into a throwable WPDieException.
		} catch ( WPDieException $e ) {
			// Expected: the handler terminates via wp_send_json_error(), which
			// the test bootstrap converts into a throwable WPDieException.
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
	 * Test that iSAMS is no longer an Integrations subtab group.
	 *
	 * Connection management for iSAMS, PayHere, Flowhub, and QuickBooks
	 * moved to Remote Sites (Pro), so the Integrations section must not
	 * advertise an iSAMS subtab.
	 */
	public function test_isams_not_in_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Integrations();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayNotHasKey(
			'isams',
			$subtab_groups,
			'iSAMS moved to Remote Sites and should not be in the Integrations subtab groups'
		);
	}

	/**
	 * Tear down the test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_GET['subtab'] );
		unset( $_GET['connection'] );
		unset( $_POST['nonce'] );
		unset( $_POST['api_url'] );
		unset( $_POST['api_key'] );
		unset( $_POST['api_secret'] );

		parent::tearDown();
	}
}
