<?php
/**
 * Tests for chat transcript reconstruction from database records.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Transcript_Reconstruction_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		rest_get_server();
		do_action( 'init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that extract_request_messages properly handles messages with various content structures.
	 */
	public function test_extract_request_messages_handles_various_content_types() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		// Test with basic user message.
		$row = array(
			'request_payload' => wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => 'Hello, how are you?',
						),
					),
				)
			),
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertCount( 1, $messages );
		$this->assertSame( 'user', $messages[0]['role'] );
		$this->assertSame( 'Hello, how are you?', $messages[0]['content'] );
	}

	/**
	 * Test that extract_response_messages properly extracts assistant messages.
	 */
	public function test_extract_response_messages_extracts_assistant_messages() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_response_messages' );
		$extract_method->setAccessible( true );

		// Test with assistant message.
		$row = array(
			'response_payload' => wp_json_encode(
				array(
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'I am doing well, thank you!',
							),
						),
					),
				)
			),
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertCount( 1, $messages );
		$this->assertSame( 'assistant', $messages[0]['role'] );
		$this->assertSame( 'I am doing well, thank you!', $messages[0]['content'] );
	}

	/**
	 * Test that extract_response_messages properly handles assistant messages with tool_calls.
	 */
	public function test_extract_response_messages_handles_tool_calls() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_response_messages' );
		$extract_method->setAccessible( true );

		// Test with assistant message that has tool_calls but empty content.
		$row = array(
			'response_payload' => wp_json_encode(
				array(
					'choices' => array(
						array(
							'message' => array(
								'role'       => 'assistant',
								'content'    => null,
								'tool_calls' => array(
									array(
										'id'       => 'call_abc123',
										'type'     => 'function',
										'function' => array(
											'name'      => 'get_weather',
											'arguments' => '{"location":"London"}',
										),
									),
								),
							),
						),
					),
				)
			),
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		// Should include only the assistant message with tool_calls preserved.
		$this->assertCount( 1, $messages, 'Should extract only the assistant message, not create fake tool messages' );
		$this->assertSame( 'assistant', $messages[0]['role'] );
		// Tool calls should be preserved in the assistant message.
		$this->assertArrayHasKey( 'tool_calls', $messages[0], 'Assistant message should preserve tool_calls array' );
		$this->assertIsArray( $messages[0]['tool_calls'] );
		$this->assertCount( 1, $messages[0]['tool_calls'] );
		$this->assertSame( 'call_abc123', $messages[0]['tool_calls'][0]['id'] );
		$this->assertSame( 'get_weather', $messages[0]['tool_calls'][0]['function']['name'] );
	}

	/**
	 * Test that extract_response_messages doesn't skip assistant messages with empty content if they have tool_calls.
	 */
	public function test_extract_response_messages_preserves_assistant_messages_with_tool_calls() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_response_messages' );
		$extract_method->setAccessible( true );

		// Test with assistant message that has tool_calls and empty string content.
		$row = array(
			'response_payload' => wp_json_encode(
				array(
					'choices' => array(
						array(
							'message' => array(
								'role'       => 'assistant',
								'content'    => '',
								'tool_calls' => array(
									array(
										'id'       => 'call_xyz789',
										'type'     => 'function',
										'function' => array(
											'name'      => 'search_documents',
											'arguments' => '{"query":"annual report"}',
										),
									),
								),
							),
						),
					),
				)
			),
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		// The assistant message should be included with tool_calls preserved.
		$this->assertCount( 1, $messages, 'Should extract only the assistant message' );

		// Verify assistant message has tool_calls.
		$this->assertSame( 'assistant', $messages[0]['role'] );
		$this->assertArrayHasKey( 'tool_calls', $messages[0], 'Assistant message should preserve tool_calls array' );
		$this->assertIsArray( $messages[0]['tool_calls'] );
		$this->assertCount( 1, $messages[0]['tool_calls'] );
		$this->assertSame( 'call_xyz789', $messages[0]['tool_calls'][0]['id'] );
	}

	/**
	 * Test that extract_request_messages properly handles empty payload.
	 */
	public function test_extract_request_messages_handles_empty_payload() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		// Test with empty request_payload.
		$row = array(
			'request_payload' => '',
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertIsArray( $messages );
		$this->assertCount( 0, $messages );
	}

	/**
	 * Test that extract_response_messages properly handles empty payload.
	 */
	public function test_extract_response_messages_handles_empty_payload() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_response_messages' );
		$extract_method->setAccessible( true );

		// Test with empty response_payload.
		$row = array(
			'response_payload' => '',
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertIsArray( $messages );
		$this->assertCount( 0, $messages );
	}

	/**
	 * Test that extract_response_messages handles malformed JSON gracefully.
	 */
	public function test_extract_response_messages_handles_malformed_json() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_response_messages' );
		$extract_method->setAccessible( true );

		// Test with malformed JSON.
		$row = array(
			'response_payload' => '{invalid json',
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertIsArray( $messages );
		$this->assertCount( 0, $messages );
	}

	/**
	 * Test that extract_request_messages preserves tool_call_id for tool messages.
	 */
	public function test_extract_request_messages_preserves_tool_call_id() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		// Test with tool message that has tool_call_id.
		$row = array(
			'request_payload' => wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'         => 'tool',
							'content'      => '{"temperature": 20, "conditions": "partly cloudy"}',
							'tool_call_id' => 'call_abc123',
							'name'         => 'get_weather',
						),
					),
				)
			),
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertCount( 1, $messages );
		$this->assertSame( 'tool', $messages[0]['role'] );
		$this->assertArrayHasKey( 'tool_call_id', $messages[0], 'Tool message should preserve tool_call_id' );
		$this->assertSame( 'call_abc123', $messages[0]['tool_call_id'] );
		$this->assertArrayHasKey( 'name', $messages[0], 'Tool message should preserve name' );
		$this->assertSame( 'get_weather', $messages[0]['name'] );
	}

	/**
	 * Test that extract_request_messages preserves tool_calls in assistant messages.
	 */
	public function test_extract_request_messages_preserves_assistant_tool_calls() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		// Test with assistant message that has tool_calls.
		$row = array(
			'request_payload' => wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'       => 'assistant',
							'content'    => '',
							'tool_calls' => array(
								array(
									'id'       => 'call_def456',
									'type'     => 'function',
									'function' => array(
										'name'      => 'calculate_sum',
										'arguments' => '{"numbers":[1,2,3]}',
									),
								),
							),
						),
					),
				)
			),
		);

		$messages = $extract_method->invokeArgs( $rest_controller, array( $row ) );

		$this->assertCount( 1, $messages );
		$this->assertSame( 'assistant', $messages[0]['role'] );
		$this->assertArrayHasKey( 'tool_calls', $messages[0], 'Assistant message should preserve tool_calls array' );
		$this->assertIsArray( $messages[0]['tool_calls'] );
		$this->assertCount( 1, $messages[0]['tool_calls'] );
		$this->assertSame( 'call_def456', $messages[0]['tool_calls'][0]['id'] );
	}

	/**
	 * Test that messages_match correctly identifies matching messages.
	 */
	public function test_messages_match_identifies_matching_messages() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$match_method = new ReflectionMethod( $rest_controller, 'messages_match' );
		$match_method->setAccessible( true );

		$message1 = array(
			'role'    => 'user',
			'content' => 'Hello there',
		);

		$message2 = array(
			'role'    => 'user',
			'content' => 'Hello there',
		);

		$message3 = array(
			'role'    => 'user',
			'content' => 'Hello world',
		);

		$this->assertTrue( $match_method->invokeArgs( $rest_controller, array( $message1, $message2 ) ) );
		$this->assertFalse( $match_method->invokeArgs( $rest_controller, array( $message1, $message3 ) ) );
	}

	/**
	 * Test append_new_messages deduplication logic.
	 */
	public function test_append_new_messages_deduplication() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$append_method = new ReflectionMethod( $rest_controller, 'append_new_messages' );
		$append_method->setAccessible( true );

		$conversation = array(
			array(
				'role'    => 'user',
				'content' => 'First message',
			),
		);

		$new_messages = array(
			array(
				'role'    => 'user',
				'content' => 'First message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Second message',
			),
		);

		$append_method->invokeArgs(
			$rest_controller,
			array( &$conversation, $new_messages, '2024-01-01 00:00:00', '2024-01-01 00:00:00' )
		);

		// Should only add the second message since the first already exists.
		$this->assertCount( 2, $conversation );
		$this->assertSame( 'user', $conversation[0]['role'] );
		$this->assertSame( 'assistant', $conversation[1]['role'] );
	}
}
