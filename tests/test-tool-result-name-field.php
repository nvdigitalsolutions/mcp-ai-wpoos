<?php
/**
 * Tests ensuring tool result messages include the name field for provider compatibility.
 *
 * This test validates the fix for Gemini and other providers that require or recommend
 * the 'name' field in tool result messages according to 2024 API specifications.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Tool_Result_Name_Field_Test extends WP_UnitTestCase {

	/**
	 * Test that tool execution results include the name field.
	 *
	 * This is required by Gemini (returns null without it) and recommended by OpenAI.
	 * Ollama and LM Studio also expect this field.
	 */
	public function test_tool_results_include_name_field() {
		// Create a mock tool registry that returns a simple result.
		$mock_tool_registry = $this->getMockBuilder( WP_MCP_AI_Tool_Registry::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'execute_tool' ) )
			->getMock();

		$mock_tool_registry
			->method( 'execute_tool' )
			->willReturn(
				array(
					'status' => 'success',
					'data'   => 'Tool executed successfully',
				)
			);

		// Create mock dependencies for chat service.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_rate_limiter = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_token_budget = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		// Create chat service instance with mocked dependencies.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget,
			$mock_tool_registry
		);

		// Use reflection to access the private execute_tool_calls method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'execute_tool_calls' );
		$method->setAccessible( true );

		// Prepare test tool calls.
		$tool_calls = array(
			array(
				'id'       => 'call_test_123',
				'type'     => 'function',
				'function' => array(
					'name'      => 'test_tool',
					'arguments' => json_encode( array( 'param' => 'value' ) ),
				),
			),
		);

		$assistant_id     = 1;
		$assistant_config = array();
		$iteration        = 0;
		$max_iterations   = 5;

		// Execute the method.
		$results = $method->invoke( $chat_service, $tool_calls, $assistant_id, $assistant_config, $iteration, $max_iterations );

		// Assertions.
		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );

		$result = $results[0];

		// Verify all required fields are present.
		$this->assertArrayHasKey( 'role', $result );
		$this->assertArrayHasKey( 'tool_call_id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'content', $result );

		// Verify field values.
		$this->assertSame( 'tool', $result['role'] );
		$this->assertSame( 'call_test_123', $result['tool_call_id'] );
		$this->assertSame( 'test_tool', $result['name'] );
		$this->assertIsString( $result['content'] );

		// Verify content is valid JSON.
		$content_decoded = json_decode( $result['content'], true );
		$this->assertNotNull( $content_decoded );
		$this->assertIsArray( $content_decoded );
	}

	/**
	 * Test that tool execution errors also include the name field.
	 */
	public function test_tool_error_results_include_name_field() {
		// Create a mock tool registry that returns an error.
		$mock_tool_registry = $this->getMockBuilder( WP_MCP_AI_Tool_Registry::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'execute_tool' ) )
			->getMock();

		$mock_tool_registry
			->method( 'execute_tool' )
			->willReturn( new WP_Error( 'tool_error', 'Tool execution failed' ) );

		// Create mock dependencies for chat service.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_rate_limiter = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_token_budget = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		// Create chat service instance with mocked dependencies.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget,
			$mock_tool_registry
		);

		// Use reflection to access the private execute_tool_calls method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'execute_tool_calls' );
		$method->setAccessible( true );

		// Prepare test tool calls.
		$tool_calls = array(
			array(
				'id'       => 'call_error_456',
				'type'     => 'function',
				'function' => array(
					'name'      => 'failing_tool',
					'arguments' => json_encode( array() ),
				),
			),
		);

		$assistant_id     = 1;
		$assistant_config = array();
		$iteration        = 0;
		$max_iterations   = 5;

		// Execute the method.
		$results = $method->invoke( $chat_service, $tool_calls, $assistant_id, $assistant_config, $iteration, $max_iterations );

		// Assertions.
		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );

		$result = $results[0];

		// Verify all required fields are present, even for errors.
		$this->assertArrayHasKey( 'role', $result );
		$this->assertArrayHasKey( 'tool_call_id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'content', $result );

		// Verify field values.
		$this->assertSame( 'tool', $result['role'] );
		$this->assertSame( 'call_error_456', $result['tool_call_id'] );
		$this->assertSame( 'failing_tool', $result['name'] );
		$this->assertIsString( $result['content'] );

		// Verify error content.
		$content_decoded = json_decode( $result['content'], true );
		$this->assertNotNull( $content_decoded );
		$this->assertIsArray( $content_decoded );
		$this->assertArrayHasKey( 'error', $content_decoded );
		$this->assertSame( 'Tool execution failed', $content_decoded['error'] );
	}

	/**
	 * Test that invalid JSON arguments also include the name field in error results.
	 */
	public function test_invalid_json_arguments_include_name_field() {
		// Create mock dependencies for chat service.
		$mock_tool_registry = $this->getMockBuilder( WP_MCP_AI_Tool_Registry::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_rate_limiter = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_token_budget = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		// Create chat service instance with mocked dependencies.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget,
			$mock_tool_registry
		);

		// Use reflection to access the private execute_tool_calls method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'execute_tool_calls' );
		$method->setAccessible( true );

		// Prepare test tool calls with invalid JSON.
		$tool_calls = array(
			array(
				'id'       => 'call_invalid_789',
				'type'     => 'function',
				'function' => array(
					'name'      => 'broken_tool',
					'arguments' => '{invalid json}',
				),
			),
		);

		$assistant_id     = 1;
		$assistant_config = array();
		$iteration        = 0;
		$max_iterations   = 5;

		// Execute the method.
		$results = $method->invoke( $chat_service, $tool_calls, $assistant_id, $assistant_config, $iteration, $max_iterations );

		// Assertions.
		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );

		$result = $results[0];

		// Verify all required fields are present, even for invalid JSON.
		$this->assertArrayHasKey( 'role', $result );
		$this->assertArrayHasKey( 'tool_call_id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'content', $result );

		// Verify field values.
		$this->assertSame( 'tool', $result['role'] );
		$this->assertSame( 'call_invalid_789', $result['tool_call_id'] );
		$this->assertSame( 'broken_tool', $result['name'] );

		// Verify error content.
		$content_decoded = json_decode( $result['content'], true );
		$this->assertNotNull( $content_decoded );
		$this->assertIsArray( $content_decoded );
		$this->assertArrayHasKey( 'error', $content_decoded );
		$this->assertSame( 'Invalid tool arguments JSON', $content_decoded['error'] );
	}

	/**
	 * Test that multiple tool calls all include name fields.
	 */
	public function test_multiple_tool_calls_include_name_fields() {
		// Create a mock tool registry that returns different results.
		$mock_tool_registry = $this->getMockBuilder( WP_MCP_AI_Tool_Registry::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'execute_tool' ) )
			->getMock();

		$mock_tool_registry
			->method( 'execute_tool' )
			->will(
				$this->onConsecutiveCalls(
					array( 'result' => 'first' ),
					array( 'result' => 'second' ),
					new WP_Error( 'error', 'third failed' )
				)
			);

		// Create mock dependencies for chat service.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_rate_limiter = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_token_budget = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		// Create chat service instance with mocked dependencies.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget,
			$mock_tool_registry
		);

		// Use reflection to access the private execute_tool_calls method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'execute_tool_calls' );
		$method->setAccessible( true );

		// Prepare multiple test tool calls.
		$tool_calls = array(
			array(
				'id'       => 'call_multi_1',
				'type'     => 'function',
				'function' => array(
					'name'      => 'tool_one',
					'arguments' => json_encode( array() ),
				),
			),
			array(
				'id'       => 'call_multi_2',
				'type'     => 'function',
				'function' => array(
					'name'      => 'tool_two',
					'arguments' => json_encode( array() ),
				),
			),
			array(
				'id'       => 'call_multi_3',
				'type'     => 'function',
				'function' => array(
					'name'      => 'tool_three',
					'arguments' => json_encode( array() ),
				),
			),
		);

		$assistant_id     = 1;
		$assistant_config = array();
		$iteration        = 0;
		$max_iterations   = 5;

		// Execute the method.
		$results = $method->invoke( $chat_service, $tool_calls, $assistant_id, $assistant_config, $iteration, $max_iterations );

		// Assertions.
		$this->assertIsArray( $results );
		$this->assertCount( 3, $results );

		// Verify all results include the name field.
		foreach ( $results as $index => $result ) {
			$this->assertArrayHasKey( 'role', $result, "Result $index missing role" );
			$this->assertArrayHasKey( 'tool_call_id', $result, "Result $index missing tool_call_id" );
			$this->assertArrayHasKey( 'name', $result, "Result $index missing name field" );
			$this->assertArrayHasKey( 'content', $result, "Result $index missing content" );

			$this->assertSame( 'tool', $result['role'] );
		}

		// Verify specific tool names.
		$this->assertSame( 'tool_one', $results[0]['name'] );
		$this->assertSame( 'tool_two', $results[1]['name'] );
		$this->assertSame( 'tool_three', $results[2]['name'] );
	}
}
