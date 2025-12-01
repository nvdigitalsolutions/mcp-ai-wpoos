<?php
/**
 * Tests for MCP tools/list endpoint functionality.
 *
 * Validates that the tools/list method properly returns tool definitions
 * in a format compatible with MCP clients like OpenAI Agent Builder.
 *
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_MCP_Tools_List_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
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
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

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

		// Create a test assistant with some tools enabled.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test MCP Assistant',
			)
		);

		// Configure assistant with a few test tools.
		$config = array(
			'provider'    => 'openai',
			'model'       => 'gpt-4',
			'temperature' => 0.7,
			'tools'       => array(
				'search_content',
				'list_users',
				'get_current_user',
			),
		);
		update_post_meta( $this->assistant_id, '_mcp_ai_configuration', $config );

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Bootstrap REST controller.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->rest_controller = new WP_MCP_AI_REST( $this->registry, $mock_client );

		// Register routes.
		rest_get_server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that initialize method returns proper capabilities.
	 */
	public function test_initialize_returns_capabilities() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
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

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertSame( '2.0', $data['jsonrpc'] );

		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'capabilities', $data['result'] );
		$this->assertArrayHasKey( 'tools', $data['result']['capabilities'] );
		$this->assertArrayHasKey( 'listChanged', $data['result']['capabilities']['tools'] );
		$this->assertTrue( $data['result']['capabilities']['tools']['listChanged'] );
	}

	/**
	 * Test that initialize method includes tool details for agent builder compatibility.
	 */
	public function test_initialize_includes_tools_for_agent_builder() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2024-11-05',
						'clientInfo'      => array(
							'name'    => 'OpenAI Agent Builder',
							'version' => '1.0',
						),
						'assistant_id'    => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		// Verify tools are included in the initialize response.
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'], 'Initialize response should include tools for agent builder compatibility' );
		$this->assertIsArray( $data['result']['tools'] );
		$this->assertNotEmpty( $data['result']['tools'], 'Tools array should not be empty' );

		// Verify tool structure.
		$first_tool = $data['result']['tools'][0];
		$this->assertArrayHasKey( 'name', $first_tool );
		$this->assertArrayHasKey( 'description', $first_tool );
		$this->assertArrayHasKey( 'inputSchema', $first_tool );

		// Verify only configured tools are returned.
		$tool_names = array_map(
			function ( $tool ) {
				return $tool['name'];
			},
			$data['result']['tools']
		);

		$this->assertContains( 'search_content', $tool_names );
		$this->assertContains( 'list_users', $tool_names );
		$this->assertContains( 'get_current_user', $tool_names );
	}

	/**
	 * Test that tools/list method returns tool definitions.
	 */
	public function test_tools_list_returns_tool_definitions() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
					'params'  => array(
						'assistant_id' => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'tools/list should return 200' );

		$data = $response->get_data();

		// Validate JSON-RPC response structure.
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertSame( '2.0', $data['jsonrpc'] );

		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 2, $data['id'] );

		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'] );

		// Verify tools array is not empty.
		$this->assertIsArray( $data['result']['tools'] );
		$this->assertNotEmpty( $data['result']['tools'], 'tools/list should return at least one tool' );

		// Validate tool structure.
		$first_tool = $data['result']['tools'][0];
		$this->assertArrayHasKey( 'name', $first_tool, 'Each tool should have a name' );
		$this->assertArrayHasKey( 'description', $first_tool, 'Each tool should have a description' );
		$this->assertArrayHasKey( 'inputSchema', $first_tool, 'Each tool should have an inputSchema' );

		// Verify inputSchema is a valid JSON schema.
		$this->assertIsArray( $first_tool['inputSchema'] );
		$this->assertArrayHasKey( 'type', $first_tool['inputSchema'] );
		$this->assertArrayHasKey( 'properties', $first_tool['inputSchema'] );
	}

	/**
	 * Test that tools/list without assistant_id returns all tools.
	 */
	public function test_tools_list_without_assistant_returns_all_tools() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 3,
					'method'  => 'tools/list',
					'params'  => array(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'] );

		// Should return more tools than the assistant-specific list.
		$this->assertIsArray( $data['result']['tools'] );
		$this->assertNotEmpty( $data['result']['tools'] );
	}

	/**
	 * Test that tool names match allowed tools for assistant.
	 */
	public function test_tools_list_returns_only_allowed_tools_for_assistant() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 4,
					'method'  => 'tools/list',
					'params'  => array(
						'assistant_id' => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$tool_names = array_map(
			function ( $tool ) {
				return $tool['name'];
			},
			$data['result']['tools']
		);

		// Verify that only allowed tools are returned.
		foreach ( $tool_names as $name ) {
			$this->assertContains(
				$name,
				array( 'search_content', 'list_users', 'get_current_user' ),
				"Tool '$name' should be in the allowed tools list"
			);
		}
	}

	/**
	 * Test CORS headers are present in MCP responses.
	 */
	public function test_mcp_responses_include_cors_headers() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 5,
					'method'  => 'initialize',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $headers );
	}

	/**
	 * Test error handling for invalid method.
	 */
	public function test_invalid_method_returns_error() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 6,
					'method'  => 'invalid/method',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayHasKey( 'code', $data['error'] );
		$this->assertSame( -32601, $data['error']['code'] );
	}
}
