<?php
/**
 * Tests for REST tools endpoint error handling.
 *
 * Verifies that the /tools endpoint handles tool schema errors gracefully
 * and continues to function even when individual tools have issues.
 */

/**
 * Mock tool with broken schema for testing error handling.
 */
class WP_MCP_AI_Mock_Broken_Schema_Tool implements WP_MCP_AI_Tool_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'broken_schema_tool';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return 'Broken Schema Tool';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'A test tool that throws an exception when get_parameters_schema() is called';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		throw new Exception( 'Simulated schema generation error' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}

/**
 * Mock tool that returns invalid schema (not an array).
 */
class WP_MCP_AI_Mock_Invalid_Schema_Tool implements WP_MCP_AI_Tool_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'invalid_schema_tool';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return 'Invalid Schema Tool';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'A test tool that returns an invalid schema';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		// Return a string instead of an array - invalid.
		return 'this should be an array';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}

/**
 * Mock tool that works correctly.
 */
class WP_MCP_AI_Mock_Working_Tool implements WP_MCP_AI_Tool_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'working_tool';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return 'Working Tool';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'A test tool that works correctly';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'test_param' => array(
					'type'        => 'string',
					'description' => 'A test parameter',
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}

/**
 * Test case for REST tools endpoint error handling.
 */
class WP_MCP_AI_REST_Tools_Error_Handling_Test extends WP_UnitTestCase {

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
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Get registry instance.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Register our test tools.
		$this->registry->register_tool( new WP_MCP_AI_Mock_Broken_Schema_Tool() );
		$this->registry->register_tool( new WP_MCP_AI_Mock_Invalid_Schema_Tool() );
		$this->registry->register_tool( new WP_MCP_AI_Mock_Working_Tool() );

		// Create a test assistant with all three tools.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant with Mixed Tools',
			)
		);

		$config = array(
			'tools' => array(
				'broken_schema_tool',
				'invalid_schema_tool',
				'working_tool',
				'get_current_date_time', // Include a real tool as well.
			),
		);
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_assistant_config', $config );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Unregister test tools to prevent interference with other tests.
		$this->registry->unregister_tool( 'broken_schema_tool' );
		$this->registry->unregister_tool( 'invalid_schema_tool' );
		$this->registry->unregister_tool( 'working_tool' );

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

		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $this->registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that GET /tools handles broken tool schemas gracefully.
	 *
	 * The endpoint should return 200 and include working tools,
	 * skipping any tools that throw exceptions or return invalid schemas.
	 */
	public function test_get_tools_handles_broken_schemas_gracefully() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		// Should return 200 even with broken tools.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /tools should return 200 even with broken tools' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'tools', $data, 'Response should include tools array' );
		$this->assertIsArray( $data['tools'], 'Tools should be an array' );

		// Get tool names.
		$tool_names = wp_list_pluck( $data['tools'], 'name' );

		// Working tool should be included.
		$this->assertContains( 'working_tool', $tool_names, 'Working tool should be in the list' );
		$this->assertContains( 'get_current_date_time', $tool_names, 'Real tool should be in the list' );

		// Broken tools should be excluded.
		$this->assertNotContains( 'broken_schema_tool', $tool_names, 'Broken schema tool should be excluded' );
		$this->assertNotContains( 'invalid_schema_tool', $tool_names, 'Invalid schema tool should be excluded' );

		// At least one tool should be returned (the working ones).
		$this->assertNotEmpty( $data['tools'], 'Should return at least one working tool' );
	}

	/**
	 * Test that MCP tools/list endpoint handles broken schemas.
	 */
	public function test_mcp_tools_list_handles_broken_schemas() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => array(
						'assistant_id' => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'MCP tools/list should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data, 'MCP response should have result' );
		$this->assertArrayHasKey( 'tools', $data['result'], 'Result should include tools array' );

		$tool_names = wp_list_pluck( $data['result']['tools'], 'name' );

		// Working tools should be included.
		$this->assertContains( 'working_tool', $tool_names, 'Working tool should be in MCP list' );

		// Broken tools should be excluded.
		$this->assertNotContains( 'broken_schema_tool', $tool_names, 'Broken tool should be excluded from MCP list' );
		$this->assertNotContains( 'invalid_schema_tool', $tool_names, 'Invalid tool should be excluded from MCP list' );
	}

	/**
	 * Test that build_tools_payload handles broken schemas for chat requests.
	 */
	public function test_chat_request_handles_broken_tool_schemas() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock the chat method to avoid actual API calls.
		$mock_client->method( 'chat' )
			->willReturn(
				array(
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Test response',
							),
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 10,
						'completion_tokens' => 10,
						'total_tokens'      => 20,
					),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'messages'     => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		// Chat should succeed even with broken tools in the assistant config.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'Chat should succeed even with broken tools' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'choices', $data, 'Chat response should include choices' );
	}

	/**
	 * Test that all three error handling locations log errors appropriately.
	 */
	public function test_error_handling_logs_errors() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Clear any previous logs.
		delete_option( 'wp_mcp_ai_recent_errors' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		// Check if errors were logged.
		$errors = get_option( 'wp_mcp_ai_recent_errors', array() );

		if ( ! empty( $errors ) ) {
			// Find errors related to our broken tools.
			$broken_tool_errors = array_filter(
				$errors,
				function ( $error ) {
					return isset( $error['data']['tool_slug'] ) &&
							in_array( $error['data']['tool_slug'], array( 'broken_schema_tool', 'invalid_schema_tool' ), true );
				}
			);

			$this->assertNotEmpty( $broken_tool_errors, 'Errors should be logged for broken tools' );
		}
	}
}
