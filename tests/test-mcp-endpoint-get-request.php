<?php
/**
 * Tests for MCP endpoint GET request behavior.
 *
 * This test suite validates that the /mcp endpoint properly handles GET requests:
 * 1. Returns JSON discovery information by default (for LM Studio compatibility)
 * 2. Returns SSE stream when explicitly requested via ?stream=true
 * 3. Returns SSE stream when Accept: text/event-stream header is set
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
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $client Mock client.
	 */
	protected function bootstrap_rest_controller( $client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that GET /mcp returns JSON discovery by default.
	 *
	 * This ensures LM Studio and other JSON-RPC clients get proper discovery info
	 * without accidentally receiving SSE streams.
	 */
	public function test_mcp_get_returns_json_discovery_by_default() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Simple GET request to /mcp without any special parameters.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /mcp should return 200' );

		$headers = $response->get_headers();
		$this->assertStringStartsWith(
			'application/json',
			$headers['Content-Type'] ?? '',
			'GET /mcp should return JSON content type by default'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be a JSON array' );
		$this->assertArrayHasKey( 'name', $data, 'Discovery response should include server name' );
		$this->assertArrayHasKey( 'protocolVersion', $data, 'Discovery response should include protocol version' );
		$this->assertArrayHasKey( 'capabilities', $data, 'Discovery response should include capabilities' );
		$this->assertArrayHasKey( 'transports', $data, 'Discovery response should include transport information' );
		$this->assertArrayHasKey( 'endpoints', $data, 'Discovery response should include endpoint URLs' );
	}

	/**
	 * Test that GET /mcp?stream=true returns SSE stream.
	 *
	 * When the stream parameter is explicitly set, the endpoint should
	 * switch to SSE mode.
	 */
	public function test_mcp_get_with_stream_param_returns_sse() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// GET request with stream=true parameter.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'stream', 'true' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /mcp?stream=true should return 200' );

		$headers = $response->get_headers();
		$this->assertStringStartsWith(
			'text/event-stream',
			$headers['Content-Type'] ?? '',
			'GET /mcp?stream=true should return SSE content type'
		);
	}

	/**
	 * Test that GET /mcp with Accept: text/event-stream returns JSON (not SSE).
	 *
	 * This is the LM Studio scenario - LM Studio sends Accept: text/event-stream
	 * by default, but expects JSON-RPC discovery response, not SSE.
	 * Accept header should NOT trigger SSE mode for /mcp endpoint.
	 */
	public function test_mcp_get_with_sse_accept_header_returns_json() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// GET request with Accept: text/event-stream header (LM Studio scenario).
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /mcp with Accept: text/event-stream should return 200' );

		// Should return JSON, NOT SSE, because Accept header is ignored.
		$headers = $response->get_headers();
		$this->assertStringStartsWith(
			'application/json',
			$headers['Content-Type'] ?? '',
			'GET /mcp with Accept: text/event-stream should return JSON (LM Studio compatibility)'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be a JSON array' );
		$this->assertArrayHasKey( 'name', $data, 'Discovery response should include server name' );
	}

	/**
	 * Test that GET /mcp with Accept: application/json returns JSON.
	 *
	 * When the client explicitly accepts JSON, it should receive JSON
	 * discovery info, not SSE.
	 */
	public function test_mcp_get_with_json_accept_header_returns_json() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// GET request with Accept: application/json header.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'application/json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /mcp with Accept: application/json should return 200' );

		$headers = $response->get_headers();
		$this->assertStringStartsWith(
			'application/json',
			$headers['Content-Type'] ?? '',
			'GET /mcp with Accept: application/json should return JSON content type'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be a JSON array' );
	}

	/**
	 * Test that discovery response indicates Streamable HTTP as the primary transport.
	 *
	 * The discovery information should clearly indicate that Streamable HTTP
	 * (MCP 2024-11-05) is the primary/default transport, with SSE as optional.
	 */
	public function test_discovery_indicates_streamable_http_as_primary_transport() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'transports', $data, 'Discovery should include transports' );
		$this->assertArrayHasKey( 'streamable_http', $data['transports'], 'Discovery should include Streamable HTTP transport' );
		$this->assertArrayHasKey( 'sse', $data['transports'], 'Discovery should include SSE transport' );

		// Streamable HTTP should be marked as default.
		$this->assertTrue(
			$data['transports']['streamable_http']['default'] ?? false,
			'Streamable HTTP should be marked as default transport'
		);

		// SSE should NOT be marked as default.
		$this->assertFalse(
			$data['transports']['sse']['default'] ?? true,
			'SSE should NOT be marked as default transport'
		);

		// Verify SSE capability indicates it's not default.
		$this->assertArrayHasKey( 'capabilities', $data, 'Discovery should include capabilities' );
		$this->assertArrayHasKey( 'sse', $data['capabilities'], 'Capabilities should include SSE info' );
		$this->assertFalse(
			$data['capabilities']['sse']['default'] ?? true,
			'SSE capability should indicate it is not the default'
		);
	}
}
