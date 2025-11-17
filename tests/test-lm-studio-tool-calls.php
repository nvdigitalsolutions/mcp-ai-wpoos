<?php
/**
 * Tests for LM Studio tool_calls support.
 *
 * Validates that the LM Studio client properly handles OpenAI-compatible
 * tool calling, including:
 * - Including tool_calls in assistant messages
 * - Normalizing content to string or null when tool_calls are present
 * - Adding tools parameter to the payload
 *
 * @package WP_MCP_AI
 */

/**
 * Test LM Studio tool_calls support.
 */
class WP_MCP_AI_Test_LM_Studio_Tool_Calls extends WP_UnitTestCase {

	/**
	 * LM Studio client instance.
	 *
	 * @var WP_MCP_AI_LM_Studio_Client
	 */
	protected $client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client = new WP_MCP_AI_LM_Studio_Client();

		// Configure LM Studio endpoint and model for tests.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that assistant messages with tool_calls include the tool_calls in the payload.
	 */
	public function test_assistant_messages_with_tool_calls_are_preserved() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
			array(
				'role'       => 'assistant',
				'content'    => array(
					array(
						'type' => 'text',
						'text' => '',
					),
				),
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"New York"}',
						),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array(), 'test-model' );

		$this->assertIsArray( $payload, 'Payload should be an array' );
		$this->assertArrayHasKey( 'messages', $payload, 'Payload should have messages' );
		$this->assertCount( 2, $payload['messages'], 'Payload should have 2 messages' );

		// Check the assistant message.
		$assistant_message = $payload['messages'][1];
		$this->assertEquals( 'assistant', $assistant_message['role'], 'Second message should be from assistant' );
		$this->assertArrayHasKey( 'tool_calls', $assistant_message, 'Assistant message should have tool_calls' );
		$this->assertCount( 1, $assistant_message['tool_calls'], 'Assistant message should have 1 tool call' );
		$this->assertEquals( 'call_123', $assistant_message['tool_calls'][0]['id'], 'Tool call ID should be preserved' );
	}

	/**
	 * Test that empty content is normalized to null when tool_calls are present.
	 */
	public function test_empty_content_normalized_to_null_with_tool_calls() {
		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_456',
						'type'     => 'function',
						'function' => array(
							'name'      => 'search_posts',
							'arguments' => '{}',
						),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array(), 'test-model' );

		$this->assertIsArray( $payload, 'Payload should be an array' );
		$this->assertArrayHasKey( 'messages', $payload, 'Payload should have messages' );
		$this->assertCount( 1, $payload['messages'], 'Payload should have 1 message' );

		// Check that content is null (OpenAI compatibility).
		$assistant_message = $payload['messages'][0];
		$this->assertNull( $assistant_message['content'], 'Content should be null when empty with tool_calls' );
		$this->assertArrayHasKey( 'tool_calls', $assistant_message, 'Assistant message should have tool_calls' );
	}

	/**
	 * Test that array content is converted to string for assistant messages with tool_calls.
	 */
	public function test_array_content_converted_to_string_with_tool_calls() {
		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => array(
					array(
						'type' => 'text',
						'text' => 'I will search for posts.',
					),
				),
				'tool_calls' => array(
					array(
						'id'       => 'call_789',
						'type'     => 'function',
						'function' => array(
							'name'      => 'search_posts',
							'arguments' => '{"query":"test"}',
						),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array(), 'test-model' );

		$this->assertIsArray( $payload, 'Payload should be an array' );
		$this->assertArrayHasKey( 'messages', $payload, 'Payload should have messages' );
		$this->assertCount( 1, $payload['messages'], 'Payload should have 1 message' );

		// Check that content is a string (not array).
		$assistant_message = $payload['messages'][0];
		$this->assertIsString( $assistant_message['content'], 'Content should be a string when array is provided' );
		$this->assertEquals( 'I will search for posts.', $assistant_message['content'], 'Content should be extracted from array segments' );
		$this->assertArrayHasKey( 'tool_calls', $assistant_message, 'Assistant message should have tool_calls' );
	}

	/**
	 * Test that tools parameter is included in payload when provided.
	 */
	public function test_tools_parameter_included_in_payload() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Search for posts',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'search_posts',
					'description' => 'Search WordPress posts',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => 'Search query',
							),
						),
						'required'   => array( 'query' ),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array( 'tools' => $tools ), 'test-model' );

		$this->assertIsArray( $payload, 'Payload should be an array' );
		$this->assertArrayHasKey( 'tools', $payload, 'Payload should have tools parameter' );
		$this->assertCount( 1, $payload['tools'], 'Payload should have 1 tool' );
		$this->assertEquals( 'search_posts', $payload['tools'][0]['function']['name'], 'Tool name should be preserved' );
	}

	/**
	 * Test that messages without tool_calls work as before.
	 */
	public function test_messages_without_tool_calls_work_normally() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			array(
				'role'    => 'assistant',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hi there!',
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array(), 'test-model' );

		$this->assertIsArray( $payload, 'Payload should be an array' );
		$this->assertArrayHasKey( 'messages', $payload, 'Payload should have messages' );
		$this->assertCount( 2, $payload['messages'], 'Payload should have 2 messages' );

		// Check that messages are formatted correctly.
		$this->assertEquals( 'Hello', $payload['messages'][0]['content'], 'User message content should be preserved' );
		$this->assertEquals( 'Hi there!', $payload['messages'][1]['content'], 'Assistant message content should be converted from array to string' );
		$this->assertArrayNotHasKey( 'tool_calls', $payload['messages'][1], 'Assistant message should not have tool_calls when not provided' );
	}

	/**
	 * Test that assistant messages with tool_calls and non-empty content work correctly.
	 */
	public function test_assistant_messages_with_tool_calls_and_content() {
		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => 'Let me check the weather for you.',
				'tool_calls' => array(
					array(
						'id'       => 'call_999',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"London"}',
						),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array(), 'test-model' );

		$this->assertIsArray( $payload, 'Payload should be an array' );
		$this->assertArrayHasKey( 'messages', $payload, 'Payload should have messages' );
		$this->assertCount( 1, $payload['messages'], 'Payload should have 1 message' );

		// Check that both content and tool_calls are present.
		$assistant_message = $payload['messages'][0];
		$this->assertEquals( 'Let me check the weather for you.', $assistant_message['content'], 'Content should be preserved' );
		$this->assertArrayHasKey( 'tool_calls', $assistant_message, 'Assistant message should have tool_calls' );
		$this->assertCount( 1, $assistant_message['tool_calls'], 'Assistant message should have 1 tool call' );
	}
}
