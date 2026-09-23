<?php
/**
 * Tests for MCP Apps connection enhancements.
 *
 * Covers the Phase 1-2 enhancements:
 *  - Basic auth (raw user:pass auto-encoding + pre-encoded passthrough).
 *  - Mcp-Session-Id capture and echo (sessionful server fallback).
 *  - JSON-RPC error decoding on non-2xx responses.
 *  - test_connection() fallback from server/discover to initialize.
 *  - Registry status persistence and pruning.
 *  - REST /test and /discover handlers with stored-token resolution.
 *
 * @package WP_MCP_AI
 * @since   1.9.1
 */

/**
 * Tests for WP_MCP_AI_MCP_App_Client connection enhancements.
 */
class Test_MCP_App_Client_Connection_Enhancements extends WP_UnitTestCase {

	/**
	 * Requests captured by the HTTP mock.
	 *
	 * @var array<int, array>
	 */
	protected $captured = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		$this->captured = array();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Install a pre_http_request mock that routes JSON-RPC methods to responses.
	 *
	 * Unmatched methods receive a generic 200 with an empty result so no test
	 * can accidentally hit the network.
	 *
	 * @param array $responses Map of JSON-RPC method => response payload.
	 * @return void
	 */
	protected function install_http_mock( array $responses ) {
		$captured =& $this->captured;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured, $responses ) {
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
	 * Test basic auth encodes raw user:password credentials.
	 */
	public function test_basic_auth_raw_credentials_encoded() {
		$this->install_http_mock( array() );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'basic',
				'token'      => 'user:pass',
			)
		);

		$client->discover();

		$this->assertNotEmpty( $this->captured );
		$headers = $this->captured[0]['headers'];
		$this->assertArrayHasKey( 'Authorization', $headers );
		$this->assertSame( 'Basic ' . base64_encode( 'user:pass' ), $headers['Authorization'] );
	}

	/**
	 * Test basic auth passes pre-encoded base64 credentials through untouched.
	 */
	public function test_basic_auth_preencoded_credentials_passthrough() {
		$this->install_http_mock( array() );

		$encoded = base64_encode( 'user:pass' );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'basic',
				'token'      => $encoded,
			)
		);

		$client->discover();

		$headers = $this->captured[0]['headers'];
		$this->assertSame( 'Basic ' . $encoded, $headers['Authorization'] );
	}

	/**
	 * Test that a missing basic credential returns an error.
	 */
	public function test_basic_auth_missing_token_errors() {
		$this->install_http_mock( array() );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'basic',
				'token'      => '',
			)
		);

		$result = $client->discover();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_missing_token', $result->get_error_code() );
	}

	/**
	 * Test the session ID from initialize is captured and echoed on subsequent requests.
	 */
	public function test_session_id_captured_and_echoed() {
		$this->install_http_mock(
			array(
				'initialize' => array(
					'headers' => array( 'mcp-session-id' => 'sess-abc-123' ),
					'body'    => $this->rpc_result(
						array(
							'protocolVersion' => '2025-11-25',
							'serverInfo'      => array(
								'name'    => 'Elementor MCP',
								'version' => 'v1.0.0',
							),
							'capabilities'    => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list' => array(
					'body' => $this->rpc_result( array( 'tools' => array() ) ),
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$client->initialize();
		$this->assertSame( 'sess-abc-123', $client->get_session_id() );

		$tools = $client->list_tools();
		$this->assertIsArray( $tools );

		// Requests: [0] initialize, [1] notifications/initialized, [2] tools/list.
		$this->assertCount( 3, $this->captured );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $this->captured[0]['headers'] );
		$this->assertSame( 'sess-abc-123', $this->captured[1]['headers']['Mcp-Session-Id'] );
		$this->assertSame( 'sess-abc-123', $this->captured[2]['headers']['Mcp-Session-Id'] );
	}

	/**
	 * Test an HTTP 4xx with a JSON-RPC error body surfaces the RPC code.
	 */
	public function test_http_error_with_rpc_body_surfaces_rpc_code() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'code' => 400,
					'body' => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'error'   => array(
								'code'    => -32600,
								'message' => 'Invalid Request: Missing Mcp-Session-Id header',
							),
						)
					),
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$result = $client->discover();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_rpc_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertEquals( -32600, $data['rpc_code'] );
		$this->assertEquals( 400, $data['status'] );
	}

	/**
	 * Test test_connection falls back to the sessionful initialize handshake
	 * when the server rejects the stateless discover request.
	 */
	public function test_test_connection_falls_back_to_sessionful_initialize() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'code' => 400,
					'body' => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'error'   => array(
								'code'    => -32600,
								'message' => 'Invalid Request: Missing Mcp-Session-Id header',
							),
						)
					),
				),
				'initialize'      => array(
					'headers' => array( 'mcp-session-id' => 'sess-fallback' ),
					'body'    => $this->rpc_result(
						array(
							'protocolVersion' => '2025-11-25',
							'serverInfo'      => array(
								'name'    => 'Elementor MCP',
								'version' => 'v1.0.0',
							),
							'capabilities'    => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list'      => array(
					'body' => $this->rpc_result(
						array(
							'tools' => array(
								array( 'name' => 'read_page' ),
								array( 'name' => 'write_page' ),
							),
						)
					),
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'initialize', $result['handshake'] );
		$this->assertEquals( '2025-11-25', $result['protocol'] );
		$this->assertEquals( 'Elementor MCP', $result['server_info']['name'] );
		$this->assertEquals( 2, $result['tool_count'] );
		$this->assertTrue( $result['session_active'] );
		$this->assertIsInt( $result['latency_ms'] );

		// The tools/list request must carry the captured session ID.
		$tools_list_request = null;
		foreach ( $this->captured as $args ) {
			$payload = json_decode( $args['body'], true );
			if ( isset( $payload['method'] ) && 'tools/list' === $payload['method'] ) {
				$tools_list_request = $args;
				break;
			}
		}
		$this->assertNotNull( $tools_list_request );
		$this->assertSame( 'sess-fallback', $tools_list_request['headers']['Mcp-Session-Id'] );
	}

	/**
	 * Test test_connection uses the stateless discover handshake for
	 * 2026-07-28 servers.
	 */
	public function test_test_connection_stateless_discover_path() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'body' => $this->rpc_result(
						array(
							'serverInfo'   => array(
								'name'    => 'Modern MCP',
								'version' => '2.0.0',
							),
							'capabilities' => array( 'tools' => array() ),
						)
					),
				),
				'tools/list'      => array(
					'body' => $this->rpc_result( array( 'tools' => array( array( 'name' => 'ping' ) ) ) ),
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertEquals( 'discover', $result['handshake'] );
		$this->assertEquals( '2026-07-28', $result['protocol'] );
		$this->assertEquals( 1, $result['tool_count'] );
		$this->assertFalse( $result['session_active'] );
	}

	/**
	 * Test test_connection still returns the original error for non-protocol failures.
	 */
	public function test_test_connection_does_not_fall_back_on_http_500() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'code' => 500,
					'body' => 'Internal Server Error',
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$result = $client->test_connection();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_http_error', $result->get_error_code() );
	}
}

