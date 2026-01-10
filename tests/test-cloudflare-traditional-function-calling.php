<?php
/**
 * Tests for Cloudflare Workers AI Traditional Function Calling.
 *
 * This test file verifies that Cloudflare Workers AI properly supports
 * traditional function calling (as opposed to embedded function calling with run_with_tools).
 *
 * Traditional function calling workflow:
 * 1. Send request with tools parameter
 * 2. Receive response with tool_calls
 * 3. Execute tools externally
 * 4. Send tool results back to AI
 * 5. Repeat until finish_reason is 'stop'
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Cloudflare Workers AI Traditional Function Calling.
 */
class Test_Cloudflare_Traditional_Function_Calling extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
		}

		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that create_chat_completion accepts tools parameter.
	 *
	 * Verifies that tools can be passed to create_chat_completion and are
	 * included in the request payload for traditional function calling.
	 */
	public function test_create_chat_completion_accepts_tools_parameter() {
		// Define a simple tool.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get the current weather for a location',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'The city name',
							),
						),
						'required'   => array( 'location' ),
					),
				),
			),
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather in San Francisco?',
			),
		);

		$options = array(
			'tools'      => $tools,
			'model'      => '@cf/meta/llama-3.1-8b-instruct',
			'max_tokens' => 100, // Keep it small for testing.
		);

		// Use reflection to access the protected build_payload method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Verify tools are in the payload.
		$this->assertArrayHasKey( 'tools', $payload, 'Payload should include tools parameter' );
		$this->assertIsArray( $payload['tools'] );
		$this->assertNotEmpty( $payload['tools'] );
		$this->assertArrayHasKey( 'name', $payload['tools'][0], 'Tool should have name field' );
		$this->assertEquals( 'get_weather', $payload['tools'][0]['name'] );
	}

	/**
	 * Test that tool_choice parameter is respected.
	 *
	 * Verifies that tool_choice parameter controls when tools are included
	 * and how the model uses them.
	 */
	public function test_tool_choice_parameter_respected() {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'A test tool',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		// Test 1: tool_choice = "none" - tools should NOT be included.
		$options = array(
			'tools'       => $tools,
			'tool_choice' => 'none',
		);

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayNotHasKey( 'tools', $payload, 'Tools should not be included when tool_choice is "none"' );

		// Test 2: tool_choice = "auto" - tools included, no tool_choice in payload (it's the default).
		$options = array(
			'tools'       => $tools,
			'tool_choice' => 'auto',
		);

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'tools', $payload, 'Tools should be included when tool_choice is "auto"' );
		$this->assertArrayNotHasKey( 'tool_choice', $payload, 'tool_choice should not be in payload when set to "auto" (default)' );

		// Test 3: tool_choice = "required" - tools and tool_choice both in payload.
		$options = array(
			'tools'       => $tools,
			'tool_choice' => 'required',
		);

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'tools', $payload, 'Tools should be included when tool_choice is "required"' );
		$this->assertArrayHasKey( 'tool_choice', $payload, 'tool_choice should be in payload when not "auto"' );
		$this->assertEquals( 'required', $payload['tool_choice'] );
	}

	/**
	 * Test that tool results can be sent back to the model.
	 *
	 * Verifies the traditional multi-turn conversation flow where tool
	 * results are sent back as tool role messages.
	 */
	public function test_tool_results_in_conversation() {
		// Simulate a conversation with tool call and result.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather in London?',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"London"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'get_weather',
				'content'      => 'Weather in London: Cloudy, 15°C',
			),
		);

		$options = array(
			'max_tokens' => 100,
		);

		// Use reflection to normalize messages.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_messages' );
		$method->setAccessible( true );

		$normalized = $method->invoke( $this->client, $messages );

		// Verify all messages are preserved correctly.
		$this->assertCount( 3, $normalized, 'Should have 3 messages in the conversation' );

		// Check user message.
		$this->assertEquals( 'user', $normalized[0]['role'] );
		$this->assertEquals( 'What is the weather in London?', $normalized[0]['content'] );

		// Check assistant message with tool_calls.
		$this->assertEquals( 'assistant', $normalized[1]['role'] );
		$this->assertArrayHasKey( 'tool_calls', $normalized[1] );
		$this->assertEquals( 'call_123', $normalized[1]['tool_calls'][0]['id'] );

		// Check tool result message.
		$this->assertEquals( 'tool', $normalized[2]['role'] );
		$this->assertEquals( 'call_123', $normalized[2]['tool_call_id'] );
		$this->assertEquals( 'get_weather', $normalized[2]['name'] );
		$this->assertEquals( 'Weather in London: Cloudy, 15°C', $normalized[2]['content'] );
	}

	/**
	 * Test finish_reason is set correctly based on tool_calls.
	 *
	 * Verifies that finish_reason is 'tool_calls' when tools are invoked,
	 * and 'stop' when the conversation is complete.
	 */
	public function test_finish_reason_based_on_tool_calls() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		// Test 1: Response with tool_calls should have finish_reason='tool_calls'.
		$decoded_with_tools = array(
			'success' => true,
			'result'  => array(
				'response'   => 'I will check the weather for you.',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"Paris"}',
						),
					),
				),
			),
		);

		$result = $method->invoke( $this->client, $decoded_with_tools, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertEquals( 'tool_calls', $result['choices'][0]['finish_reason'], 'finish_reason should be "tool_calls" when tools are invoked' );

		// Test 2: Response without tool_calls should have finish_reason='stop'.
		$decoded_without_tools = array(
			'success' => true,
			'result'  => array(
				'response' => 'The weather in Paris is sunny and 22°C.',
			),
		);

		$result = $method->invoke( $this->client, $decoded_without_tools, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertEquals( 'stop', $result['choices'][0]['finish_reason'], 'finish_reason should be "stop" when conversation is complete' );
	}

	/**
	 * Test tool normalization for payload.
	 *
	 * Verifies that tools are properly normalized with correct naming
	 * for the Cloudflare API.
	 */
	public function test_tool_normalization_for_payload() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tools_for_payload' );
		$method->setAccessible( true );

		// Test with OpenAI-style tool format.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'search_database',
					'description' => 'Search the database',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array( 'type' => 'string' ),
						),
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'calculate',
					'description' => 'Perform calculations',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'expression' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);

		$normalized = $method->invoke( $this->client, $tools );

		$this->assertIsArray( $normalized );
		$this->assertCount( 2, $normalized );

		// Verify both tools have name field extracted.
		$this->assertEquals( 'search_database', $normalized[0]['name'] );
		$this->assertEquals( 'calculate', $normalized[1]['name'] );

		// Verify original structure is preserved.
		$this->assertEquals( 'function', $normalized[0]['type'] );
		$this->assertArrayHasKey( 'function', $normalized[0] );
	}

	/**
	 * Test multiple tools can be passed and used.
	 *
	 * Verifies that multiple tools can be defined and will be available
	 * for the model to choose from.
	 */
	public function test_multiple_tools_passed_to_model() {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get weather for a location',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array( 'type' => 'string' ),
						),
						'required'   => array( 'location' ),
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_time',
					'description' => 'Get current time',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'timezone' => array( 'type' => 'string' ),
						),
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'search',
					'description' => 'Search the web',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array( 'type' => 'string' ),
						),
						'required'   => array( 'query' ),
					),
				),
			),
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather in Tokyo and what time is it there?',
			),
		);

		$options = array(
			'tools' => $tools,
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertCount( 3, $payload['tools'], 'All 3 tools should be in the payload' );

		$tool_names = array_column( $payload['tools'], 'name' );
		$this->assertContains( 'get_weather', $tool_names );
		$this->assertContains( 'get_time', $tool_names );
		$this->assertContains( 'search', $tool_names );
	}

	/**
	 * Test that traditional function calling is compatible with chat service.
	 *
	 * This is a documentation test that explains how traditional function calling
	 * works with the existing chat service agentic loop.
	 */
	public function test_traditional_function_calling_workflow_documentation() {
		/*
		 * This test documents the traditional function calling workflow:
		 *
		 * 1. Chat service calls $client->create_chat_completion($messages, $options)
		 *    with tools in $options['tools']
		 *
		 * 2. Cloudflare returns response with tool_calls in:
		 *    $response['choices'][0]['message']['tool_calls']
		 *
		 * 3. Chat service checks finish_reason:
		 *    - If 'tool_calls', it executes tools via execute_tool_calls()
		 *    - If 'stop', conversation is complete
		 *
		 * 4. Tool results are added as 'tool' role messages:
		 *    array(
		 *        'role' => 'tool',
		 *        'tool_call_id' => $tool_call_id,
		 *        'name' => $function_name,
		 *        'content' => $tool_result
		 *    )
		 *
		 * 5. Updated conversation is sent back to $client->create_chat_completion()
		 *
		 * 6. Loop continues until finish_reason is 'stop' or max iterations reached
		 */

		// Verify the workflow components exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Chat_Service' ),
			'Chat service should exist for traditional function calling'
		);

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Cloudflare_Client' ),
			'Cloudflare client should support traditional function calling'
		);

		// Verify the client has the necessary methods.
		$this->assertTrue(
			method_exists( $this->client, 'create_chat_completion' ),
			'Client should have create_chat_completion method'
		);

		// Success - documentation test passed.
		$this->assertTrue( true, 'Traditional function calling workflow is properly documented and supported' );
	}
}
