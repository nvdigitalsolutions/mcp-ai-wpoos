<?php
/**
 * FlowHub Client Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

/**
 * Test class for WP_MCP_AI_FlowHub_Client.
 *
 * The client was re-architected when FlowHub connections moved to the Remote
 * Sites page: it is now constructed with a connection ID and resolves
 * credentials from the connection (falling back to centralized settings).
 */
class Test_FlowHub_Client extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	// ------------------------------------------------------------------ //
	// Constants Tests
	// ------------------------------------------------------------------ //

	/**
	 * The production API endpoint constant.
	 */
	public function test_default_api_endpoint() {
		$ref = new ReflectionClass( 'WP_MCP_AI_FlowHub_Client' );
		$this->assertEquals( 'https://api.flowhub.co', $ref->getConstant( 'API_ENDPOINT' ) );
	}

	// ------------------------------------------------------------------ //
	// Constructor Tests
	// ------------------------------------------------------------------ //

	/**
	 * Constructing without a connection ID uses the settings fallback.
	 */
	public function test_constructor_with_defaults() {
		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_FlowHub_Client', $client );
	}

	/**
	 * Constructing with a connection ID binds the client to that connection.
	 */
	public function test_constructor_with_connection_id() {
		$client = new WP_MCP_AI_FlowHub_Client( 'conn_flowhub_1' );
		$this->assertInstanceOf( 'WP_MCP_AI_FlowHub_Client', $client );
	}

	// ------------------------------------------------------------------ //
	// Credential Resolution Tests
	// ------------------------------------------------------------------ //

	/**
	 * get_key() falls back to the centralized settings option.
	 */
	public function test_get_key_falls_back_to_settings() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'flowhub_api_key' => 'settings_key' )
		);

		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertSame( 'settings_key', $client->get_key() );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * get_client_id() falls back to the centralized settings option.
	 */
	public function test_get_client_id_falls_back_to_settings() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'flowhub_client_id' => 'settings_client' )
		);

		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertSame( 'settings_client', $client->get_client_id() );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * get_api_key() remains as a legacy alias of get_key().
	 */
	public function test_get_api_key_aliases_get_key() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'flowhub_api_key' => 'alias_key' )
		);

		$client = new WP_MCP_AI_FlowHub_Client();
		$this->assertSame( $client->get_key(), $client->get_api_key() );

		delete_option( 'wp_mcp_ai_settings' );
	}

	// ------------------------------------------------------------------ //
	// Request Tests
	// ------------------------------------------------------------------ //

	/**
	 * make_request() must reject missing credentials with a WP_Error.
	 */
	public function test_make_request_returns_error_when_credentials_missing() {
		delete_option( 'wp_mcp_ai_settings' );

		$client = new WP_MCP_AI_FlowHub_Client();
		$result = $client->make_request( '/v0/inventoryNonZero' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_flowhub_config', $result->get_error_code() );
	}

	/**
	 * Verify make_request() sends credentials as clientId / key headers.
	 */
	public function test_make_request_uses_settings_credentials() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'flowhub_api_key'   => 'test_key',
				'flowhub_client_id' => 'test_client',
			)
		);

		$captured_headers = null;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured_headers ) {
				$captured_headers = isset( $args['headers'] ) ? $args['headers'] : null;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

		$client = new WP_MCP_AI_FlowHub_Client();
		$client->make_request( '/v0/inventoryNonZero' );

		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_settings' );

		$this->assertNotNull( $captured_headers, 'The Flowhub request should have been dispatched' );
		$this->assertSame( 'test_client', $captured_headers['clientId'] );
		$this->assertSame( 'test_key', $captured_headers['key'] );
	}

	// ------------------------------------------------------------------ //
	// Proxy Tests
	// ------------------------------------------------------------------ //

	/**
	 * The client resolves proxy config from a proxied Remote Sites connection.
	 */
	public function test_get_proxy_config_from_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Proxied FlowHub',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'auth_type'       => 'none',
				'client_id'       => 'test_client',
				'api_key'         => 'test_key',
				'enabled'         => true,
				'proxy_enabled'   => true,
				'proxy_url'       => 'http://proxy.local:3128',
				'proxy_username'  => 'puser',
				'proxy_password'  => 'ppass',
			)
		);

		$client = new WP_MCP_AI_FlowHub_Client( $connection_id );

		$this->assertSame( 'http://proxy.local:3128', $client->get_proxy_url() );
		$this->assertSame( 'puser:ppass', $client->get_proxy_auth() );

		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * The client resolves proxy config from toolkit settings in settings mode.
	 */
	public function test_get_proxy_config_from_settings() {
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'proxy_enabled'  => true,
				'proxy_url'      => 'http://settings-proxy:3128',
				'proxy_username' => 'suser',
				'proxy_password' => 'spass',
			)
		);

		$client = new WP_MCP_AI_FlowHub_Client();

		$this->assertSame( 'http://settings-proxy:3128', $client->get_proxy_url() );
		$this->assertSame( 'suser:spass', $client->get_proxy_auth() );

		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
	}

	/**
	 * Make request: attaches the proxy during the request and removes it after.
	 */
	public function test_make_request_attaches_proxy_during_request() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		remove_all_filters( 'http_api_curl' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Proxied FlowHub',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'auth_type'       => 'none',
				'client_id'       => 'test_client',
				'api_key'         => 'test_key',
				'enabled'         => true,
				'proxy_enabled'   => true,
				'proxy_url'       => 'http://proxy.local:3128',
			)
		);

		$proxy_hook_attached = false;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$proxy_hook_attached ) {
				$proxy_hook_attached = false !== has_filter( 'http_api_curl' );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

		$client = new WP_MCP_AI_FlowHub_Client( $connection_id );
		$result = $client->make_request( '/v0/inventoryNonZero' );

		remove_all_filters( 'pre_http_request' );
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$this->assertNotWPError( $result, 'The mocked request should succeed.' );
		$this->assertTrue( $proxy_hook_attached, 'The proxy must be attached while the request runs.' );
		$this->assertFalse( has_filter( 'http_api_curl' ), 'The proxy must be removed after the request.' );
	}
}
