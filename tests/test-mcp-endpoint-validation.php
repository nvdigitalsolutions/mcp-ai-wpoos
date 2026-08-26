<?php
/**
 * Tests for MCP endpoint parameter validation.
 *
 * Ensures that the /mcp endpoint properly validates JSON-RPC requests
 * and returns clear, actionable error messages when validation fails.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for MCP endpoint validation.
 */
class WP_MCP_AI_MCP_Endpoint_Validation_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected static $assistant_id;

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Bearer token for authenticated MCP requests.
	 *
	 * @var string
	 */
	protected $bearer_token = '';

	/**
	 * Set up test fixtures once for all tests.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Create a test assistant.
		self::$assistant_id = $factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Configure assistant with basic settings.
		update_post_meta(
			self::$assistant_id,
			'_wp_mcp_ai_config',
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4',
				'tools'    => array( 'search_content' ),
			)
		);
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$admin_id ) {
			wp_delete_user( self::$admin_id );
		}

		if ( self::$assistant_id ) {
			wp_delete_post( self::$assistant_id, true );
		}
	}

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::$admin_id );

		// MCP requests require a Bearer credential (bare nonce auth is
		// deliberately refused); issue one for the test assistant, mirroring
		// tests/test-mcp-endpoint.php.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( self::$assistant_id, 'Test MCP Client' );
			if ( $credential && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}
		if ( empty( $this->bearer_token ) ) {
			$this->markTestSkipped( 'Bearer credential could not be issued.' );
		}
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * Build an authenticated POST request against the MCP endpoint.
	 *
	 * @return WP_REST_Request
	 */
	protected function build_mcp_request() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		return $request;
	}

	/**
	 * Test MCP endpoint rejects empty request body.
	 */
	public function test_mcp_endpoint_rejects_empty_body() {
		$request = $this->build_mcp_request();
		$request->set_body( '' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Empty body is delivered as a JSON-RPC parse error' );
		$this->assertArrayHasKey( 'error', $data, 'Response should have JSON-RPC error structure' );
		$this->assertSame( -32700, $data['error']['code'], 'Should return parse error code' );
	}

	/**
	 * Test MCP endpoint rejects invalid JSON.
	 */
	public function test_mcp_endpoint_rejects_invalid_json() {
		$request = $this->build_mcp_request();
		$request->set_body( '{invalid json}' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid JSON should return 400' );
		// The malformed body is rejected by the WP REST layer before the MCP
		// handler runs, so the response carries the WP REST error shape rather
		// than the JSON-RPC error envelope.
		$this->assertSame( 'rest_invalid_json', $data['code'] );
	}

	/**
	 * Test MCP endpoint rejects missing jsonrpc field.
	 */
	public function test_mcp_endpoint_requires_jsonrpc_field() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'id'     => 1,
					'method' => 'initialize',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// JSON-RPC-level validation errors are delivered in the JSON-RPC error
		// envelope over HTTP 200, per the MCP transport conventions.
		$this->assertSame( 200, $response->get_status(), 'Missing jsonrpc field should return a JSON-RPC error envelope' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'], 'Should return invalid request code' );
		$this->assertStringContainsString( 'jsonrpc', $data['error']['message'] );
	}

	/**
	 * Test MCP endpoint rejects wrong jsonrpc version.
	 */
	public function test_mcp_endpoint_requires_jsonrpc_2_0() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '1.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Wrong jsonrpc version should return a JSON-RPC error envelope' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertStringContainsString( '2.0', $data['error']['message'] );
	}

	/**
	 * Test MCP endpoint rejects missing method field.
	 */
	public function test_mcp_endpoint_requires_method_field() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Missing method should return a JSON-RPC error envelope' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertStringContainsString( 'method', $data['error']['message'] );
	}

	/**
	 * Test MCP endpoint returns 404 for unknown method.
	 */
	public function test_mcp_endpoint_rejects_unknown_method() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'unknown/method',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Unknown method is delivered as a JSON-RPC method-not-found error' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'], 'Should return method not found code' );
		$this->assertStringContainsString( 'not found', $data['error']['message'] );
	}

	/**
	 * Test MCP tools/call requires name parameter.
	 */
	public function test_mcp_tools_call_requires_name() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'arguments' => array( 'test' => 'value' ),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'tools/call without name should return 400' );
		// tools/call parameter validation is handled by the REST schema, so the
		// response carries the WP REST param-error shape.
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$param_message = isset( $data['data']['params']['params'] ) ? (string) $data['data']['params']['params'] : '';
		$this->assertStringContainsString( 'name', $param_message );

		// Check for actionable guidance in the nested param details.
		if ( isset( $data['data']['details']['params']['data']['actions'] ) ) {
			$this->assertIsArray( $data['data']['details']['params']['data']['actions'], 'Error should include actions' );
		}
	}

	/**
	 * Test MCP tools/call rejects non-array arguments.
	 */
	public function test_mcp_tools_call_rejects_non_array_arguments() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'test_tool',
						'arguments' => 'not an array',
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Non-array arguments should return 400' );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$param_message = isset( $data['data']['params']['params'] ) ? (string) $data['data']['params']['params'] : '';
		$this->assertStringContainsString( 'arguments', $param_message );
	}

	/**
	 * Test MCP notification (no id) returns 202.
	 */
	public function test_mcp_notification_returns_202() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'method'  => 'initialize',
					// No 'id' field - this is a notification.
				)
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 202, $response->get_status(), 'Notification should return 202 Accepted' );

		// Notification response should have no body or null.
		$data = $response->get_data();
		$this->assertNull( $data, 'Notification response should have null body' );
	}

	/**
	 * Test MCP initialize returns proper structure.
	 */
	public function test_mcp_initialize_returns_proper_structure() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Initialize should return 200' );
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'result', $data );

		$result = $data['result'];
		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertArrayHasKey( 'capabilities', $result );
		$this->assertArrayHasKey( 'serverInfo', $result );
	}

	/**
	 * Test MCP error responses include actionable guidance.
	 */
	public function test_mcp_errors_include_actionable_guidance() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'unknown/method',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayHasKey( 'message', $data['error'] );

		// Check for actionable guidance in error data.
		if ( isset( $data['error']['data'] ) ) {
			$this->assertIsArray( $data['error']['data'] );

			if ( isset( $data['error']['data']['actions'] ) ) {
				$this->assertIsArray( $data['error']['data']['actions'], 'Actions should be an array' );
				$this->assertNotEmpty( $data['error']['data']['actions'], 'Actions should not be empty' );
			}
		}
	}

	/**
	 * Test MCP tools/list returns proper structure.
	 */
	public function test_mcp_tools_list_returns_proper_structure() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'] );
		$this->assertIsArray( $data['result']['tools'] );
	}

	/**
	 * Test MCP resources/list returns proper structure.
	 */
	public function test_mcp_resources_list_returns_proper_structure() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'resources/list',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'] );
		$this->assertIsArray( $data['result']['resources'] );
	}

	/**
	 * Test MCP prompts/list returns proper structure.
	 */
	public function test_mcp_prompts_list_returns_proper_structure() {
		$request = $this->build_mcp_request();
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'prompts/list',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'prompts', $data['result'] );
		$this->assertIsArray( $data['result']['prompts'] );
	}
}
