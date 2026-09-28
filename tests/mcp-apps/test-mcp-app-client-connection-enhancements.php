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

		// Post-negotiation requests to a legacy sessionful server must look
		// like a 2025-era client: no SEP-2243 routing headers.
		$this->assertArrayNotHasKey( 'MCP-Protocol-Version', $this->captured[2]['headers'] );
		$this->assertArrayNotHasKey( 'Mcp-Method', $this->captured[2]['headers'] );

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
		$this->assertArrayNotHasKey( 'MCP-Protocol-Version', $tools_list_request['headers'] );
		$this->assertArrayNotHasKey( 'Mcp-Method', $tools_list_request['headers'] );

		// The fallback initialize handshake itself must look like a 2025-era
		// client — strict gateways reject the 2026-07-28 routing headers with
		// a bare HTTP 400 before any JSON-RPC handling.
		$initialize_request = null;
		foreach ( $this->captured as $args ) {
			$payload = json_decode( $args['body'], true );
			if ( isset( $payload['method'] ) && 'initialize' === $payload['method'] ) {
				$initialize_request = $args;
				break;
			}
		}
		$this->assertNotNull( $initialize_request );
		$this->assertArrayNotHasKey( 'MCP-Protocol-Version', $initialize_request['headers'] );
		$this->assertArrayNotHasKey( 'Mcp-Method', $initialize_request['headers'] );
	}

	/**
	 * Test test_connection falls back to initialize when the server answers
	 * the discover probe with a bare HTTP 400 and no JSON-RPC error body.
	 *
	 * Strict 2025-era gateways (e.g. Upwork) behave exactly this way, and the
	 * fallback must trigger on the HTTP status alone.
	 */
	public function test_test_connection_falls_back_on_bare_http_400() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'code' => 400,
					'body' => 'Bad Request',
				),
				'initialize'      => array(
					'headers' => array( 'mcp-session-id' => 'sess-upwork' ),
					'body'    => $this->rpc_result(
						array(
							'protocolVersion' => '2025-03-26',
							'serverInfo'      => array(
								'name'    => 'Upwork MCP',
								'version' => '1.0.0',
							),
							'capabilities'    => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list'      => array(
					'body' => $this->rpc_result(
						array(
							'tools' => array(
								array( 'name' => 'search_jobs' ),
							),
						)
					),
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://mcp.upwork.com/mcp',
				'auth_type'  => 'oauth',
				'token'      => 'test-token',
			)
		);

		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertEquals( 'initialize', $result['handshake'] );
		$this->assertEquals( 'Upwork MCP', $result['server_info']['name'] );
		$this->assertEquals( 1, $result['tool_count'] );
		$this->assertTrue( $result['session_active'] );

		// The fallback initialize handshake must carry no 2026-07-28 routing
		// headers — strict gateways reject them before JSON-RPC handling.
		$initialize_request = null;
		foreach ( $this->captured as $args ) {
			$payload = json_decode( $args['body'], true );
			if ( isset( $payload['method'] ) && 'initialize' === $payload['method'] ) {
				$initialize_request = $args;
				break;
			}
		}
		$this->assertNotNull( $initialize_request );
		$this->assertArrayNotHasKey( 'MCP-Protocol-Version', $initialize_request['headers'] );
		$this->assertArrayNotHasKey( 'Mcp-Method', $initialize_request['headers'] );
		$this->assertSame( 'Bearer test-token', $initialize_request['headers']['Authorization'] );

		// Post-negotiation requests to the legacy session omit them too.
		$tools_list_request = null;
		foreach ( $this->captured as $args ) {
			$payload = json_decode( $args['body'], true );
			if ( isset( $payload['method'] ) && 'tools/list' === $payload['method'] ) {
				$tools_list_request = $args;
				break;
			}
		}
		$this->assertNotNull( $tools_list_request );
		$this->assertArrayNotHasKey( 'MCP-Protocol-Version', $tools_list_request['headers'] );
		$this->assertSame( 'sess-upwork', $tools_list_request['headers']['Mcp-Session-Id'] );
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

	/**
	 * Test an SSE tools/list response (text/event-stream) is parsed into the
	 * JSON-RPC payload — the Envoy AI Gateway responds this way after a
	 * sessionful initialize handshake.
	 */
	public function test_sse_tools_list_response_is_parsed() {
		$this->install_http_mock(
			array(
				'initialize' => array(
					'headers' => array( 'mcp-session-id' => 'sess-sse-123' ),
					'body'    => $this->rpc_result(
						array(
							'protocolVersion' => '2025-06-18',
							'serverInfo'      => array(
								'name'    => 'envoy-ai-gateway',
								'version' => 'v1.1.0',
							),
							'capabilities'    => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list' => array(
					'headers' => array( 'content-type' => 'text/event-stream' ),
					'body'    => "event: message\nid: 550e8400-e29b-41d4-a716-446655440000\ndata: " . $this->rpc_result(
						array(
							'tools' => array(
								array( 'name' => 'search_upwork_jobs' ),
								array( 'name' => 'score_upwork_job' ),
							),
						)
					) . "\n\n",
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
		$this->assertSame( 'sess-sse-123', $client->get_session_id() );

		$tools = $client->list_tools();
		$this->assertIsArray( $tools );
		$this->assertCount( 2, $tools );
		$this->assertSame( 'search_upwork_jobs', $tools[0]['name'] );
	}

	/**
	 * Test SSE bodies are sniffed even when the Content-Type header is missing
	 * or mislabeled as application/json.
	 */
	public function test_sse_body_sniffed_without_content_type_header() {
		$this->install_http_mock(
			array(
				'tools/list' => array(
					'body' => ": keep-alive ping\n\nevent: message\ndata: " . $this->rpc_result(
						array( 'tools' => array( array( 'name' => 'ping' ) ) )
					) . "\n\n",
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$tools = $client->list_tools();
		$this->assertIsArray( $tools );
		$this->assertCount( 1, $tools );
		$this->assertSame( 'ping', $tools[0]['name'] );
	}

	/**
	 * Test an SSE stream without a message event surfaces a dedicated error
	 * instead of the generic invalid-JSON failure.
	 */
	public function test_sse_stream_without_message_event_errors() {
		$this->install_http_mock(
			array(
				'tools/list' => array(
					'headers' => array( 'content-type' => 'text/event-stream' ),
					'body'    => ": only keep-alive pings\n\n: nothing else\n\n",
				),
			)
		);

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$result = $client->list_tools();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_empty_sse', $result->get_error_code() );
	}

	/**
	 * Test test_connection reports the tool count when the sessionful server
	 * answers tools/list with an SSE stream.
	 */
	public function test_test_connection_counts_tools_from_sse_stream() {
		$this->install_http_mock(
			array(
				'server/discover' => array(
					'code' => 400,
					'body' => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'error'   => array(
								'code'    => -32601,
								'message' => 'Method not found: server/discover',
							),
						)
					),
				),
				'initialize'      => array(
					'headers' => array( 'mcp-session-id' => 'sess-envoy' ),
					'body'    => $this->rpc_result(
						array(
							'protocolVersion' => '2025-06-18',
							'serverInfo'      => array(
								'name'    => 'envoy-ai-gateway',
								'version' => 'v1.1.0',
							),
							'capabilities'    => array( 'tools' => new stdClass() ),
						)
					),
				),
				'tools/list'      => array(
					'headers' => array( 'content-type' => 'text/event-stream' ),
					'body'    => "event: message\ndata: " . $this->rpc_result(
						array(
							'tools' => array(
								array( 'name' => 'search_upwork_jobs' ),
								array( 'name' => 'score_upwork_job' ),
							),
						)
					) . "\n\n",
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
		$this->assertEquals( '2025-06-18', $result['protocol'] );
		$this->assertEquals( 2, $result['tool_count'] );
		$this->assertSame( '', $result['tool_error'] );
		$this->assertTrue( $result['session_active'] );
	}
}
