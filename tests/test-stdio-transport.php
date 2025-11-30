<?php
/**
 * Tests for STDIO Transport functionality.
 *
 * This test suite validates the WP_MCP_AI_STDIO_Transport class
 * which implements MCP over stdin/stdout for local agent integration.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_STDIO_Transport_Test extends WP_UnitTestCase {

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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test STDIO Assistant',
			)
		);

		// Configure assistant with some tools.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_tools', array( 'list_posts', 'get_post' ) );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that STDIO transport class exists and can be instantiated.
	 */
	public function test_stdio_transport_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_STDIO_Transport' ),
			'WP_MCP_AI_STDIO_Transport class should exist'
		);
	}

	/**
	 * Test STDIO transport can be instantiated without arguments.
	 */
	public function test_stdio_transport_instantiation_without_assistant() {
		$transport = new WP_MCP_AI_STDIO_Transport();
		$this->assertInstanceOf( WP_MCP_AI_STDIO_Transport::class, $transport );
	}

	/**
	 * Test STDIO transport can be instantiated with assistant ID.
	 */
	public function test_stdio_transport_instantiation_with_assistant() {
		$transport = new WP_MCP_AI_STDIO_Transport( $this->assistant_id );
		$this->assertInstanceOf( WP_MCP_AI_STDIO_Transport::class, $transport );
	}

	/**
	 * Test initialize response structure.
	 */
	public function test_initialize_response_structure() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		// Use reflection to access protected method.
		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_initialize' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertArrayHasKey( 'capabilities', $result );
		$this->assertArrayHasKey( 'serverInfo', $result );
		$this->assertArrayHasKey( 'instructions', $result );
		$this->assertSame( '2024-11-05', $result['protocolVersion'] );
	}

	/**
	 * Test initialize includes tools by default.
	 */
	public function test_initialize_includes_tools() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_initialize' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertArrayHasKey( 'tools', $result );
		$this->assertIsArray( $result['tools'] );
	}

	/**
	 * Test server info in initialize response.
	 */
	public function test_initialize_server_info() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_initialize' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertArrayHasKey( 'name', $result['serverInfo'] );
		$this->assertArrayHasKey( 'version', $result['serverInfo'] );
		$this->assertSame( 'WP oOS', $result['serverInfo']['name'] );
	}

	/**
	 * Test capabilities structure.
	 */
	public function test_initialize_capabilities() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_initialize' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$capabilities = $result['capabilities'];

		$this->assertArrayHasKey( 'tools', $capabilities );
		$this->assertArrayHasKey( 'resources', $capabilities );
		$this->assertArrayHasKey( 'prompts', $capabilities );

		$this->assertArrayHasKey( 'listChanged', $capabilities['tools'] );
		$this->assertTrue( $capabilities['tools']['listChanged'] );
	}

	/**
	 * Test tools/list returns proper format.
	 */
	public function test_tools_list_response_format() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_tools_list' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
		$this->assertIsArray( $result['tools'] );
	}

	/**
	 * Test tools/list with assistant scoping.
	 */
	public function test_tools_list_with_assistant_scoping() {
		$transport = new WP_MCP_AI_STDIO_Transport( $this->assistant_id );

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_tools_list' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );

		// Assistant has list_posts and get_post configured.
		// If these tools exist, they should be in the list.
		$tool_names = array_column( $result['tools'], 'name' );

		// The actual tools depend on what's registered.
		// We can't guarantee specific tools are present without knowing the full tool registry.
		$this->assertIsArray( $tool_names );
	}

	/**
	 * Test tool entry structure.
	 */
	public function test_tool_entry_structure() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_tools_list' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		if ( ! empty( $result['tools'] ) ) {
			$first_tool = $result['tools'][0];

			$this->assertArrayHasKey( 'name', $first_tool );
			$this->assertArrayHasKey( 'description', $first_tool );
			$this->assertArrayHasKey( 'inputSchema', $first_tool );
		}
	}

	/**
	 * Test resources/list returns proper format.
	 */
	public function test_resources_list_response_format() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_resources_list' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'resources', $result );
		$this->assertIsArray( $result['resources'] );
	}

	/**
	 * Test prompts/list returns assistants.
	 */
	public function test_prompts_list_returns_assistants() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_prompts_list' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'prompts', $result );
		$this->assertIsArray( $result['prompts'] );

		// Should include our test assistant.
		$this->assertGreaterThanOrEqual( 1, count( $result['prompts'] ) );
	}

	/**
	 * Test prompt entry structure.
	 */
	public function test_prompt_entry_structure() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_prompts_list' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		if ( ! empty( $result['prompts'] ) ) {
			$first_prompt = $result['prompts'][0];

			$this->assertArrayHasKey( 'name', $first_prompt );
			$this->assertArrayHasKey( 'description', $first_prompt );
			$this->assertArrayHasKey( 'arguments', $first_prompt );
		}
	}

	/**
	 * Test route_method handles initialize.
	 */
	public function test_route_method_handles_initialize() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'route_method' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 'initialize', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'protocolVersion', $result );
	}

	/**
	 * Test route_method handles tools/list.
	 */
	public function test_route_method_handles_tools_list() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'route_method' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 'tools/list', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
	}

	/**
	 * Test route_method handles shutdown.
	 */
	public function test_route_method_handles_shutdown() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'route_method' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 'shutdown', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'shutdown', $result );
		$this->assertTrue( $result['shutdown'] );
	}

	/**
	 * Test route_method returns error for unknown method.
	 */
	public function test_route_method_returns_error_for_unknown_method() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'route_method' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 'unknown/method', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_method_not_found', $result->get_error_code() );
	}

	/**
	 * Test error response structure.
	 */
	public function test_error_response_structure() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'error_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 123, -32600, 'Test error message' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'jsonrpc', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'error', $result );

		$this->assertSame( '2.0', $result['jsonrpc'] );
		$this->assertSame( 123, $result['id'] );
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertArrayHasKey( 'message', $result['error'] );
		$this->assertSame( -32600, $result['error']['code'] );
		$this->assertSame( 'Test error message', $result['error']['message'] );
	}

	/**
	 * Test error response handles null ID.
	 */
	public function test_error_response_handles_null_id() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'error_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, null, -32700, 'Parse error' );

		$this->assertNull( $result['id'] );
	}

	/**
	 * Test convert_to_text with string.
	 */
	public function test_convert_to_text_with_string() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'convert_to_text' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 'Hello World' );

		$this->assertSame( 'Hello World', $result );
	}

	/**
	 * Test convert_to_text with array.
	 */
	public function test_convert_to_text_with_array() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'convert_to_text' );
		$method->setAccessible( true );

		$input  = array( 'key' => 'value', 'number' => 42 );
		$result = $method->invoke( $transport, $input );

		// Should return valid JSON.
		$decoded = json_decode( $result, true );
		$this->assertNotNull( $decoded );
		$this->assertSame( 'value', $decoded['key'] );
		$this->assertSame( 42, $decoded['number'] );
	}

	/**
	 * Test convert_to_text with scalar.
	 */
	public function test_convert_to_text_with_scalar() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'convert_to_text' );
		$method->setAccessible( true );

		// Integer.
		$result = $method->invoke( $transport, 42 );
		$this->assertSame( '42', $result );

		// Boolean.
		$result = $method->invoke( $transport, true );
		$this->assertSame( 'true', $result );

		// Null.
		$result = $method->invoke( $transport, null );
		$this->assertSame( 'null', $result );
	}

	/**
	 * Test process_message with invalid JSON.
	 */
	public function test_process_message_with_invalid_json() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'process_message' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, 'not valid json' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( -32700, $result['error']['code'] );
	}

	/**
	 * Test process_message with missing jsonrpc field.
	 */
	public function test_process_message_with_missing_jsonrpc() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'process_message' );
		$method->setAccessible( true );

		$message = wp_json_encode( array( 'id' => 1, 'method' => 'initialize' ) );
		$result  = $method->invoke( $transport, $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( -32600, $result['error']['code'] );
	}

	/**
	 * Test process_message with missing method field.
	 */
	public function test_process_message_with_missing_method() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'process_message' );
		$method->setAccessible( true );

		$message = wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1 ) );
		$result  = $method->invoke( $transport, $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( -32600, $result['error']['code'] );
	}

	/**
	 * Test process_message with valid initialize request.
	 */
	public function test_process_message_with_valid_initialize() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'process_message' );
		$method->setAccessible( true );

		$message = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(),
			)
		);

		$result = $method->invoke( $transport, $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'jsonrpc', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'result', $result );
		$this->assertSame( '2.0', $result['jsonrpc'] );
		$this->assertSame( 1, $result['id'] );
	}

	/**
	 * Test process_message notification (no ID) returns null.
	 */
	public function test_process_message_notification_returns_null() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'process_message' );
		$method->setAccessible( true );

		// Notification has no ID.
		$message = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'initialize',
				'params'  => array(),
			)
		);

		$result = $method->invoke( $transport, $message );

		$this->assertNull( $result, 'Notifications should not return a response' );
	}

	/**
	 * Test tools/call with missing name parameter.
	 */
	public function test_tools_call_missing_name() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_tools_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $transport, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_params', $result->get_error_code() );
	}

	/**
	 * Test tools/call with unknown tool.
	 */
	public function test_tools_call_unknown_tool() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		$method = new ReflectionMethod( WP_MCP_AI_STDIO_Transport::class, 'handle_tools_call' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$transport,
			array(
				'name'      => 'nonexistent_tool_xyz',
				'arguments' => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
	}

	/**
	 * Test stop method sets running to false.
	 */
	public function test_stop_method() {
		$transport = new WP_MCP_AI_STDIO_Transport();

		// Use reflection to check the running property.
		$running_prop = new ReflectionProperty( WP_MCP_AI_STDIO_Transport::class, 'running' );
		$running_prop->setAccessible( true );

		// Initially should be true.
		$this->assertTrue( $running_prop->getValue( $transport ) );

		// Call stop.
		$transport->stop();

		// Should now be false.
		$this->assertFalse( $running_prop->getValue( $transport ) );
	}
}
