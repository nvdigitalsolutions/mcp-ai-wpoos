<?php
/**
 * Tests for WP_MCP_AI_MCP_App_Client connection enhancements.
 *
 * Covers:
 *  - Basic auth (raw user:pass auto-encoding + pre-encoded passthrough).
 *  - Mcp-Session-Id capture and echo (sessionful server fallback).
 *  - Negotiated protocol version advertised on post-initialize requests.
 *  - JSON-RPC error decoding on non-2xx responses.
 *  - test_connection() fallback from server/discover to initialize.
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
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Testing Basic auth credential encoding per RFC 7617.
		$this->assertSame( 'Basic ' . base64_encode( 'user:pass' ), $headers['Authorization'] );
	}

	/**
	 * Test basic auth passes pre-encoded base64 credentials through untouched.
	 */
	public function test_basic_auth_preencoded_credentials_passthrough() {
		$this->install_http_mock( array() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Testing Basic auth credential passthrough per RFC 7617.
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

		// Post-negotiation requests must advertise the server's protocol
		// version, not the client's 2026-07-28 default.
		$this->assertSame( '2025-11-25', $this->captured[2]['headers']['MCP-Protocol-Version'] );

		// The _meta envelope is a 2026-07-28 construct and must not be sent
		// to a legacy sessionful server.
		$payload = json_decode( $this->captured[2]['body'], true );
		$this->assertArrayNotHasKey( '_meta', $payload['params'] );
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

		// The tools/list request must carry the captured session ID and the
		// negotiated protocol version.
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
		$this->assertSame( '2025-11-25', $tools_list_request['headers']['MCP-Protocol-Version'] );
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

		// The stateless path keeps advertising 2026-07-28 and the _meta envelope.
		$tools_list_request = null;
		foreach ( $this->captured as $args ) {
			$payload = json_decode( $args['body'], true );
			if ( isset( $payload['method'] ) && 'tools/list' === $payload['method'] ) {
				$tools_list_request = $args;
				break;
			}
		}
		$this->assertNotNull( $tools_list_request );
		$this->assertSame( '2026-07-28', $tools_list_request['headers']['MCP-Protocol-Version'] );
		$payload = json_decode( $tools_list_request['body'], true );
		$this->assertArrayHasKey( '_meta', $payload['params'] );
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
