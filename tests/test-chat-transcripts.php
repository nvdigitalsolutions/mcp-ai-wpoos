<?php
/**
 * Tests for persisting chat transcripts to the JetEngine CCT.
 */
class WP_MCP_AI_Chat_Transcripts_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Mock transcript handler that captures stored records.
	 *
	 * @var object
	 */
	protected $transcript_handler;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Transcript Assistant',
			)
		);

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Tool responses captured in the request payload should appear after the
	 * matching tool call when transcripts are reconstructed.
	 */
	public function test_transcript_moves_tool_responses_after_function_calls() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$append_method  = new ReflectionMethod( $rest_controller, 'append_new_messages' );
		$extract_method = new ReflectionMethod( $rest_controller, 'extract_appended_tool_responses' );

		$append_method->setAccessible( true );
		$extract_method->setAccessible( true );

		$conversation = array(
			array(
				'role'    => 'user',
				'content' => 'Initial request',
			),
		);

		$request_messages = array(
			array(
				'role'    => 'user',
				'content' => 'Please review the attachment.',
			),
			array(
				'role'    => 'tool',
				'content' => 'Document summary from submit_document_prompt.',
			),
		);

		$existing_count = count( $conversation );

		$append_method->invokeArgs(
			$rest_controller,
			array( &$conversation, $request_messages, '2024-01-01 00:00:00', '2024-01-01 00:00:00' )
		);

		$tool_responses = $extract_method->invokeArgs( $rest_controller, array( &$conversation, $existing_count ) );

		$this->assertCount( 2, $conversation, 'User request should remain in the conversation.' );
		$this->assertSame( 'user', $conversation[1]['role'] );
		$this->assertCount( 1, $tool_responses, 'Tool response should be extracted for later insertion.' );
		$this->assertSame( 'tool', $tool_responses[0]['role'] );

		$response_messages = array(
			array(
				'role'    => 'tool',
				'content' => 'Tool call: submit_document_prompt',
			),
		);

		$append_method->invokeArgs(
			$rest_controller,
			array( &$conversation, $response_messages, '2024-01-01 00:00:05', '2024-01-01 00:00:05' )
		);

		$append_method->invokeArgs(
			$rest_controller,
			array( &$conversation, $tool_responses, '2024-01-01 00:00:05', '2024-01-01 00:00:05' )
		);

		$this->assertSame( 'tool', $conversation[2]['role'] );
		$this->assertStringStartsWith( 'Tool call:', $conversation[2]['content'] );
		$this->assertSame( 'tool', $conversation[3]['role'] );
		$this->assertSame( 'Document summary from submit_document_prompt.', $conversation[3]['content'] );
	}

	/**
	 * Nested tool response payloads should be flattened into readable text.
	 */
	public function test_transcript_flattens_nested_tool_response_content() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		$row = array(
			'request_payload' => wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => 'What is in the image?',
						),
						array(
							'role'    => 'tool',
							'content' => array(
								array(
									'type' => 'output_text',
									'text' => array(
										array(
											'type' => 'text',
											'text' => 'The image shows a lighthouse at sunset.',
										),
										array(
											'type' => 'text',
											'text' => 'Warm orange light reflects across the water.',
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

		$this->assertCount( 2, $messages );
		$this->assertSame( 'tool', $messages[1]['role'] );
		$this->assertSame(
			"The image shows a lighthouse at sunset.\n\nWarm orange light reflects across the water.",
			$messages[1]['content']
		);
	}

	/**
	 * Tool responses that provide structured result arrays should be flattened
	 * so textual summaries remain visible in transcripts.
	 */
	public function test_transcript_flattens_tool_result_payloads() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		$row = array(
			'request_payload' => wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'tool',
							'content' => array(
								array(
									'type'   => 'json_schema',
									'result' => array(
										'summary'  => 'Here is your summary.',
										'details'  => array(
											array( 'message' => 'First supporting detail.' ),
											array( 'description' => 'Second supporting detail.' ),
										),
										'metadata' => array(
											'status' => 'ok',
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

		$this->assertCount( 1, $messages );
		$this->assertSame( 'tool', $messages[0]['role'] );
		$this->assertSame(
			"Here is your summary.\n\nFirst supporting detail.\n\nSecond supporting detail.",
			$messages[0]['content']
		);
	}

	/**
	 * Gemini tool_result payloads that expose output arrays should still render
	 * readable content within saved transcripts.
	 */
	public function test_transcript_flattens_tool_result_output_segments() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$extract_method = new ReflectionMethod( $rest_controller, 'extract_request_messages' );
		$extract_method->setAccessible( true );

		$row = array(
			'request_payload' => wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'tool',
							'content' => array(
								array(
									'type'   => 'tool_result',
									'output' => array(
										array(
											'type' => 'text',
											'text' => 'Gemini identified a clear sky.',
										),
										array(
											'type' => 'text',
											'text' => 'No precipitation expected for the next 24 hours.',
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

		$this->assertCount( 1, $messages );
		$this->assertSame( 'tool', $messages[0]['role'] );
		$this->assertSame(
			"Gemini identified a clear sky.\n\nNo precipitation expected for the next 24 hours.",
			$messages[0]['content']
		);
	}

	/**
	 * Ensure successful chat requests create a transcript record when storage is enabled.
	 */
	public function test_chat_transcript_saved_to_cct() {
		$this->register_transcript_handler();

		$response_payload = array(
			'id'       => 'chatcmpl-test',
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'status'   => 'completed',
			'choices'  => array(
				array(
					'index'         => 0,
					'finish_reason' => 'stop',
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello!',
					),
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 20,
				'total_tokens'      => 30,
			),
		);

		$this->set_mock_rest_controller( $response_payload );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi there',
				),
			)
		);
		$request->set_param( 'session_key', 'Session-123_TEST' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->transcript_handler->records, 'Transcript handler should capture a saved record.' );

		$record = $this->transcript_handler->records[0];
		$this->assertSame( 'Session-123_TEST', $record['session_key'] );
		$this->assertSame( $this->admin_id, (int) $record['user_id'] );
		$this->assertSame( (string) $this->assistant_id, $record['assistant_id'] );
		$this->assertSame( 'gpt-4o-mini', $record['assistant_model'] );
		$this->assertArrayHasKey( 'latency_ms', $record );
		$this->assertGreaterThanOrEqual( 0, $record['latency_ms'] );
		$this->assertNotEmpty( $record['request_started_at'] );
		$this->assertNotEmpty( $record['response_completed_at'] );

		$request_payload = json_decode( $record['request_payload'], true );
		$this->assertIsArray( $request_payload );
		$this->assertArrayHasKey( 'messages', $request_payload );
		$this->assertSame( 'user', $request_payload['messages'][0]['role'] );

		$metadata = json_decode( $record['metadata'], true );
		$this->assertIsArray( $metadata );
		$this->assertSame( 'openai', $metadata['provider'] );
		$this->assertSame( 'completed', $metadata['status'] );
		$this->assertSame( 'chatcmpl-test', $metadata['response_id'] );
		$this->assertContains( 'stop', $metadata['finish_reasons'] );
		$this->assertSame(
			array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 20,
				'total_tokens'      => 30,
			),
			$metadata['usage']
		);
	}

	/**
	 * Ensure transcripts are not saved when the request explicitly disables persistence.
	 */
	public function test_chat_transcript_not_saved_when_disabled() {
		$this->register_transcript_handler();

		$response_payload = array(
			'id'       => 'chatcmpl-disabled',
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'status'   => 'completed',
			'choices'  => array(),
		);

		$this->set_mock_rest_controller( $response_payload );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Skip this one',
				),
			)
		);
		$request->set_param( 'save_transcript', 'false' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( $this->transcript_handler->records, 'Transcript handler should remain empty when saving is disabled.' );
	}

	/**
	 * Replace the REST controller client with a mock that returns a canned response.
	 *
	 * @param array $response_payload Mock response payload.
	 */
	protected function set_mock_rest_controller( array $response_payload ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( $response_payload );

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Register a mock transcript handler that captures stored records.
	 */
	protected function register_transcript_handler() {
		$this->transcript_handler = new class() {
			public $records = array();

			public function update_item( $item ) {
				$this->records[] = $item;
				return count( $this->records );
			}
		};

		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10, 7 );
	}

	/**
	 * Provide the mock transcript handler for storage.
	 *
	 * @param object|null     $handler       Existing handler.
	 * @param int             $assistant_id  Assistant identifier.
	 * @param array           $messages      Sanitised messages.
	 * @param array           $options       Prepared options.
	 * @param array           $response      Response payload.
	 * @param WP_REST_Request $request       REST request instance.
	 * @param array           $context       Additional context.
	 * @return object
	 */
	public function provide_transcript_handler( $handler, $assistant_id = 0, $messages = array(), $options = array(), $response = array(), $request = null, $context = array() ) {
		return $this->transcript_handler;
	}
}
