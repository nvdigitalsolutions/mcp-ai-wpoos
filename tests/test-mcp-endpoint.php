<?php
/**
 * Tests for the dedicated MCP endpoint with JSON-RPC 2.0 support.
 *
 * This test suite validates that the /mcp endpoint correctly implements
 * the Model Context Protocol specification using JSON-RPC 2.0 format.
 *
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_MCP_Endpoint_Test extends WP_UnitTestCase {

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
	 * Bearer token for authentication.
	 *
	 * @var string
	 */
	protected $bearer_token;

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

		// Generate a test credential.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, 'Test MCP Client' );
			if ( $credential && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}

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
	 * Send a JSON-RPC request to the MCP endpoint.
	 *
	 * @param array $message JSON-RPC message.
	 * @return WP_REST_Response
	 */
	protected function send_mcp_request( $message ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( $message ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test that the MCP endpoint is registered.
	 */
	public function test_mcp_endpoint_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/mcp', $routes, 'MCP endpoint should be registered' );
	}

	/**
	 * Test that MCP endpoint URL is included in assistant directory response.
	 */
	public function test_mcp_url_in_directory() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'rest', $data, 'Response should include rest URLs' );
		$this->assertArrayHasKey( 'mcp', $data['rest'], 'REST URLs should include mcp endpoint' );
		$this->assertStringContainsString( '/mcp-ai/v1/mcp', $data['rest']['mcp'] );
	}

	/**
	 * Test handling of empty request body.
	 */
	public function test_empty_request_body() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( '' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32700, $data['error']['code'] );
		$this->assertStringContainsString( 'Parse error', $data['error']['message'] );
	}

	/**
	 * Test handling of invalid JSON.
	 */
	public function test_invalid_json() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( '{ invalid json }' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32700, $data['error']['code'] );
	}

	/**
	 * Test handling of missing jsonrpc field.
	 */
	public function test_missing_jsonrpc_field() {
		$response = $this->send_mcp_request(
			array(
				'id'     => 1,
				'method' => 'initialize',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertStringContainsString( 'jsonrpc', $data['error']['message'] );
	}

	/**
	 * Test handling of missing method field.
	 */
	public function test_missing_method_field() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
			)
		);

		$data = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertStringContainsString( 'method', $data['error']['message'] );
	}

	/**
	 * Test MCP initialize method.
	 */
	public function test_initialize_method() {
		$response = $this->send_mcp_request(
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
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 1, $data['id'] );
		$this->assertArrayHasKey( 'result', $data );

		$result = $data['result'];
		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertArrayHasKey( 'capabilities', $result );
		$this->assertArrayHasKey( 'serverInfo', $result );
		$this->assertArrayHasKey( 'instructions', $result );
		$this->assertSame( 'WP oOS', $result['serverInfo']['name'] );
		$this->assertNotEmpty( $result['instructions'], 'Instructions should not be empty' );
	}

	/**
	 * Test MCP tools/list method.
	 */
	public function test_tools_list_method() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'] );
		$this->assertIsArray( $data['result']['tools'] );

		// Verify tool structure.
		if ( ! empty( $data['result']['tools'] ) ) {
			$tool = $data['result']['tools'][0];
			$this->assertArrayHasKey( 'name', $tool );
			$this->assertArrayHasKey( 'description', $tool );
			$this->assertArrayHasKey( 'inputSchema', $tool );
		}
	}

	/**
	 * Test MCP resources/list method.
	 */
	public function test_resources_list_method() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'resources/list',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'] );
		$this->assertIsArray( $data['result']['resources'] );
	}

	/**
	 * Test MCP prompts/list method.
	 */
	public function test_prompts_list_method() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'prompts/list',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'prompts', $data['result'] );
		$this->assertIsArray( $data['result']['prompts'] );
	}

	/**
	 * Test handling of unknown method.
	 */
	public function test_unknown_method() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'unknown/method',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'] );
		$this->assertStringContainsString( 'Method not found', $data['error']['message'] );
	}

	/**
	 * Test notification (no id field) returns 202 Accepted.
	 */
	public function test_notification_request() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'logging/message',
				'params'  => array(
					'level'   => 'info',
					'message' => 'Test notification',
				),
			)
		);

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	/**
	 * Test authentication requirement.
	 */
	public function test_authentication_required() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status(), 'Should require authentication' );
	}

	/**
	 * Test bearer token authentication.
	 */
	public function test_bearer_token_authentication() {
		if ( empty( $this->bearer_token ) ) {
			$this->markTestSkipped( 'Bearer token not available' );
		}

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'Bearer token should authenticate successfully' );
	}

	/**
	 * Test CORS headers are present in MCP responses.
	 */
	public function test_cors_headers_present() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers, 'CORS origin header should be present' );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'], 'CORS should allow all origins' );
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers, 'CORS methods header should be present' );
		$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $headers, 'CORS headers header should be present' );
	}

	/**
	 * Test OPTIONS request for CORS preflight.
	 */
	public function test_options_request() {
		$request  = new WP_REST_Request( 'OPTIONS', '/mcp-ai/v1/mcp' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 204, $response->get_status(), 'OPTIONS should return 204' );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $headers );
		$this->assertArrayHasKey( 'Access-Control-Max-Age', $headers );
	}
}
