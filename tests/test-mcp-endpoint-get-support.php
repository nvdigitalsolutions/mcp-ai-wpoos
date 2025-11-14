<?php
/**
 * Tests for GET support on the /mcp endpoint.
 *
 * Validates that the MCP endpoint properly handles GET requests for:
 * - Endpoint discovery (without Accept: text/event-stream)
 * - SSE connections (with Accept: text/event-stream)
 * - MCP 2024-11-05 Streamable HTTP transport
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_MCP_Endpoint_GET_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test MCP Assistant',
			)
		);

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Bootstrap REST controller.
		$this->bootstrap_rest_controller();
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$this->rest_controller                = new WP_MCP_AI_REST( $registry, $mock_client );
		$GLOBALS['wp_mcp_ai_rest_controller'] = $this->rest_controller;

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that GET request to /mcp defaults to SSE stream.
	 *
	 * Per the new requirement, SSE should be the default transport.
	 * GET requests should establish SSE connections by default.
	 */
	public function test_mcp_get_defaults_to_sse() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'GET /mcp should return a REST response' );
		$this->assertSame( 200, $response->get_status(), 'GET /mcp should return 200 status' );

		// Check that response has SSE content type (default behavior).
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Content-Type', $headers, 'Response should include Content-Type header' );
		$this->assertStringStartsWith(
			'text/event-stream',
			$headers['Content-Type'],
			'GET /mcp should default to SSE (text/event-stream)'
		);
	}

	/**
	 * Test that GET request to /mcp with discovery parameter returns JSON.
	 *
	 * Clients can explicitly request discovery info by adding ?discovery=true.
	 */
	public function test_mcp_get_with_discovery_param_returns_json() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'discovery', 'true' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'GET /mcp?discovery=true should return a REST response' );
		$this->assertSame( 200, $response->get_status(), 'GET /mcp?discovery=true should return 200 status' );

		// Check that response is JSON (not SSE).
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Content-Type', $headers, 'Response should include Content-Type header' );
		$this->assertStringStartsWith(
			'application/json',
			$headers['Content-Type'],
			'Discovery request should return JSON'
		);

		$data = $response->get_data();

		// Verify basic server info.
		$this->assertArrayHasKey( 'name', $data, 'Response should include server name' );
		$this->assertSame( 'WP oOS MCP Server', $data['name'], 'Server name should be WP oOS MCP Server' );

		$this->assertArrayHasKey( 'version', $data, 'Response should include version' );
		$this->assertArrayHasKey( 'protocolVersion', $data, 'Response should include protocol version' );
		$this->assertSame( '2024-11-05', $data['protocolVersion'], 'Should support MCP 2024-11-05' );

		// Verify SSE is marked as default.
		$this->assertArrayHasKey( 'capabilities', $data, 'Response should include capabilities' );
		$this->assertArrayHasKey( 'sse', $data['capabilities'], 'Capabilities should include SSE info' );
		$this->assertTrue( $data['capabilities']['sse']['enabled'], 'SSE should be enabled' );
		$this->assertTrue( $data['capabilities']['sse']['default'], 'SSE should be marked as default' );

		// Verify transports show SSE as default.
		$this->assertArrayHasKey( 'transports', $data, 'Response should include transports' );
		$this->assertArrayHasKey( 'sse', $data['transports'], 'Should support SSE transport' );
		$this->assertTrue( $data['transports']['sse']['default'], 'SSE transport should be marked as default' );
	}

	/**
	 * Test that GET request with Accept: application/json returns discovery.
	 *
	 * If a client explicitly requests JSON via Accept header, they should
	 * get discovery info instead of SSE stream.
	 */
	public function test_mcp_get_with_json_accept_header_returns_discovery() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'application/json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'GET /mcp with JSON Accept should return a REST response' );
		$this->assertSame( 200, $response->get_status(), 'JSON request should return 200 status' );

		// Check that response is JSON (not SSE).
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Content-Type', $headers, 'Response should include Content-Type header' );
		$this->assertStringStartsWith(
			'application/json',
			$headers['Content-Type'],
			'Content-Type should be application/json when explicitly requested'
		);

		// Verify it's discovery data.
		$data = $response->get_data();
		$this->assertArrayHasKey( 'name', $data, 'Should return discovery data' );
		$this->assertArrayHasKey( 'capabilities', $data, 'Should include capabilities' );
	}

	/**
	 * Test that GET request with Accept: text/event-stream still works.
	 *
	 * Explicitly requesting SSE should still work, even though it's now the default.
	 */
	public function test_mcp_get_with_sse_accept_header_returns_sse() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'GET /mcp with SSE Accept should return a REST response' );
		$this->assertSame( 200, $response->get_status(), 'SSE request should return 200 status' );

		// Check that response has SSE content type.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Content-Type', $headers, 'Response should include Content-Type header' );
		$this->assertStringStartsWith(
			'text/event-stream',
			$headers['Content-Type'],
			'Content-Type should be text/event-stream'
		);
	}

	/**
	 * Test that CORS headers include GET method.
	 *
	 * MCP 2024-11-05 requires GET support for Streamable HTTP transport.
	 * CORS headers must allow GET requests.
	 */
	public function test_mcp_cors_headers_include_get() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers, 'Should include CORS origin header' );
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers, 'Should include CORS methods header' );

		$allowed_methods = $headers['Access-Control-Allow-Methods'];
		$this->assertStringContainsString( 'GET', $allowed_methods, 'CORS headers should allow GET method' );
		$this->assertStringContainsString( 'POST', $allowed_methods, 'CORS headers should allow POST method' );
		$this->assertStringContainsString( 'OPTIONS', $allowed_methods, 'CORS headers should allow OPTIONS method' );
	}

	/**
	 * Test that CORS headers include MCP 2024-11-05 required headers.
	 *
	 * The spec requires support for Accept and Mcp-Session-Id headers.
	 */
	public function test_mcp_cors_headers_include_mcp_2024_headers() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $headers, 'Should include CORS allow-headers' );

		$allowed_headers = $headers['Access-Control-Allow-Headers'];
		$this->assertStringContainsString( 'Accept', $allowed_headers, 'Should allow Accept header' );
		$this->assertStringContainsString( 'Mcp-Session-Id', $allowed_headers, 'Should allow Mcp-Session-Id header' );

		// Also check for exposed headers (needed for session ID response).
		$this->assertArrayHasKey( 'Access-Control-Expose-Headers', $headers, 'Should include CORS expose-headers' );
		$this->assertStringContainsString( 'Mcp-Session-Id', $headers['Access-Control-Expose-Headers'], 'Should expose Mcp-Session-Id header' );
	}

	/**
	 * Test that POST requests still work (backward compatibility).
	 *
	 * Adding GET support should not break existing POST JSON-RPC functionality.
	 */
	public function test_mcp_post_still_works_after_adding_get() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2024-11-05',
						'clientInfo'      => array(
							'name'    => 'Test Client',
							'version' => '1.0',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'POST /mcp should still work' );
		$this->assertSame( 200, $response->get_status(), 'POST /mcp should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data, 'Response should be JSON-RPC format' );
		$this->assertSame( '2.0', $data['jsonrpc'], 'Should be JSON-RPC 2.0' );
		$this->assertArrayHasKey( 'result', $data, 'Should include result' );
	}

	/**
	 * Test that OPTIONS request works for CORS preflight.
	 *
	 * Browsers send OPTIONS requests before cross-origin requests.
	 */
	public function test_mcp_options_request_works() {
		$request = new WP_REST_Request( 'OPTIONS', '/mcp-ai/v1/mcp' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'OPTIONS /mcp should return a response' );
		$this->assertSame( 204, $response->get_status(), 'OPTIONS should return 204 No Content' );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers, 'OPTIONS should include allowed methods' );
	}

	/**
	 * Test discovery response includes correct endpoint URLs.
	 *
	 * The discovery response should provide correct URLs for all endpoints
	 * to help clients configure themselves automatically.
	 */
	public function test_discovery_includes_correct_endpoint_urls() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'discovery', 'true' ); // Explicitly request discovery.

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'endpoints', $data );

		// Verify each endpoint URL is valid and points to the right namespace.
		foreach ( $data['endpoints'] as $name => $url ) {
			$this->assertStringContainsString( '/mcp-ai/v1/', $url, "Endpoint $name should be in correct namespace" );
			$this->assertStringStartsWith( 'http', $url, "Endpoint $name should be a valid URL" );
		}

		// Verify transport endpoint URLs.
		$this->assertArrayHasKey( 'transports', $data );
		$this->assertStringContainsString( '/mcp-ai/v1/mcp', $data['transports']['sse']['endpoint'] );
		$this->assertStringContainsString( '/mcp-ai/v1/mcp', $data['transports']['jsonrpc']['endpoint'] );

		// Verify usage examples are included.
		$this->assertArrayHasKey( 'usage', $data, 'Should include usage examples' );
		$this->assertArrayHasKey( 'sse_default', $data['usage'], 'Should explain SSE is default' );
		$this->assertArrayHasKey( 'discovery', $data['usage'], 'Should explain how to get discovery' );
	}
}
