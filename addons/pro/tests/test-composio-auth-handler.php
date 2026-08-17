<?php
/**
 * Tests for the Composio Connect Link auth handler.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';

/**
 * Test the Composio auth handler.
 */
class Test_Composio_Auth_Handler extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Save a minimal composio connection and return its record.
	 *
	 * @param array $overrides Connection overrides.
	 * @return array
	 */
	private function create_connection( array $overrides = array() ) {
		$data = array_merge(
			array(
				'name'            => 'Composio Test',
				'url'             => 'https://backend.composio.dev',
				'connection_type' => 'composio',
				'auth_type'       => 'none',
				'api_key'         => 'ak_test_123',
				'enabled'         => true,
			),
			$overrides
		);

		$id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );

		$this->assertNotWPError( $id );

		return WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
	}

	/**
	 * Test the WordPress user → Composio user_id mapping.
	 */
	public function test_wp_user_to_composio_user_id() {
		$this->assertSame( 'wp-42', WP_MCP_AI_Composio_Auth_Handler::wp_user_to_composio_user_id( 42 ) );
		$this->assertSame( WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX, WP_MCP_AI_Composio_Auth_Handler::wp_user_to_composio_user_id( 0 ) );
	}

	/**
	 * Test admin_shared mode uses the shared identity.
	 */
	public function test_resolve_user_id_admin_shared() {
		$connection = array( 'default_user_mode' => 'admin_shared' );

		$this->assertSame( WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX, WP_MCP_AI_Composio_Auth_Handler::resolve_user_id( $connection, 42 ) );
	}

	/**
	 * Test per_wp_user mode maps to the WordPress user.
	 */
	public function test_resolve_user_id_per_wp_user() {
		$connection = array( 'default_user_mode' => 'per_wp_user' );

		$this->assertSame( 'wp-42', WP_MCP_AI_Composio_Auth_Handler::resolve_user_id( $connection, 42 ) );
	}

	/**
	 * Test that create_link rejects non-composio connections.
	 */
	public function test_create_link_rejects_wrong_type() {
		$data = array(
			'name'            => 'WordPress Site',
			'url'             => 'https://example.com',
			'connection_type' => 'WordPress',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		$id   = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
		$link = WP_MCP_AI_Composio_Auth_Handler::create_link( $id, 'gmail' );

		$this->assertWPError( $link );
		$this->assertSame( 'wp_mcp_ai_composio_invalid_connection', $link->get_error_code() );
	}

	/**
	 * Test that create_link mints a state transient and returns a URL.
	 */
	public function test_create_link_mints_state_and_returns_url() {
		$connection = $this->create_connection();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'redirect_url' => 'https://auth.composio.dev/flow/abc' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$link = WP_MCP_AI_Composio_Auth_Handler::create_link( $connection['id'], 'gmail', 7 );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $link );
		$this->assertArrayHasKey( 'url', $link );
		$this->assertArrayHasKey( 'state', $link );
		$this->assertStringContainsString( 'state=', $link['url'] );

		// The state transient must exist and be bound to the connection.
		$stored = get_transient( WP_MCP_AI_Composio_Auth_Handler::STATE_PREFIX . $link['state'] );
		$this->assertIsArray( $stored );
		$this->assertSame( $connection['id'], $stored['connection_id'] );
		$this->assertSame( 'gmail', $stored['toolkit'] );
		$this->assertSame( 7, $stored['wp_user_id'] );
	}

	/**
	 * Test that a disabled connection cannot create links.
	 */
	public function test_create_link_rejects_disabled_connection() {
		$connection = $this->create_connection( array( 'enabled' => false ) );

		$link = WP_MCP_AI_Composio_Auth_Handler::create_link( $connection['id'], 'gmail' );

		$this->assertWPError( $link );
		$this->assertSame( 'wp_mcp_ai_composio_disabled_connection', $link->get_error_code() );
	}

	/**
	 * Test expired-account marking round-trips through transients.
	 */
	public function test_account_expiry_marking() {
		$this->assertFalse( WP_MCP_AI_Composio_Auth_Handler::is_account_expired( 'conn_1', 'ca_abc' ) );

		WP_MCP_AI_Composio_Auth_Handler::mark_account_expired( 'conn_1', 'ca_abc' );

		$this->assertTrue( WP_MCP_AI_Composio_Auth_Handler::is_account_expired( 'conn_1', 'ca_abc' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Auth_Handler::is_account_expired( 'conn_1', 'ca_other' ) );
	}
}
