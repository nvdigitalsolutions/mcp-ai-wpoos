<?php
/**
 * Tests for JSON parsing behavior in chat transcript save endpoint.
 *
 * @package WP_MCP_AI
 */

/**
 * Test to verify JSON parsing behavior for chat transcript endpoint.
 *
 * This test investigates the issue where saving conversations fails with
 * "Invalid parameter(s): messages" error when WordPress REST API parses
 * JSON message objects as stdClass instead of arrays.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Chat_Transcript_JSON_Parsing_Test extends WP_UnitTestCase {

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
	 * Set up test fixtures.
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
				'post_title'  => 'JSON Parsing Test Assistant',
			)
		);

		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that JSON body with message objects is properly parsed.
	 *
	 * This test verifies that the endpoint correctly handles JSON bodies
	 * where message objects might be parsed as stdClass by WordPress REST API.
	 */
	public function test_json_body_parsing_with_message_objects() {
		$json_body = wp_json_encode(
			array(
				'assistant_id' => $this->assistant_id,
				'session_key'  => 'test-session-json-parse',
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => 'Hello, this is a test message',
					),
					array(
						'role'    => 'assistant',
						'content' => 'Hello! I am the assistant response.',
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $json_body );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Request should succeed with JSON body' );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], 'Response should indicate success' );
	}

	/**
	 * Test with complex message content (array of segments).
	 *
	 * This tests the case where message content is an array of objects.
	 */
	public function test_json_body_with_complex_content() {
		$json_body = wp_json_encode(
			array(
				'assistant_id' => $this->assistant_id,
				'session_key'  => 'test-session-complex',
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Here is my question',
							),
							array(
								'type'      => 'image_url',
								'image_url' => array(
									'url' => 'https://example.com/image.jpg',
								),
							),
						),
					),
					array(
						'role'    => 'assistant',
						'content' => 'I can see your image.',
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $json_body );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Request with complex content should succeed' );
	}

	/**
	 * Test with tool_calls in assistant message.
	 */
	public function test_json_body_with_tool_calls() {
		$json_body = wp_json_encode(
			array(
				'assistant_id' => $this->assistant_id,
				'session_key'  => 'test-session-tools',
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => 'Please search for information',
					),
					array(
						'role'       => 'assistant',
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'       => 'call_123',
								'type'     => 'function',
								'function' => array(
									'name'      => 'search_tool',
									'arguments' => '{"query": "test"}',
								),
							),
						),
					),
					array(
						'role'         => 'tool',
						'content'      => 'Search results here',
						'tool_call_id' => 'call_123',
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $json_body );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Request with tool_calls should succeed' );
	}
}
