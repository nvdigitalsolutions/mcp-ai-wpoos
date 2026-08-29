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
}