/**
 * Tests for WP_MCP_AI_MCP_App_Registry connection enhancements.
 */
class Test_MCP_App_Registry_Connection_Enhancements extends WP_UnitTestCase {

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Snapshot of the settings option taken before an allowlist test mutates it.
	 *
	 * @var array|false|null
	 */
	protected $settings_option_before = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'update_post_meta' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_mcp_app_allowed_hosts' );

		if ( null !== $this->settings_option_before ) {
			if ( false === $this->settings_option_before ) {
				delete_option( 'wp_mcp_ai_settings' );
			} else {
				update_option( 'wp_mcp_ai_settings', $this->settings_option_before );
			}
			$this->settings_option_before = null;
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		parent::tearDown();
	}

	/**
	 * Store the MCP App Allowed Hosts setting and reset the settings cache.
	 *
	 * @param string $hosts Newline-separated host entries.
	 * @return void
	 */
	protected function set_allowed_hosts_setting( $hosts ) {
		if ( null === $this->settings_option_before ) {
			$this->settings_option_before = get_option( 'wp_mcp_ai_settings', array() );
		}

		$settings                          = is_array( $this->settings_option_before ) ? $this->settings_option_before : array();
		$settings['mcp_app_allowed_hosts'] = $hosts;
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Test sanitize_app_config accepts the basic auth type.
	 */
	public function test_sanitize_accepts_basic_auth() {
		$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'basic',
				'token'      => 'user:pass',
			)
		);

