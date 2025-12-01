<?php
/**
 * Tests for MCP endpoint parameter validation.
 *
 * Ensures that the /mcp endpoint properly validates JSON-RPC requests
 * and returns clear, actionable error messages when validation fails.
 *
 * @package WP_MCP_AI
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
	 * Test MCP endpoint rejects empty request body.
	 */
	public function test_mcp_endpoint_rejects_empty_body() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Empty body should return 400' );
		$this->assertArrayHasKey( 'error', $data, 'Response should have JSON-RPC error structure' );
		$this->assertSame( -32700, $data['error']['code'], 'Should return parse error code' );
	}

	/**
	 * Test MCP endpoint rejects invalid JSON.
	 */
	public function test_mcp_endpoint_rejects_invalid_json() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{invalid json}' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid JSON should return 400' );
		$this->assertArrayHasKey( 'error', $data, 'Response should have JSON-RPC error structure' );
		$this->assertSame( -32700, $data['error']['code'], 'Should return parse error code' );
	}

	/**
	 * Test MCP endpoint rejects missing jsonrpc field.
	 */
	public function test_mcp_endpoint_requires_jsonrpc_field() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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

		$this->assertSame( 400, $response->get_status(), 'Missing jsonrpc field should return 400' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'], 'Should return invalid request code' );
		$this->assertStringContainsString( 'jsonrpc', $data['error']['message'] );
	}

	/**
	 * Test MCP endpoint rejects wrong jsonrpc version.
	 */
	public function test_mcp_endpoint_requires_jsonrpc_2_0() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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

		$this->assertSame( 400, $response->get_status(), 'Wrong jsonrpc version should return 400' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertStringContainsString( '2.0', $data['error']['message'] );
	}

	/**
	 * Test MCP endpoint rejects missing method field.
	 */
	public function test_mcp_endpoint_requires_method_field() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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

		$this->assertSame( 400, $response->get_status(), 'Missing method should return 400' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertStringContainsString( 'method', $data['error']['message'] );
	}

	/**
	 * Test MCP endpoint returns 404 for unknown method.
	 */
	public function test_mcp_endpoint_rejects_unknown_method() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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

		$this->assertSame( 404, $response->get_status(), 'Unknown method should return 404' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'], 'Should return method not found code' );
		$this->assertStringContainsString( 'not found', $data['error']['message'] );
	}

	/**
	 * Test MCP tools/call requires name parameter.
	 */
	public function test_mcp_tools_call_requires_name() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'name', $data['error']['message'] );

		// Check for actionable guidance.
		if ( isset( $data['error']['data'] ) && isset( $data['error']['data']['actions'] ) ) {
			$this->assertIsArray( $data['error']['data']['actions'], 'Error should include actions' );
		}
	}

	/**
	 * Test MCP tools/call rejects non-array arguments.
	 */
	public function test_mcp_tools_call_rejects_non_array_arguments() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'arguments', $data['error']['message'] );
	}

	/**
	 * Test MCP notification (no id) returns 202.
	 */
	public function test_mcp_notification_returns_202() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
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
