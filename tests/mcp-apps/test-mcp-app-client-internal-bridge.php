<?php
/**
 * Tests for the in-process same-origin MCP bridge.
 *
 * A self-request over the public hostname can deadlock a small PHP-FPM pool
 * or stall on missing hairpin NAT (cURL error 28). The client must therefore
 * dispatch registered same-site REST routes through rest_do_request() instead
 * of an outbound HTTP call, falling back to HTTP for remote hosts and
 * unregistered routes.
 *
 * @package WP_MCP_AI
 * @since   1.9.2
 */

/**
 * Tests for the in-process same-origin bridge.
 */
class Test_MCP_App_Client_Internal_Bridge extends WP_UnitTestCase {

	/**
	 * Authorization header seen by the registered test route.
	 *
	 * @var string
	 */
	protected static $saw_authorization = '';

	/**
	 * Request bodies routed to the registered test route.
	 *
	 * @var array<int, string>
	 */
	protected static $routed_bodies = array();

	/**
	 * Sessions created by the EMCP-style route (session id => true).
	 *
	 * @var array<string, bool>
	 */
	protected static $emcp_sessions = array();

	/**
	 * Session id awaiting header attachment via rest_post_dispatch.
	 *
	 * @var string
	 */
	protected static $emcp_pending_session = '';

	/**
	 * Mcp-Session-Id request headers seen by the EMCP-style route.
	 *
	 * @var array<int, string>
	 */
	protected static $emcp_req_headers = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		self::$saw_authorization    = '';
		self::$routed_bodies        = array();
		self::$emcp_sessions        = array();
		self::$emcp_pending_session = '';
		self::$emcp_req_headers     = array();

		add_action( 'rest_api_init', array( $this, 'register_test_route' ) );

