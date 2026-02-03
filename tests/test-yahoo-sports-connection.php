<?php
/**
 * Tests for Yahoo Sports API connection integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Yahoo Sports connection settings.
 */
class WP_MCP_AI_Yahoo_Sports_Connection_Test extends WP_UnitTestCase {

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
	 * Test that the Yahoo AJAX handler is registered.
	 */
	public function test_yahoo_ajax_handler_is_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_yahoo_connection' ),
			'Yahoo connection test AJAX handler should be registered'
		);
	}

	/**
	 * Test that Yahoo Sports fields are included in integrations section.
	 */
	public function test_yahoo_sports_fields_exist_in_integrations() {
		$section = new WP_MCP_AI_Section_Integrations();
		$fields  = $section->get_fields();

		$this->assertArrayHasKey( 'yahoo_client_id', $fields, 'Yahoo Client ID field should exist' );
		$this->assertArrayHasKey( 'yahoo_client_secret', $fields, 'Yahoo Client Secret field should exist' );
		
		// Verify field structure.
		$this->assertEquals( 'text', $fields['yahoo_client_id']['type'] );
		$this->assertEquals( 'password', $fields['yahoo_client_secret']['type'] );
	}

	/**
	 * Test that Yahoo Sports is in the connections subtab groups.
	 */
	public function test_yahoo_sports_in_subtab_groups() {
		$section       = new WP_MCP_AI_Section_Integrations();
		$reflection    = new ReflectionClass( $section );
		$method        = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		$this->assertArrayHasKey( 'yahoo_sports', $subtab_groups, 'Yahoo Sports should be in subtab groups' );
		
		$yahoo_group = $subtab_groups['yahoo_sports'];
		$this->assertEquals( 'yahoo_sports', $yahoo_group['id'] );
		$this->assertEquals( 'dashicons-awards', $yahoo_group['icon'] );
		$this->assertTrue( $yahoo_group['pro'], 'Yahoo Sports should be marked as Pro feature' );
		$this->assertContains( 'yahoo_client_id', $yahoo_group['fields'] );
		$this->assertContains( 'yahoo_client_secret', $yahoo_group['fields'] );
	}

	/**
	 * Test Yahoo credentials are stored and retrieved correctly.
	 */
	public function test_yahoo_credentials_storage() {
		// Save credentials to centralized settings.
		$settings = array(
			'yahoo_client_id'     => 'test_client_id_123',
			'yahoo_client_secret' => 'test_secret_456',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Retrieve and verify.
		$retrieved = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertEquals( 'test_client_id_123', $retrieved['yahoo_client_id'] );
		$this->assertEquals( 'test_secret_456', $retrieved['yahoo_client_secret'] );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test backward compatibility with legacy option names.
	 */
	public function test_backward_compatibility_with_legacy_options() {
		// Set legacy options.
		update_option( 'wp_mcp_ai_yahoo_client_id', 'legacy_client_id' );
		update_option( 'wp_mcp_ai_yahoo_client_secret', 'legacy_secret' );

		// Verify they can still be retrieved.
		$legacy_id     = get_option( 'wp_mcp_ai_yahoo_client_id' );
		$legacy_secret = get_option( 'wp_mcp_ai_yahoo_client_secret' );

		$this->assertEquals( 'legacy_client_id', $legacy_id );
		$this->assertEquals( 'legacy_secret', $legacy_secret );

		// Clean up.
		delete_option( 'wp_mcp_ai_yahoo_client_id' );
		delete_option( 'wp_mcp_ai_yahoo_client_secret' );
	}

	/**
	 * Test Yahoo FF auth tool reads from centralized settings.
	 */
	public function test_yahoo_ff_auth_tool_reads_from_settings() {
		// Skip if Pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Yahoo_FF_Auth' ) ) {
			$this->markTestSkipped( 'Yahoo FF Auth tool not available (Pro addon required)' );
		}

		// Set credentials in centralized settings.
		$settings = array(
			'yahoo_client_id'     => 'test_auth_client',
			'yahoo_client_secret' => 'test_auth_secret',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create user for testing.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Instantiate tool.
		$tool = new WP_MCP_AI_Tool_Yahoo_FF_Auth();

		// Execute get_status action.
		$result = $tool->execute(
			array( 'action' => 'get_status' ),
			array( 'user_id' => $user_id )
		);

		// Verify configuration status.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'configuration', $result );
		$this->assertTrue( $result['configuration']['client_id_set'], 'Client ID should be detected as set' );
		$this->assertTrue( $result['configuration']['client_secret_set'], 'Client Secret should be detected as set' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
		wp_delete_user( $user_id );
	}

	/**
	 * Test Yahoo FF auth tool falls back to legacy options.
	 */
	public function test_yahoo_ff_auth_tool_fallback_to_legacy() {
		// Skip if Pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Yahoo_FF_Auth' ) ) {
			$this->markTestSkipped( 'Yahoo FF Auth tool not available (Pro addon required)' );
		}

		// Set only legacy options (not in centralized settings).
		update_option( 'wp_mcp_ai_yahoo_client_id', 'legacy_fallback_id' );
		update_option( 'wp_mcp_ai_yahoo_client_secret', 'legacy_fallback_secret' );

		// Create user for testing.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Instantiate tool.
		$tool = new WP_MCP_AI_Tool_Yahoo_FF_Auth();

		// Execute get_status action.
		$result = $tool->execute(
			array( 'action' => 'get_status' ),
			array( 'user_id' => $user_id )
		);

		// Verify configuration status with fallback.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'configuration', $result );
		$this->assertTrue( $result['configuration']['client_id_set'], 'Client ID should be detected from legacy option' );
		$this->assertTrue( $result['configuration']['client_secret_set'], 'Client Secret should be detected from legacy option' );

		// Clean up.
		delete_option( 'wp_mcp_ai_yahoo_client_id' );
		delete_option( 'wp_mcp_ai_yahoo_client_secret' );
		wp_delete_user( $user_id );
	}

	/**
	 * Test AJAX handler with missing credentials.
	 */
	public function test_yahoo_ajax_handler_requires_credentials() {
		// Set up valid nonce.
		$_POST['nonce']         = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['client_id']     = '';
		$_POST['client_secret'] = '';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		$ajax_handlers->handle_test_yahoo_connection();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify credentials error.
		$this->assertFalse( $data['success'], 'Response should indicate failure' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $data['data'], 'Response should have error message' );
		$this->assertStringContainsString(
			'Client ID',
			$data['data']['message'],
			'Error message should mention Client ID'
		);
	}

	/**
	 * Test AJAX handler with valid credentials format.
	 */
	public function test_yahoo_ajax_handler_validates_credentials_format() {
		// Set up valid nonce and credentials.
		$_POST['nonce']         = wp_create_nonce( 'wp-mcp-ai-settings' );
		$_POST['client_id']     = 'dj0yJmk9V2VxZW1NRE9zV2lVJmQ9WVdrOVRuYzJWR3BKYkUwbWNHbzlNQT09JnM9Y29uc3VtZXJzZWNyZXQmc3Y9MCZ4PTQ4';
		$_POST['client_secret'] = '1733cd6d727fc75f981a0d44a07d5ffa961696e3';

		// Create instance and call handler.
		$ajax_handlers = new WP_MCP_AI_Admin_AJAX_Handlers();

		// Capture output.
		ob_start();
		$ajax_handlers->handle_test_yahoo_connection();
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify success.
		$this->assertTrue( $data['success'], 'Response should indicate success' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data' );
		$this->assertArrayHasKey( 'message', $data['data'], 'Response should have success message' );
		$this->assertStringContainsString(
			'validated',
			$data['data']['message'],
			'Success message should mention validation'
		);
	}

	/**
	 * Tear down the test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_POST['nonce'] );
		unset( $_POST['client_id'] );
		unset( $_POST['client_secret'] );

		parent::tearDown();
	}
}
