<?php
/**
 * Tests for the QuickBooks Online MCP connection type in the Remote Site Manager.
 *
 * Covers the official hosted-endpoint default, self-hosted wrapper URLs, the
 * remote-sites → MCP App auth mapping (OAuth / bearer / custom header), the
 * pre-registered Intuit client credentials, validation rules, restricted-host
 * enforcement, and the JSON-RPC handshake path.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.21
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * QuickBooks Online MCP connection type tests.
 *
 * @since 1.2.21
 */
class Test_Remote_Site_Manager_QuickBooks_MCP extends WP_UnitTestCase {

	/**
	 * Captured outbound HTTP arguments.
	 *
	 * @var array
	 */
	protected $captured = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Save a QuickBooks MCP connection fixture.
	 *
	 * @param array $overrides Field overrides.
	 * @return string|WP_Error Connection ID or error.
	 */
	protected function save_quickbooks_mcp_connection( array $overrides = array() ) {
		$data = array_merge(
			array(
				'name'            => 'QuickBooks MCP',
				'url'             => 'https://ai-inc.quickbooks.intuit.com/v1/mcp',
				'connection_type' => 'quickbooks_mcp',
				'auth_type'       => 'oauth',
				'enabled'         => true,
			),
			$overrides
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
	}

	/**
	 * Build a JSON-RPC success body.
	 *
	 * @param array $result Result payload.
	 * @return string
	 */
	protected function rpc_result( array $result ) {
		return wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => $result,
			)
		);
	}

	/**
	 * Install a pre_http_request mock routing JSON-RPC methods to responses.
	 *
	 * @param array $responses Map of JSON-RPC method => response payload.
	 * @return void
	 */
	protected function install_http_mock( array $responses ) {
		$captured =& $this->captured;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured, $responses ) {
				unset( $pre, $url );
				$captured[] = $args;

				$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';

				if ( isset( $responses[ $method ] ) ) {
					$response = $responses[ $method ];

					return array(
						'headers'  => isset( $response['headers'] ) ? $response['headers'] : array(),
						'body'     => $response['body'],
						'response' => array(
							'code'    => isset( $response['code'] ) ? $response['code'] : 200,
							'message' => 'OK',
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => new stdClass(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * The connection saves, is typed, and encrypts the Intuit client secret.
	 */
	public function test_quickbooks_mcp_connection_saves_and_encrypts_secret() {
		$id = $this->save_quickbooks_mcp_connection(
			array(
				'client_id'     => 'ABcDeFIntuitClient',
				'client_secret' => 'intuit-secret-value',
				'company_id'    => '9130345678901234',
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		$this->assertIsArray( $connection );
		$this->assertSame( 'quickbooks_mcp', $connection['connection_type'] );
		$this->assertSame( 'ABcDeFIntuitClient', $connection['client_id'] );
		$this->assertSame( '9130345678901234', $connection['company_id'] );
		$this->assertNotSame( 'intuit-secret-value', $connection['client_secret'] );
		$this->assertSame( 'intuit-secret-value', WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] ) );
	}

	/**
	 * The config builder defaults to the official hosted endpoint.
	 */
	public function test_build_config_defaults_to_official_hosted_endpoint() {
		$connection = array(
			'id'              => 'qb_default',
			'connection_type' => 'quickbooks_mcp',
			'auth_type'       => 'oauth',
		);

		$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_quickbooks_mcp_app_config( $connection );

		$this->assertSame( 'oauth', $config['auth_type'] );
		$this->assertSame( WP_MCP_AI_Pro_Remote_Site_Manager::QUICKBOOKS_MCP_DEFAULT_URL, $config['server_url'] );
		$this->assertSame( 'qb_default', $config['connection_ref'] );
	}

	/**
	 * A self-hosted Streamable HTTP wrapper URL overrides the official default.
	 */
	public function test_build_config_honours_self_hosted_wrapper_url() {
		$id = $this->save_quickbooks_mcp_connection(
			array(
				'url'       => 'https://mcp.internal.example.com/qbo/mcp',
				'auth_type' => 'none',
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_quickbooks_mcp_app_config( $connection );

		$this->assertSame( 'none', $config['auth_type'] );
		$this->assertStringContainsString( 'mcp.internal.example.com/qbo/mcp', $config['server_url'] );
	}

	/**
	 * Bearer auth maps onto the MCP client's bearer auth with a decrypted token.
	 */
	public function test_build_config_maps_bearer_auth() {
		$id = $this->save_quickbooks_mcp_connection(
			array(
				'auth_type' => 'bearer',
				'token'     => 'qbo-access-token',
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_quickbooks_mcp_app_config( $connection );

		$this->assertSame( 'bearer', $config['auth_type'] );
		$this->assertSame( 'qbo-access-token', $config['token'] );
	}

	/**
	 * Custom-header auth maps onto the MCP client's header auth.
	 */
	public function test_build_config_maps_custom_header() {
		$id = $this->save_quickbooks_mcp_connection(
			array(
				'auth_type'       => 'custom_header',
				'mcp_header_name' => 'X-QB-Token',
				'token'           => 'header-value',
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_quickbooks_mcp_app_config( $connection );

		$this->assertSame( 'header', $config['auth_type'] );
		$this->assertSame( 'X-QB-Token', $config['header_name'] );
		$this->assertSame( 'header-value', $config['token'] );
	}

	/**
	 * The OAuth config decrypts the token blob and carries the Intuit app
	 * registration for HTTP Basic token/refresh requests.
	 */
	public function test_build_config_decrypts_oauth_blob_and_carries_client_credentials() {
		$id = $this->save_quickbooks_mcp_connection(
			array(
				'client_id'     => 'ABcDeFIntuitClient',
				'client_secret' => 'intuit-secret-value',
				'mcp_oauth'     => wp_json_encode(
					array(
						'access_token'  => 'qbo-access-token',
						'refresh_token' => 'qbo-refresh-token',
						'expires_in'    => 3600,
						'issued_at'     => time(),
					)
				),
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_quickbooks_mcp_app_config( $connection );

		$this->assertSame( 'oauth', $config['auth_type'] );
		$this->assertSame( 'qbo-access-token', $config['token'] );
		$this->assertSame( 'qbo-refresh-token', $config['oauth_data']['refresh_token'] );
		// Client ID case must survive sanitisation (Intuit IDs are case-sensitive).
		$this->assertSame( 'ABcDeFIntuitClient', $config['client_id'] );
		$this->assertSame( 'intuit-secret-value', $config['client_secret'] );
	}

	/**
	 * OAuth mode saves before the browser sign-in completes.
	 */
	public function test_validation_allows_oauth_before_login() {
		$id = $this->save_quickbooks_mcp_connection();

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'oauth', $connection['auth_type'] );
	}

	/**
	 * Bearer auth without a token is rejected at save time.
	 */
	public function test_validation_requires_bearer_token() {
		$result = $this->save_quickbooks_mcp_connection(
			array(
				'auth_type' => 'bearer',
				'token'     => '',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_mcp_bearer', $result->get_error_code() );
	}

	/**
	 * Custom-header auth without a header name is rejected at save time.
	 */
	public function test_validation_requires_header_name() {
		$result = $this->save_quickbooks_mcp_connection(
			array(
				'auth_type' => 'custom_header',
				'token'     => 'header-value',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_mcp_header', $result->get_error_code() );
	}

	/**
	 * A missing URL is rejected.
	 */
	public function test_validation_requires_endpoint_url() {
		$result = $this->save_quickbooks_mcp_connection( array( 'url' => '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_url', $result->get_error_code() );
		$this->assertStringContainsString( 'QuickBooks MCP endpoint URL', $result->get_error_message() );
	}

	/**
	 * Private/loopback hosts are rejected for QuickBooks MCP connections.
	 */
	public function test_quickbooks_mcp_restricted_host_rejected() {
		$result = $this->save_quickbooks_mcp_connection(
			array(
				'url'       => 'http://127.0.0.1:8000/mcp',
				'auth_type' => 'none',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_restricted_host', $result->get_error_code() );
	}

	/**
	 * OAuth connections without tokens return a saved-credentials
	 * acknowledgement instead of a failed handshake.
	 */
	public function test_test_connection_oauth_without_token_is_acknowledged() {
		$id         = $this->save_quickbooks_mcp_connection();
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['quickbooks_mcp'] );
		$this->assertSame( 0, count( $this->captured ), 'No HTTP call should run before OAuth completes.' );
	}

	/**
	 * A bearer-authenticated connection performs a real JSON-RPC handshake.
	 */
	public function test_test_connection_performs_handshake() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'body' => $this->rpc_result(
						array(
							'serverInfo'   => array(
								'name'    => 'QuickBooks MCP',
								'version' => '1.0.0',
							),
							'capabilities' => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list'      => array(
					'body' => $this->rpc_result(
						array(
							'tools' => array(
								array(
									'name'        => 'search_invoices',
									'description' => 'Search invoices',
								),
							),
						)
					),
				),
			)
		);

		$id = $this->save_quickbooks_mcp_connection(
			array(
				'auth_type' => 'bearer',
				'token'     => 'qbo-access-token',
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['quickbooks_mcp'] );
		$this->assertSame( 'QuickBooks MCP', $result['server_info']['name'] );
		$this->assertSame( 1, $result['tool_count'] );
	}

	/**
	 * QuickBooks MCP connections are referenceable from assistant MCP Apps.
	 */
	public function test_quickbooks_mcp_is_referenceable() {
		$qb_id = $this->save_quickbooks_mcp_connection();
		$qb    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $qb_id );

		$legacy_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'QuickBooks API',
				'url'             => 'https://quickbooks.api.intuit.com',
				'connection_type' => 'quickbooks',
				'auth_type'       => 'none',
				'client_id'       => 'cid',
				'client_secret'   => 'csecret',
				'enabled'         => true,
			)
		);

		$this->assertNotWPError( $legacy_id );

		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( $qb ) );
		$this->assertFalse( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $legacy_id ) ) );

		$references = WP_MCP_AI_Pro_Remote_Site_Manager::get_mcp_app_connections();
		$types      = wp_list_pluck( $references, 'connection_type' );

		$this->assertContains( 'quickbooks_mcp', $types );
		$this->assertNotContains( 'quickbooks', $types );
	}

	/**
	 * Update_mcp_oauth() persists rotated tokens for QuickBooks MCP connections.
	 */
	public function test_update_mcp_oauth_accepts_quickbooks_mcp() {
		$id = $this->save_quickbooks_mcp_connection(
			array(
				'mcp_oauth' => wp_json_encode(
					array(
						'access_token'  => 'old-token',
						'refresh_token' => 'old-refresh',
						'expires_in'    => 3600,
						'issued_at'     => time() - 7200,
					)
				),
			)
		);

		$this->assertNotWPError( $id );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$id,
			array(
				'access_token'  => 'new-token',
				'refresh_token' => 'new-refresh',
				'expires_in'    => 3600,
				'issued_at'     => time(),
			)
		);

		$this->assertTrue( $updated );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$decoded    = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );

		$this->assertSame( 'new-token', $decoded['access_token'] );
		$this->assertSame( 'new-refresh', $decoded['refresh_token'] );
	}
}
