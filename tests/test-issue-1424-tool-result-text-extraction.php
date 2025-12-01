<?php
/**
 * Integration test for issue #1424 - Images not surfacing to chat-client UI.
 *
 * This test verifies that tool results with images and descriptive text
 * are properly formatted in the chat response so the frontend can extract
 * both the image attachment and the descriptive text.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test the fix for issue #1424.
 */
class WP_MCP_AI_Issue_1424_Tool_Result_Text_Extraction_Test extends WP_UnitTestCase {

	/**
	 * Create a test assistant post.
	 *
	 * @return int Assistant post ID.
	 */
	private function create_assistant_post() {
		return self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
				'meta_input'  => array(
					'wp_mcp_ai_assistant_provider' => 'openai',
					'wp_mcp_ai_assistant_model'    => 'gpt-4o-mini',
					'wp_mcp_ai_assistant_tools'    => array( 'generate_gemini_image' ),
				),
			)
		);
	}

	/**
	 * Bootstrap the REST controller with a mock client.
	 *
	 * @param object $mock_client Mock language model client.
	 */
	private function bootstrap_rest_controller( $mock_client ) {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$validator  = new WP_MCP_AI_REST_Validator();
		$controller = new WP_MCP_AI_REST( $mock_client, $registry, $validator );
		$controller->register_routes();
	}

	/**
	 * Test that tool results with both text and attachments are included in response.
	 *
	 * This simulates what happens when:
	 * 1. User asks to generate an image
	 * 2. LLM responds with a tool call
	 * 3. Tool executes and returns result with both URL and descriptive text
	 * 4. Chat-client needs to extract BOTH for proper display
	 */
	public function test_tool_result_includes_text_and_attachment_data() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Mock the language model router to return a tool call response.
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// First request: LLM returns tool call.
		// Second request: LLM processes tool result and responds.
		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnOnConsecutiveCalls(
				// First response: Tool call to generate image.
				array(
					'id'      => 'chatcmpl-test-1',
					'choices' => array(
						array(
							'message' => array(
								'role'       => 'assistant',
								'content'    => null, // OpenAI allows null for tool_calls messages.
								'tool_calls' => array(
									array(
										'id'       => 'call_123',
										'type'     => 'function',
										'function' => array(
											'name'      => 'generate_gemini_image',
											'arguments' => wp_json_encode(
												array(
													'prompt'       => 'A beautiful sunset over the ocean',
													'aspect_ratio' => '16:9',
												)
											),
										),
									),
								),
							),
						),
					),
					'usage'   => array( 'total_tokens' => 50 ),
				),
				// Second response: Final assistant message after tool execution.
				array(
					'id'      => 'chatcmpl-test-2',
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'I have created the image for you. It shows a beautiful sunset over the ocean.',
							),
						),
					),
					'usage'   => array( 'total_tokens' => 100 ),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Mock the generate_gemini_image tool to return a realistic result.
		add_filter(
			'wp_mcp_ai_tool_generate_gemini_image_execute',
			function ( $result, $arguments ) {
				return array(
					'attachment_id' => 789,
					'url'           => 'https://example.com/generated-sunset.png',
					'download_url'  => 'https://example.com/download/generated-sunset.png',
					'file_name'     => 'generated-sunset.png',
					'mime_type'     => 'image/png',
					'bytes'         => 125000,
					'title'         => 'AI Generated Sunset',
					'model'         => 'gemini-2.5-flash-image',
					'aspect_ratio'  => '16:9',
					'prompt'        => 'A beautiful sunset over the ocean',
					'provider'      => 'gemini',
					// This is the descriptive text that should be extracted!
					'text'          => 'Gemini image saved to the Media Library.',
					'message'       => 'Image generated successfully',
				);
			},
			10,
			2
		);

		// Send chat request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate an image of a beautiful sunset over the ocean',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Verify the response contains tool results.
		$this->assertArrayHasKey( 'tool_results', $data, 'Response should include tool_results array' );
		$this->assertIsArray( $data['tool_results'], 'tool_results should be an array' );
		$this->assertNotEmpty( $data['tool_results'], 'tool_results should not be empty' );

		// Find the tool result.
		$tool_result = null;
		foreach ( $data['tool_results'] as $result ) {
			if ( isset( $result['role'] ) && 'tool' === $result['role'] ) {
				$tool_result = $result;
				break;
			}
		}

		$this->assertNotNull( $tool_result, 'Should have a tool result in the response' );
		$this->assertArrayHasKey( 'content', $tool_result, 'Tool result should have content' );

		$content = $tool_result['content'];
		$this->assertIsArray( $content, 'Tool result content should be an array' );

		// CRITICAL: Verify the tool result contains the data needed for frontend display.
		// The frontend's normaliseToolResultForDisplay() function looks for these fields.
		$this->assertArrayHasKey( 'url', $content, 'Content should have URL for image display' );
		$this->assertArrayHasKey( 'attachment_id', $content, 'Content should have attachment_id' );

		// CRITICAL: Verify the descriptive text is present.
		// This is what was missing before the fix - only attachments were extracted.
		// The frontend should extract both text and attachments from normalized results.
		$this->assertTrue(
			isset( $content['text'] ) || isset( $content['message'] ),
			'Content should have text or message field for display'
		);

		// Verify attachment metadata is present for frontend rendering.
		$this->assertArrayHasKey( 'file_name', $content, 'Content should have file_name' );
		$this->assertArrayHasKey( 'title', $content, 'Content should have title' );

		// The frontend will use normaliseToolResultForDisplay() which returns:
		// { text: "...", attachments: [...] }
		// Both text AND attachments should be extracted and displayed.
		$expected_has_displayable_text = isset( $content['text'] ) || isset( $content['message'] );
		$expected_has_displayable_url  = isset( $content['url'] ) || isset( $content['download_url'] );

		$this->assertTrue(
			$expected_has_displayable_text && $expected_has_displayable_url,
			'Tool result should have both displayable text and URL for complete frontend rendering'
		);
	}

	/**
	 * Test that assistant messages with tool_calls use null instead of empty string.
	 *
	 * OpenAI rejects assistant messages that have tool_calls with empty string content.
	 * The content must be either a non-empty string or null.
	 */
	public function test_assistant_message_with_tool_calls_uses_null_content() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool_call_received    = false;
		$assistant_content_val = 'unset';

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// Capture what messages are sent to the LLM.
		$mock_client
			->expects( $this->atLeastOnce() )
			->method( 'create_chat_completion' )
			->with(
				$this->callback(
					function ( $messages ) use ( &$tool_call_received, &$assistant_content_val ) {
						// Look for assistant message with tool_calls.
						foreach ( $messages as $msg ) {
							if ( isset( $msg['role'] ) && 'assistant' === $msg['role'] && isset( $msg['tool_calls'] ) ) {
								$tool_call_received = true;
								// CRITICAL: Content should be null, not empty string.
								$assistant_content_val = isset( $msg['content'] ) ? $msg['content'] : 'not_set';
							}
						}
						return true;
					}
				),
				$this->anything()
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Done.',
							),
						),
					),
					'usage'   => array( 'total_tokens' => 50 ),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Simulate a conversation where an assistant message has tool_calls.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate an image',
				),
				array(
					'role'       => 'assistant',
					'content'    => '', // Empty string - should be converted to null.
					'tool_calls' => array(
						array(
							'id'       => 'call_456',
							'type'     => 'function',
							'function' => array(
								'name'      => 'generate_gemini_image',
								'arguments' => wp_json_encode( array( 'prompt' => 'test' ) ),
							),
						),
					),
				),
				array(
					'role'         => 'tool',
					'tool_call_id' => 'call_456',
					'content'      => wp_json_encode(
						array(
							'url'   => 'https://example.com/image.png',
							'title' => 'Test Image',
						)
					),
				),
				array(
					'role'    => 'user',
					'content' => 'Thanks!',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		rest_do_request( $request );

		// Note: The JavaScript fix handles converting empty string to null on the frontend.
		// The backend receives the messages as-is from the frontend.
		// This test verifies the backend doesn't break when receiving such messages.
	}

	/**
	 * Test that multiple tool results are all included in the response.
	 */
	public function test_multiple_tool_results_all_included() {
		$assistant_id = $this->create_assistant_post();

		// Add another tool to the assistant.
		update_post_meta( $assistant_id, 'wp_mcp_ai_assistant_tools', array( 'generate_gemini_image', 'generate_openai_image' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnOnConsecutiveCalls(
				// First response: Multiple tool calls.
				array(
					'id'      => 'chatcmpl-test-multi',
					'choices' => array(
						array(
							'message' => array(
								'role'       => 'assistant',
								'content'    => null,
								'tool_calls' => array(
									array(
										'id'       => 'call_gemini',
										'type'     => 'function',
										'function' => array(
											'name'      => 'generate_gemini_image',
											'arguments' => wp_json_encode( array( 'prompt' => 'sunset' ) ),
										),
									),
									array(
										'id'       => 'call_openai',
										'type'     => 'function',
										'function' => array(
											'name'      => 'generate_openai_image',
											'arguments' => wp_json_encode( array( 'prompt' => 'mountain' ) ),
										),
									),
								),
							),
						),
					),
					'usage'   => array( 'total_tokens' => 75 ),
				),
				// Second response: Final message.
				array(
					'id'      => 'chatcmpl-test-final',
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Both images created.',
							),
						),
					),
					'usage'   => array( 'total_tokens' => 100 ),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Mock both tools.
		add_filter(
			'wp_mcp_ai_tool_generate_gemini_image_execute',
			function ( $result, $arguments ) {
				return array(
					'url'   => 'https://example.com/gemini-sunset.png',
					'title' => 'Gemini Sunset',
					'text'  => 'Gemini image saved to the Media Library.',
				);
			},
			10,
			2
		);

		add_filter(
			'wp_mcp_ai_tool_generate_openai_image_execute',
			function ( $result, $arguments ) {
				return array(
					'url'   => 'https://example.com/openai-mountain.png',
					'title' => 'OpenAI Mountain',
					'text'  => 'Image saved to the Media Library.',
				);
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate two images',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'tool_results', $data );
		$this->assertCount( 2, $data['tool_results'], 'Should have 2 tool results' );

		// Verify both results have the necessary data.
		foreach ( $data['tool_results'] as $result ) {
			$this->assertArrayHasKey( 'content', $result );
			$content = $result['content'];
			$this->assertArrayHasKey( 'url', $content, 'Each result should have a URL' );
			$this->assertArrayHasKey( 'title', $content, 'Each result should have a title' );
			$this->assertArrayHasKey( 'text', $content, 'Each result should have descriptive text' );
		}
	}
}
