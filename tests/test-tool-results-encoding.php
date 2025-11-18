<?php
/**
 * Test tool results encoding in chat responses.
 *
 * Verifies that tool results are properly JSON-encoded in the tool_results
 * array sent to chat clients, ensuring image generation and other tools
 * surface correctly.
 */
class WP_MCP_AI_Tool_Results_Encoding_Test extends WP_UnitTestCase {

	/**
	 * Test that tool results content is JSON-encoded.
	 */
	public function test_tool_result_content_is_json_encoded() {
		// Create assistant.
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a mock tool that returns a complex result.
		$mock_tool = $this->getMockBuilder( WP_MCP_AI_Tool_Interface::class )
			->onlyMethods( array( 'get_slug', 'get_name', 'get_description', 'get_parameters_schema', 'execute' ) )
			->getMock();

		$mock_tool->method( 'get_slug' )->willReturn( 'test_image_tool' );
		$mock_tool->method( 'get_name' )->willReturn( 'Test Image Tool' );
		$mock_tool->method( 'get_description' )->willReturn( 'A test tool' );
		$mock_tool->method( 'get_parameters_schema' )->willReturn(
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);

		// Mock tool returns a complex result with image content.
		$tool_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
			'text'          => 'Image created successfully',
			'content'       => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwAB/aurH8kAAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
			),
		);

		$mock_tool->method( 'execute' )->willReturn( $tool_result );

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Update assistant to use the test tool.
		update_post_meta( $assistant_id, 'tools', array( 'test_image_tool' ) );

		// Create a mock client that simulates tool call and response.
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// First call: LLM requests to use the tool.
		// Second call: LLM responds with final answer.
		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnOnConsecutiveCalls(
				// First response: LLM wants to call the tool.
				array(
					'id'      => 'chatcmpl-test1',
					'choices' => array(
						array(
							'index'   => 0,
							'message' => array(
								'role'       => 'assistant',
								'content'    => '',
								'tool_calls' => array(
									array(
										'id'       => 'call_123',
										'type'     => 'function',
										'function' => array(
											'name'      => 'test_image_tool',
											'arguments' => '{}',
										),
									),
								),
							),
						),
					),
				),
				// Second response: LLM provides final answer.
				array(
					'id'      => 'chatcmpl-test2',
					'choices' => array(
						array(
							'index'   => 0,
							'message' => array(
								'role'    => 'assistant',
								'content' => 'I created the image for you.',
							),
						),
					),
				)
			);

		// Bootstrap REST controller with mock client.
		$rest = new WP_MCP_AI_REST(
			$registry,
			$mock_client
		);

		// Make the chat request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Create an image please',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = $rest->handle_chat_request( $request );

		// Verify we got a valid response.
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();

		// Verify tool_results is present.
		$this->assertArrayHasKey( 'tool_results', $data, 'Response should include tool_results' );
		$this->assertNotEmpty( $data['tool_results'], 'tool_results should not be empty' );

		// Get the first tool result.
		$first_tool_result = $data['tool_results'][0];

		// Verify structure.
		$this->assertArrayHasKey( 'role', $first_tool_result );
		$this->assertEquals( 'tool', $first_tool_result['role'] );
		$this->assertArrayHasKey( 'content', $first_tool_result );
		$this->assertArrayHasKey( 'tool_call_id', $first_tool_result );

		// CRITICAL: Verify content is JSON-encoded string, not raw array.
		$this->assertIsString( $first_tool_result['content'], 'Tool result content must be a JSON string' );

		// Verify we can decode the content back to the original structure.
		$decoded_content = json_decode( $first_tool_result['content'], true );
		$this->assertIsArray( $decoded_content, 'Tool result content should be decodable to array' );

		// Verify the decoded content has expected fields from our mock tool.
		$this->assertArrayHasKey( 'attachment_id', $decoded_content );
		$this->assertEquals( 123, $decoded_content['attachment_id'] );
		$this->assertArrayHasKey( 'url', $decoded_content );
		$this->assertArrayHasKey( 'title', $decoded_content );
		$this->assertArrayHasKey( 'text', $decoded_content );

		// Most importantly: verify the 'content' field with base64 image data is preserved.
		$this->assertArrayHasKey( 'content', $decoded_content, 'Image content should be preserved' );
		$this->assertIsArray( $decoded_content['content'] );
		$this->assertArrayHasKey( 'data', $decoded_content['content'], 'Base64 image data should be present' );
		$this->assertArrayHasKey( 'encoding', $decoded_content['content'] );
		$this->assertEquals( 'base64', $decoded_content['content']['encoding'] );

		// Clean up.
		$registry->unregister_tool( 'test_image_tool' );
		wp_delete_post( $assistant_id, true );
	}
}