		$this->assertEquals( 'basic', $sanitized['auth_type'] );
		$this->assertEquals( 'user:pass', $sanitized['token'] );
	}

	/**
	 * Test record_app_status / get_app_status roundtrip.
	 */
	public function test_record_and_get_app_status() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$config   = array(
			'server_url'  => 'https://example.com/mcp',
			'auth_type'   => 'bearer',
			'header_name' => '',
		);

		$this->assertTrue(
			$registry->record_app_status(
				$this->assistant_id,
				$config,
				array(
					'last_status' => 'ok',
					'tool_count'  => 3,
					'protocol'    => '2025-11-25',
				)
			)
		);

		$statuses = $registry->get_app_status( $this->assistant_id );
		$key      = $registry->get_app_status_key( $config );

		$this->assertArrayHasKey( $key, $statuses );
		$this->assertEquals( 'ok', $statuses[ $key ]['last_status'] );
		$this->assertEquals( 3, $statuses[ $key ]['tool_count'] );
		$this->assertEquals( '2025-11-25', $statuses[ $key ]['protocol'] );
		$this->assertGreaterThan( 0, $statuses[ $key ]['checked_at'] );
	}

	/**
	 * Test record_app_status skips the meta write when nothing changed.
	 */
	public function test_record_app_status_skips_unchanged_write() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$config   = array(
			'server_url' => 'https://example.com/mcp',
			'auth_type'  => 'bearer',
		);

		$writes = 0;
		add_filter(
			'update_post_meta',
			function ( $check, $object_id, $meta_key, $meta_value, $prev_value ) use ( &$writes ) {
				if ( WP_MCP_AI_MCP_App_Registry::STATUS_META_KEY === $meta_key ) {
					$writes++;
				}
				return $check;
			},
			10,
			5
		);

		$status = array(
			'last_status' => 'ok',
			'tool_count'  => 1,
		);

		$registry->record_app_status( $this->assistant_id, $config, $status );
		$registry->record_app_status( $this->assistant_id, $config, $status );

		$this->assertSame( 1, $writes, 'An identical status snapshot must not rewrite post meta.' );

		// A changed snapshot must write again.
		$status['tool_count'] = 2;
		$registry->record_app_status( $this->assistant_id, $config, $status );

		$this->assertSame( 2, $writes );
	}

	/**
	 * Test save_apps prunes status entries for removed apps.
	 */
	public function test_save_apps_prunes_removed_statuses() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$removed = array(
			'server_url' => 'https://removed.example.com/mcp',
			'auth_type'  => 'none',
		);
		$kept    = array(
			'server_url' => 'https://kept.example.com/mcp',
			'auth_type'  => 'none',
		);

		$registry->record_app_status(
			$this->assistant_id,
			$removed,
			array(
				'last_status' => 'ok',
				'tool_count'  => 5,
			)
		);

		$registry->save_apps( $this->assistant_id, array( $kept ) );

		$statuses = $registry->get_app_status( $this->assistant_id );

		$this->assertArrayNotHasKey( $registry->get_app_status_key( $removed ), $statuses );
		$this->assertArrayNotHasKey( $registry->get_app_status_key( $kept ), $statuses, 'No status was ever recorded for the kept app.' );
	}

	/**
	 * Test the Security Center setting feeds the allowlist and blocks other hosts.
	 */
	public function test_allowlist_reads_security_center_setting() {
		$this->set_allowed_hosts_setting( "mcp.example.com\n*.corp.example.com" );

		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/mcp' )
		);
		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://api.corp.example.com/mcp' ),
			'A *. wildcard entry must match single subdomains.'
		);

		$blocked = WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://evil.example.net/mcp' );
		$this->assertWPError( $blocked );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_url_not_allowed', $blocked->get_error_code() );
	}

	/**
	 * Test the filter output and the saved setting are merged.
	 */
	public function test_allowlist_merges_filter_and_setting() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'filter.example.com' );
			}
		);

		$this->set_allowed_hosts_setting( "mcp.example.com\n" );

		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://filter.example.com/mcp' ),
			'Hosts from the filter must remain allowed.'
		);
		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/mcp' ),
			'Hosts from the saved setting must be allowed.'
		);
		$this->assertWPError(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://other.example.net/mcp' )
		);
	}

	/**
	 * Test discover_tools falls back to initialize for sessionful servers.
	 */
	public function test_discover_tools_sessionful_fallback() {
		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requests ) {
				$payload    = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method     = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';
				$requests[] = $method;

				if ( 'server/discover' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'error'   => array(
									'code'    => -32601,
									'message' => 'Method not found',
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( 'initialize' === $method ) {
					return array(
						'headers'  => array( 'mcp-session-id' => 'sess-registry' ),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'result'  => array(
									'protocolVersion' => '2025-11-25',
									'serverInfo'      => array( 'name' => 'Elementor MCP' ),
									'capabilities'    => array( 'tools' => new stdClass() ),
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( 'tools/list' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'result'  => array(
									'tools' => array(
										array( 'name' => 'read_page' ),
									),
								),
							)
						),
						'response' => array(
							'code'    => 200,
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

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$tools    = $registry->discover_tools(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			),
			true
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $tools );
		$this->assertCount( 1, $tools );
		$this->assertEquals( 'read_page', $tools[0]['name'] );
		$this->assertContains( 'server/discover', $requests );
		$this->assertContains( 'initialize', $requests );
		$this->assertContains( 'tools/list', $requests );
	}
}

/**
 * Tests for the MCP Apps REST controller enhancements.
 */
class Test_REST_MCP_Apps_Connection_Enhancements extends WP_UnitTestCase {

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_REST_MCP_Apps_Controller' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Allowlist the test host so the SSRF URL guard passes without DNS.
		add_filter(
			'wp_mcp_ai_http_allowed_host',
			function ( $hosts, $host, $url ) {
				$hosts[] = 'example.com';
				return $hosts;
			},
			10,
			3
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_http_allowed_host' );
		parent::tearDown();
	}

	/**
	 * Install a pre_http_request mock that records requests and serves a
	 * successful stateless discover + tools/list exchange.
	 *
	 * @param array $captured Reference for captured request args.
	 * @return void
	 */
	protected function install_success_mock( &$captured ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured[] = $args;

				$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';

				$result = array( 'serverInfo' => array( 'name' => 'Modern MCP' ) );
				if ( 'tools/list' === $method ) {
					$result = array( 'tools' => array( array( 'name' => 'ping' ) ) );
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => $result,
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
	 * Test the /test handler resolves a masked (omitted) token from the
	 * assistant's saved apps and persists a success status.
	 */
	public function test_test_handler_resolves_stored_token_and_persists_status() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'       => 'Saved App',
					'server_url'  => 'https://example.com/mcp',
					'auth_type'   => 'header',
					'token'       => 'stored-secret',
					'header_name' => 'X-API-Key',
					'enabled'     => true,
				),
			)
		);

		$captured = array();
		$this->install_success_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/test' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'auth_type', 'header' );
		$request->set_param( 'token', '' ); // Masked in the metabox.
		$request->set_param( 'header_name', 'X-API-Key' );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->test_connection( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Modern MCP', $data['server_info']['name'] );

		// The stored credential must have been attached to the outbound request.
		$this->assertNotEmpty( $captured );
		$this->assertSame( 'stored-secret', $captured[0]['headers']['X-API-Key'] );

		// Status must be persisted for the metabox badge.
		$statuses = $registry->get_app_status( $this->assistant_id );
		$key      = $registry->get_app_status_key(
			array(
				'server_url'  => 'https://example.com/mcp',
				'auth_type'   => 'header',
				'header_name' => 'X-API-Key',
			)
		);
		$this->assertArrayHasKey( $key, $statuses );
		$this->assertEquals( 'ok', $statuses[ $key ]['last_status'] );
		$this->assertEquals( 1, $statuses[ $key ]['tool_count'] );
	}

	/**
	 * Test the /test handler records an error status when the connection fails.
	 */
	public function test_test_handler_records_error_status() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'error'   => array(
								'code'    => -32600,
								'message' => 'Invalid Request: Missing Mcp-Session-Id header',
							),
						)
					),
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/test' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'auth_type', 'none' );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->test_connection( $request );

		$this->assertWPError( $response );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$statuses = $registry->get_app_status( $this->assistant_id );

		$this->assertNotEmpty( $statuses );
		$entry = reset( $statuses );
		$this->assertEquals( 'error', $entry['last_status'] );
		$this->assertStringContainsString( 'Missing Mcp-Session-Id', $entry['last_error'] );
	}

	/**
	 * Test the /discover handler returns formatted tools and persists status.
	 */
	public function test_discover_handler_returns_tools_and_persists_status() {
		$captured = array();
		$this->install_success_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/discover' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'auth_type', 'none' );
		$request->set_param( 'refresh', true );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->discover_tools( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 1, $data['tool_count'] );
		$this->assertEquals( 'ping', $data['tools'][0]['name'] );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$statuses = $registry->get_app_status( $this->assistant_id );
		$this->assertNotEmpty( $statuses );
		$entry = reset( $statuses );
		$this->assertEquals( 'ok', $entry['last_status'] );
		$this->assertEquals( 1, $entry['tool_count'] );
	}
}
