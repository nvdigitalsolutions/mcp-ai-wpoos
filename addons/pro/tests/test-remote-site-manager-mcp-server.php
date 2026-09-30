<?php
/**
 * Tests for the MCP Server connection type in the Remote Site Manager.
 *
 * Covers save/encryption, the remote-sites → MCP App auth mapping, validation
 * rules, restricted-host enforcement, and the JSON-RPC handshake test path.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.85
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * MCP Server connection type tests.
 *
 * @since 1.1.85
 */
class Test_Remote_Site_Manager_MCP_Server extends WP_UnitTestCase {

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
	 * Save an MCP Server connection fixture.
	 *
	 * @param array $overrides Field overrides.
	 * @return string|WP_Error Connection ID or error.
	 */
	protected function save_mcp_connection( array $overrides = array() ) {
		$data = array_merge(
			array(
				'name'            => 'Elementor MCP',
				'url'             => 'https://example.com/wp-json/mcp/elementor-mcp-server',
				'connection_type' => 'mcp_server',
				'auth_type'       => 'basic_auth',
				'username'        => 'mcp-agent',
				'password'        => 'secret-app-password',
				'enabled'         => true,
			),
			$overrides
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
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
	 * An MCP Server connection saves, is typed, and encrypts the password.
	 */
	public function test_mcp_server_connection_saves_and_encrypts_credentials() {
		$id = $this->save_mcp_connection();

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		$this->assertIsArray( $connection );
		$this->assertSame( 'mcp_server', $connection['connection_type'] );
		$this->assertNotSame( 'secret-app-password', $connection['password'] );
		$this->assertSame( 'secret-app-password', WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['password'] ) );
	}

	/**
	 * The OAuth blob field is a credential field and is encrypted at rest.
	 */
	public function test_mcp_oauth_blob_is_credential_field_and_encrypted() {
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( 'mcp_oauth' ) );

		$id = $this->save_mcp_connection(
			array(
				'auth_type' => 'oauth',
				'username'  => '',
				'password'  => '',
				'mcp_oauth' => wp_json_encode(
					array(
						'access_token'  => 'access-secret',
						'refresh_token' => 'refresh-secret',
					)
				),
			)
		);

		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertNotSame( 'access-secret', $connection['mcp_oauth'] );
		$this->assertStringNotContainsString( 'access-secret', (string) $connection['mcp_oauth'] );

		$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] );
		$decoded   = json_decode( $decrypted, true );
		$this->assertSame( 'access-secret', $decoded['access_token'] );
	}

	/**
	 * Basic/application-password auth maps onto the MCP client's basic auth.
	 */
	public function test_build_mcp_config_maps_basic_auth() {
		$id         = $this->save_mcp_connection();
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_mcp_app_config_from_connection( $connection );

		$this->assertSame( 'basic', $config['auth_type'] );
		$this->assertSame( 'mcp-agent:secret-app-password', $config['token'] );
		$this->assertSame( 'https://example.com/wp-json/mcp/elementor-mcp-server/', $config['server_url'] );
	}

	/**
	 * Bearer auth maps onto the MCP client's bearer auth with a decrypted token.
	 */
	public function test_build_mcp_config_maps_bearer_auth() {
		$id = $this->save_mcp_connection(
			array(
				'auth_type' => 'bearer',
				'username'  => '',
				'password'  => '',
				'token'     => 'bearer-token-value',
			)
		);

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_mcp_app_config_from_connection( $connection );

		$this->assertSame( 'bearer', $config['auth_type'] );
		$this->assertSame( 'bearer-token-value', $config['token'] );
	}

	/**
	 * Custom-header auth maps onto the MCP client's header auth.
	 */
	public function test_build_mcp_config_maps_custom_header() {
		$id = $this->save_mcp_connection(
			array(
				'auth_type'       => 'custom_header',
				'username'        => '',
				'password'        => '',
				'mcp_header_name' => 'Authorization',
				'token'           => 'Bearer user:app-password',
			)
		);

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_mcp_app_config_from_connection( $connection );

		$this->assertSame( 'header', $config['auth_type'] );
		$this->assertSame( 'Authorization', $config['header_name'] );
		$this->assertSame( 'Bearer user:app-password', $config['token'] );
	}

	/**
	 * Custom-header auth without a header name is rejected at save time.
	 */
	public function test_mcp_server_validation_requires_header_name() {
		$result = $this->save_mcp_connection(
			array(
				'auth_type' => 'custom_header',
				'username'  => '',
				'password'  => '',
				'token'     => 'Bearer abc',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_mcp_header', $result->get_error_code() );
	}

	/**
	 * Bearer auth without a token is rejected at save time.
	 */
	public function test_mcp_server_validation_requires_bearer_token() {
		$result = $this->save_mcp_connection(
			array(
				'auth_type' => 'bearer',
				'username'  => '',
				'password'  => '',
				'token'     => '',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_mcp_bearer', $result->get_error_code() );
	}

	/**
	 * Private/loopback hosts are rejected for MCP Server connections.
	 */
	public function test_mcp_server_restricted_host_rejected() {
		$result = $this->save_mcp_connection(
			array(
				'url'      => 'http://127.0.0.1:8000/mcp',
				'password' => 'x',
				'username' => 'u',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_restricted_host', $result->get_error_code() );
	}

	/**
	 * Test Connection performs a real JSON-RPC handshake via the MCP client.
	 */
	public function test_mcp_server_test_connection_handshake() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'body' => $this->rpc_result(
						array(
							'serverInfo'   => array(
								'name'    => 'Elementor MCP',
								'version' => '4.3.0',
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
									'name'        => 'elementor_read_page',
									'description' => 'Read a page',
								),
							),
						)
					),
				),
			)
		);

		$id         = $this->save_mcp_connection();
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Elementor MCP', $result['server_info']['name'] );
	}

	/**
	 * Tool discovery persists the snapshot on the connection record.
	 */
	public function test_mcp_server_discover_persists_snapshot() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'body' => $this->rpc_result(
						array(
							'serverInfo'   => array(
								'name'    => 'Elementor MCP',
								'version' => '4.3.0',
							),
							'capabilities' => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list'      => array(
					'body' => $this->rpc_result(
						array(
							'tools' => array(
								array( 'name' => 'elementor_read_page' ),
								array( 'name' => 'elementor_add_container' ),
							),
						)
					),
				),
			)
		);

		$id    = $this->save_mcp_connection();
		$tools = WP_MCP_AI_Pro_Remote_Site_Manager::discover_mcp_server_tools( $id, true );

		$this->assertNotWPError( $tools );
		$this->assertCount( 2, $tools );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 2, $connection['mcp_tool_count'] );
		$this->assertGreaterThan( 0, $connection['mcp_discovered_at'] );
		$this->assertTrue( $connection['mcp_last_test']['success'] );
	}

	/**
	 * The get_mcp_server_connections() helper filters by type.
	 */
	public function test_get_mcp_server_connections_filters_by_type() {
		$this->save_mcp_connection();

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Regular WP Site',
				'url'             => 'https://example.org',
				'connection_type' => 'wordpress',
				'auth_type'       => 'none',
				'enabled'         => true,
			)
		);

		$mcp = WP_MCP_AI_Pro_Remote_Site_Manager::get_mcp_server_connections();

		$this->assertCount( 1, $mcp );
		$this->assertSame( 'Elementor MCP', $mcp[0]['name'] );
	}

	/**
	 * Update_mcp_oauth() merges rotated tokens into the encrypted blob,
	 * preserves scope/client_id, and stays a no-op on identical data.
	 */
	public function test_update_mcp_oauth_merges_rotated_tokens_and_stays_encrypted() {
		$id = $this->save_mcp_connection(
			array(
				'auth_type' => 'oauth',
				'username'  => '',
				'password'  => '',
				'mcp_oauth' => wp_json_encode(
					array(
						'access_token'  => 'tok-old',
						'refresh_token' => 'ref-old',
						'token_type'    => 'Bearer',
						'expires_in'    => 3600,
						'scope'         => 'jobs.read',
						'issued_at'     => time() - 7200,
						'client_id'     => 'upwork-client-123',
					)
				),
			)
		);

		$this->assertNotWPError( $id );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$id,
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => '',
				'issued_at'     => time(),
			)
		);

		$this->assertTrue( $updated );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		// Still encrypted at rest: no plaintext token material.
		$this->assertStringNotContainsString( 'tok-new', (string) $connection['mcp_oauth'] );
		$this->assertStringNotContainsString( 'ref-new', (string) $connection['mcp_oauth'] );

		$decoded = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'tok-new', $decoded['access_token'] );
		$this->assertSame( 'ref-new', $decoded['refresh_token'] );
		// Stored scope survives a refresh response that omits it.
		$this->assertSame( 'jobs.read', $decoded['scope'] );
		// The dynamic client ID must never be dropped.
		$this->assertSame( 'upwork-client-123', $decoded['client_id'] );

		// Re-applying identical data is a no-op.
		$again = WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$id,
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => '',
				'issued_at'     => $decoded['issued_at'],
			)
		);
		$this->assertTrue( $again );
	}

	/**
	 * Update_mcp_oauth() refuses to touch non-OAuth MCP Server connections.
	 */
	public function test_update_mcp_oauth_rejects_non_oauth_connections() {
		$id = $this->save_mcp_connection();
		$this->assertNotWPError( $id );

		$before = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$id,
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'issued_at'     => time(),
			)
		);

		$this->assertFalse( $updated );

		$after = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( $before['mcp_oauth'], $after['mcp_oauth'] );
	}

	/**
	 * A successful central refresh records an activity entry so operators can
	 * see token rotation in the logs — without ever logging credential material.
	 */
	public function test_update_mcp_oauth_logs_activity_event() {
		// Enable logging and reset the static settings cache so the logger's
		// base gate observes the test's write.
		$previous_settings          = get_option( 'wp_mcp_ai_settings', array() );
		$settings                   = is_array( $previous_settings ) ? $previous_settings : array();
		$settings['enable_logging'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		try {
			$id = $this->save_mcp_connection(
				array(
					'auth_type' => 'oauth',
					'username'  => '',
					'password'  => '',
					'mcp_oauth' => wp_json_encode(
						array(
							'access_token'  => 'tok-old',
							'refresh_token' => 'ref-old',
							'expires_in'    => 3600,
							'issued_at'     => time() - 7200,
						)
					),
				)
			);
			$this->assertNotWPError( $id );

			WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
				$id,
				array(
					'access_token'  => 'tok-new',
					'refresh_token' => 'ref-new',
					'token_type'    => 'Bearer',
					'expires_in'    => 3600,
					'issued_at'     => time(),
				)
			);

			if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
				$this->markTestSkipped( 'WP_MCP_AI_Logger is not available.' );
			}

			$entries = WP_MCP_AI_Logger::get_recent_activity_entries( 50, array( 'mcp_oauth_refresh' ), 0, 'OAuth token refreshed' );

			$found = null;
			foreach ( $entries as $entry ) {
				if ( isset( $entry['context']['connection_id'] ) && $entry['context']['connection_id'] === $id ) {
					$found = $entry;
					break;
				}
			}

			$this->assertNotNull( $found, 'Expected an activity entry for the central OAuth refresh.' );
			$this->assertSame( 'mcp_oauth_refresh', $found['type'] );
			$this->assertTrue( ! empty( $found['context']['refresh_token_rotated'] ) );
			$this->assertSame( 3600, (int) $found['context']['expires_in'] );

			// The entry must never carry credential material.
			$serialized = wp_json_encode( $found );
			$this->assertStringNotContainsString( 'tok-new', $serialized );
			$this->assertStringNotContainsString( 'ref-new', $serialized );
			$this->assertStringNotContainsString( 'tok-old', $serialized );
			$this->assertStringNotContainsString( 'ref-old', $serialized );
		} finally {
			update_option( 'wp_mcp_ai_settings', $previous_settings );
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
				WP_MCP_AI_Admin_Settings::reset_settings_cache();
			}
		}
	}

	/**
	 * Save an Upwork connection fixture in MCP mode.
	 *
	 * @param array $overrides Field overrides.
	 * @return string|WP_Error Connection ID or error.
	 */
	protected function save_upwork_mcp_connection( array $overrides = array() ) {
		$data = array_merge(
			array(
				'name'            => 'Upwork MCP',
				'url'             => 'https://api.upwork.com/graphql',
				'connection_type' => 'upwork',
				'upwork_mode'     => 'mcp',
				'enabled'         => true,
			),
			$overrides
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
	}

	/**
	 * Get_upwork_mcp_connections() only returns Upwork connections in MCP mode.
	 */
	public function test_get_upwork_mcp_connections_filters_by_type_and_mode() {
		$this->save_upwork_mcp_connection();
		$this->save_upwork_mcp_connection(
			array(
				'name'          => 'Upwork API',
				'upwork_mode'   => 'api',
				'client_id'     => 'cid',
				'client_secret' => 'csecret',
			)
		);
		$this->save_mcp_connection();

		$upwork = WP_MCP_AI_Pro_Remote_Site_Manager::get_upwork_mcp_connections();

		$this->assertCount( 1, $upwork );
		$this->assertSame( 'Upwork MCP', $upwork[0]['name'] );
	}

	/**
	 * Is_mcp_app_connection() accepts mcp_server and Upwork MCP connections only.
	 */
	public function test_is_mcp_app_connection_accepts_referenceable_types() {
		$mcp_id    = $this->save_mcp_connection();
		$upwork_id = $this->save_upwork_mcp_connection();
		$api_id    = $this->save_upwork_mcp_connection(
			array(
				'name'          => 'Upwork API',
				'upwork_mode'   => 'api',
				'client_id'     => 'cid',
				'client_secret' => 'csecret',
			)
		);

		$this->assertNotWPError( $mcp_id );
		$this->assertNotWPError( $upwork_id );
		$this->assertNotWPError( $api_id );

		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $mcp_id ) ) );
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $upwork_id ) ) );
		$this->assertFalse( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $api_id ) ) );
		$this->assertFalse( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( 'not-an-array' ) );
	}

	/**
	 * Get_mcp_app_connections() merges MCP Server and Upwork MCP connections.
	 */
	public function test_get_mcp_app_connections_merges_both_kinds() {
		$this->save_mcp_connection();
		$this->save_upwork_mcp_connection();

		$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_mcp_app_connections();

		$this->assertCount( 2, $all );

		$types = wp_list_pluck( $all, 'connection_type' );
		$this->assertContains( 'mcp_server', $types );
		$this->assertContains( 'upwork', $types );
	}

	/**
	 * Build_upwork_mcp_app_config() resolves the gateway URL and the encrypted
	 * OAuth blob into an MCP App client config.
	 */
	public function test_build_upwork_mcp_config_uses_gateway_and_decrypts_blob() {
		$id = $this->save_upwork_mcp_connection(
			array(
				'mcp_oauth' => wp_json_encode(
					array(
						'access_token'  => 'upwork-access',
						'refresh_token' => 'upwork-refresh',
						'token_type'    => 'Bearer',
						'expires_in'    => 3600,
						'issued_at'     => time(),
						'client_id'     => 'upwork-client',
					)
				),
			)
		);
		$this->assertNotWPError( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$config     = WP_MCP_AI_Pro_Remote_Site_Manager::build_upwork_mcp_app_config( $connection );

		$this->assertSame( 'https://mcp.upwork.com/mcp', $config['server_url'] );
		$this->assertSame( 'oauth', $config['auth_type'] );
		$this->assertSame( 'upwork-access', $config['oauth_data']['access_token'] );
		$this->assertSame( 'upwork-client', $config['oauth_data']['client_id'] );
	}
}
