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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		self::$saw_authorization = '';
		self::$routed_bodies     = array();

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
}