		// Force a fresh REST server so rest_api_init re-fires and the test
		// route registers on the server used by rest_do_request().
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_mcp_app_disable_inprocess_bridge' );
		remove_filter( 'rest_post_dispatch', array( $this, 'attach_emcp_session_header' ) );
		remove_action( 'rest_api_init', array( $this, 'register_test_route' ) );
		$GLOBALS['wp_rest_server'] = null;
		parent::tearDown();
	}

	/**
	 * Register a minimal MCP-shaped REST route for the tests.
	 *
	 * @return void
	 */
	public function register_test_route() {
		register_rest_route(
			'elementor',
			'/mcp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					self::$routed_bodies[]     = (string) $request->get_body();
					self::$saw_authorization   = (string) $request->get_header( 'Authorization' );

					$response = rest_ensure_response(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => array(
								'protocolVersion' => '2025-11-25',
								'serverInfo'      => array(
									'name'    => 'Elementor MCP',
									'version' => 'v1.0.0',
								),
							),
						)
					);
					$response->header( 'Mcp-Session-Id', 'sess-inprocess' );

					return $response;
				},
			)
		);

		// EMCP Tools-style route: the Mcp-Session-Id response header is attached
		// via the rest_post_dispatch filter (never directly on the response), and
		// tools/list rejects requests that do not echo the session back.
		register_rest_route(
			'elementor',
			'/mcp-emcp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_emcp_style_route' ),
			)
		);
	}

	/**
	 * Attach the pending EMCP session id as a response header.
	 *
	 * Registered on rest_post_dispatch by handle_emcp_style_route() to mirror
	 * the EMCP Tools server behaviour.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error $response REST response.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error
	 */
	public function attach_emcp_session_header( $response ) {
		if ( $response instanceof WP_REST_Response && '' !== self::$emcp_pending_session ) {
			$response->header( 'Mcp-Session-Id', self::$emcp_pending_session );
			self::$emcp_pending_session = '';
		}
		return $response;
	}

	/**
	 * Handle requests to the EMCP-style route.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return array JSON-RPC response body.
	 */
	public function handle_emcp_style_route( WP_REST_Request $request ) {
		$body   = json_decode( $request->get_body(), true );
		$method = isset( $body['method'] ) ? $body['method'] : '';
		$req_id = isset( $body['id'] ) ? $body['id'] : 1;

		self::$emcp_req_headers[] = (string) $request->get_header( 'Mcp-Session-Id' );

		$error = function ( $message ) use ( $req_id ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $req_id,
				'error'   => array(
					'code'    => -32600,
					'message' => $message,
				),
			);
		};

		if ( 'initialize' === $method ) {
			$session_id                         = wp_generate_password( 24, false );
			self::$emcp_sessions[ $session_id ] = true;
			self::$emcp_pending_session         = $session_id;

			add_filter( 'rest_post_dispatch', array( $this, 'attach_emcp_session_header' ) );

			return array(
				'jsonrpc' => '2.0',
				'id'      => $req_id,
				'result'  => array(
					'protocolVersion' => '2025-11-25',
					'serverInfo'      => array(
						'name'    => 'Elementor MCP',
						'version' => 'v1.0.0',
					),
				),
			);
		}

		$session_id = $request->get_header( 'Mcp-Session-Id' );
		if ( 'tools/list' === $method ) {
			if ( empty( $session_id ) || empty( self::$emcp_sessions[ $session_id ] ) ) {
				return $error( 'Invalid Request: Missing Mcp-Session-Id header' );
			}
			return array(
				'jsonrpc' => '2.0',
				'id'      => $req_id,
				'result'  => array(
					'tools' => array(
						array( 'name' => 'read_page' ),
					),
				),
			);
		}

		return $error( 'Invalid Request: Missing Mcp-Session-Id header' );
	}

	/**
	 * Install a pre_http_request counter that serves an empty JSON-RPC result.
	 *
	 * @param int $http_calls Reference for the outbound HTTP call counter.
	 * @return void
	 */
	protected function install_http_counter( &$http_calls ) {
		add_filter(
			'pre_http_request',
			function () use ( &$http_calls ) {
				$http_calls++;
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
	 * Test a registered same-origin REST route is dispatched in-process with
	 * no outbound HTTP request, and headers/session round-trip.
	 */
	public function test_same_origin_registered_route_dispatches_in_process() {
		$http_calls = 0;
		$this->install_http_counter( $http_calls );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => home_url( '/wp-json/elementor/mcp/' ),
				'auth_type'  => 'basic',
				'token'      => 'user:pass',
			)
		);

		$result = $client->initialize();

		$this->assertNotWPError( $result );
		$this->assertSame( 'Elementor MCP', $result['serverInfo']['name'] );
		$this->assertSame( 'sess-inprocess', $client->get_session_id() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Testing Basic auth credential encoding per RFC 7617.
		$this->assertSame( 'Basic ' . base64_encode( 'user:pass' ), self::$saw_authorization );
		$this->assertNotEmpty( self::$routed_bodies );
		$this->assertSame(
			0,
			$http_calls,
			'A registered same-origin REST route must never leave the process.'
		);
	}

	/**
	 * Test an unregistered same-origin path falls back to outbound HTTP.
	 */
	public function test_same_origin_unregistered_route_falls_back_to_http() {
		$http_calls = 0;
		$this->install_http_counter( $http_calls );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => home_url( '/wp-json/no/such/route/' ),
				'auth_type'  => 'none',
			)
		);

		$result = $client->initialize();

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual(
			1,
			$http_calls,
			'Unregistered routes must fall back to the HTTP transport.'
		);
	}

	/**
	 * Test remote hosts always use the HTTP transport.
	 */
	public function test_remote_host_uses_http_transport() {
		$http_calls = 0;
		$this->install_http_counter( $http_calls );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			)
		);

		$result = $client->initialize();

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $http_calls );
	}

	/**
	 * Test the escape-hatch filter forces the HTTP transport even for a
	 * registered same-origin route.
	 */
	public function test_disable_filter_forces_http_transport() {
		$http_calls = 0;
		$this->install_http_counter( $http_calls );

		add_filter( 'wp_mcp_ai_mcp_app_disable_inprocess_bridge', '__return_true' );

		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => home_url( '/wp-json/elementor/mcp/' ),
				'auth_type'  => 'none',
			)
		);

		$result = $client->initialize();

		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual(
			1,
			$http_calls,
			'The disable filter must force outbound HTTP.'
		);
	}

	/**
	 * Test the EMCP session round-trip: servers that attach Mcp-Session-Id via
	 * the rest_post_dispatch filter must have that filter applied during
	 * in-process dispatch so tools/list sees the session header.
	 */
	public function test_emcp_session_roundtrip_via_rest_post_dispatch() {
		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => home_url( '/wp-json/elementor/mcp-emcp/' ),
				'auth_type'  => 'none',
			)
		);

		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertSame(
			1,
			$result['tool_count'],
			'Tool enumeration must succeed when the session round-trips.'
		);
		$this->assertSame( '', $result['tool_error'] );
		$this->assertTrue( $result['session_active'], 'The negotiated session must be captured and replayed.' );
	}
}
