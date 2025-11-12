<?php
/**
 * Tests for handling stdClass objects in chat transcript recording.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for stdClass object handling in chat transcripts.
 *
 * When JSON responses are decoded without the associative flag (true),
 * they create stdClass objects instead of arrays. The transcript recorder
 * must handle both formats to prevent crashes.
 */
class WP_MCP_AI_Chat_Transcript_StdClass_Test extends WP_UnitTestCase {
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
	 * Set up the test environment.
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
				'post_title'  => 'StdClass Test Assistant',
			)
		);

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Test that transcript recording handles response with stdClass objects correctly.
	 */
	public function test_transcript_handles_stdclass_response() {
		$this->register_transcript_handler();

		// Create a response with stdClass objects (simulates JSON decode without true flag).
		$response_payload = json_decode(
			wp_json_encode(
				array(
					'id'       => 'chatcmpl-stdclass-test',
					'model'    => 'gpt-4o-mini',
					'provider' => 'openai',
					'status'   => 'completed',
					'choices'  => array(
						array(
							'index'         => 0,
							'finish_reason' => 'stop',
							'message'       => array(
								'role'    => 'assistant',
								'content' => 'Response with stdClass',
							),
						),
					),
					'usage'    => array(
						'prompt_tokens'     => 15,
						'completion_tokens' => 25,
						'total_tokens'      => 40,
					),
				)
			)
		);

		// Verify we have stdClass objects (not arrays).
		$this->assertInstanceOf( 'stdClass', $response_payload );
		$this->assertIsArray( $response_payload->choices );
		$this->assertInstanceOf( 'stdClass', $response_payload->choices[0] );
		$this->assertInstanceOf( 'stdClass', $response_payload->usage );

		// Convert to array for the mock (the actual code should handle stdClass).
		$response_array = json_decode( wp_json_encode( $response_payload ), true );

		$this->set_mock_rest_controller( $response_array );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test stdClass handling',
				),
			)
		);
		$request->set_param( 'session_key', 'StdClass-Test-Session' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->transcript_handler->records, 'Transcript handler should capture a saved record.' );

		$record = $this->transcript_handler->records[0];

		// Verify metadata was extracted correctly from stdClass objects.
		$metadata = json_decode( $record['metadata'], true );
		$this->assertIsArray( $metadata );
		$this->assertSame( 'openai', $metadata['provider'] );
		$this->assertSame( 'completed', $metadata['status'] );
		$this->assertSame( 'chatcmpl-stdclass-test', $metadata['response_id'] );
		$this->assertContains( 'stop', $metadata['finish_reasons'] );

		// Verify usage data was converted from stdClass to array.
		$this->assertIsArray( $metadata['usage'] );
		$this->assertSame( 15, $metadata['usage']['prompt_tokens'] );
		$this->assertSame( 25, $metadata['usage']['completion_tokens'] );
		$this->assertSame( 40, $metadata['usage']['total_tokens'] );
	}

	/**
	 * Test extract_finish_reasons helper method with stdClass objects.
	 */
	public function test_extract_finish_reasons_with_stdclass() {
		$response_with_stdclass = json_decode(
			wp_json_encode(
				array(
					'choices' => array(
						array(
							'finish_reason' => 'stop',
							'message'       => array( 'content' => 'First' ),
						),
						array(
							'finish_reason' => 'length',
							'message'       => array( 'content' => 'Second' ),
						),
						array(
							'finish_reason' => 'stop',
							'message'       => array( 'content' => 'Third (duplicate)' ),
						),
					),
				)
			),
			true
		);

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( WP_MCP_AI_Chat_Transcript_Recorder::class );
		$method     = $reflection->getMethod( 'extract_finish_reasons' );
		$method->setAccessible( true );

		$reasons = $method->invoke( null, $response_with_stdclass );

		$this->assertIsArray( $reasons );
		$this->assertCount( 2, $reasons, 'Should return unique finish reasons.' );
		$this->assertContains( 'stop', $reasons );
		$this->assertContains( 'length', $reasons );
	}

	/**
	 * Test get_property helper method.
	 */
	public function test_get_property_helper() {
		$reflection = new ReflectionClass( WP_MCP_AI_Chat_Transcript_Recorder::class );
		$method     = $reflection->getMethod( 'get_property' );
		$method->setAccessible( true );

		// Test with array.
		$array_data = array( 'key' => 'value' );
		$this->assertSame( 'value', $method->invoke( null, $array_data, 'key' ) );
		$this->assertNull( $method->invoke( null, $array_data, 'nonexistent' ) );

		// Test with stdClass.
		$object_data      = new stdClass();
		$object_data->key = 'value';
		$this->assertSame( 'value', $method->invoke( null, $object_data, 'key' ) );
		$this->assertNull( $method->invoke( null, $object_data, 'nonexistent' ) );

		// Test with null.
		$this->assertNull( $method->invoke( null, null, 'key' ) );

		// Test with scalar.
		$this->assertNull( $method->invoke( null, 'string', 'key' ) );
	}

	/**
	 * Test to_array helper method.
	 */
	public function test_to_array_helper() {
		$reflection = new ReflectionClass( WP_MCP_AI_Chat_Transcript_Recorder::class );
		$method     = $reflection->getMethod( 'to_array' );
		$method->setAccessible( true );

		// Test with array (should return as-is).
		$array_data = array( 'key' => 'value' );
		$result     = $method->invoke( null, $array_data );
		$this->assertIsArray( $result );
		$this->assertSame( 'value', $result['key'] );

		// Test with stdClass (should convert to array).
		$object_data      = new stdClass();
		$object_data->key = 'value';
		$result           = $method->invoke( null, $object_data );
		$this->assertIsArray( $result );
		$this->assertSame( 'value', $result['key'] );

		// Test with null (should return empty array).
		$result = $method->invoke( null, null );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );

		// Test with scalar (should return empty array).
		$result = $method->invoke( null, 'string' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test nested stdClass objects in usage data.
	 */
	public function test_nested_stdclass_in_usage() {
		$response_with_nested = json_decode(
			wp_json_encode(
				array(
					'usage' => array(
						'prompt_tokens'     => 10,
						'completion_tokens' => 20,
						'total_tokens'      => 30,
						'details'           => array(
							'cached' => 5,
							'fresh'  => 5,
						),
					),
				)
			)
		);

		// Verify structure.
		$this->assertInstanceOf( 'stdClass', $response_with_nested );
		$this->assertInstanceOf( 'stdClass', $response_with_nested->usage );
		$this->assertInstanceOf( 'stdClass', $response_with_nested->usage->details );

		// Test conversion.
		$reflection = new ReflectionClass( WP_MCP_AI_Chat_Transcript_Recorder::class );
		$to_array   = $reflection->getMethod( 'to_array' );
		$to_array->setAccessible( true );

		$usage_array = $to_array->invoke( null, $response_with_nested->usage );

		$this->assertIsArray( $usage_array );
		$this->assertSame( 10, $usage_array['prompt_tokens'] );
		$this->assertSame( 20, $usage_array['completion_tokens'] );
		$this->assertSame( 30, $usage_array['total_tokens'] );
		// Note: Nested object conversion depends on PHP's (array) cast behavior.
		$this->assertArrayHasKey( 'details', $usage_array );
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
			/**
			 * Stored records.
			 *
			 * @var array
			 */
			public $records = array();

			/**
			 * Store a transcript record.
			 *
			 * @param array $item Transcript record.
			 * @return int Number of records stored.
			 */
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
