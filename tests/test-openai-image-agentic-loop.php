<?php
/**
 * Test OpenAI image generation tool agentic loop support.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-service.php';

/**
 * Test OpenAI image tool integration with agentic loop.
 */
class WP_MCP_AI_OpenAI_Image_Agentic_Loop_Test extends WP_UnitTestCase {

	/**
	 * Create a chat service instance with mock dependencies.
	 *
	 * @return WP_MCP_AI_Chat_Service
	 */
	private function create_chat_service() {
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_rate_limiter = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_token_budget = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();

		$mock_tool_registry = $this->getMockBuilder( WP_MCP_AI_Tool_Registry::class )
			->disableOriginalConstructor()
			->getMock();

		return new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget,
			$mock_tool_registry
		);
	}

	/**
	 * Test that sanitize_for_llm includes image_url structure.
	 */
	public function test_sanitize_for_llm_includes_image_url() {
		$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();

		// Simulate a tool result with all the fields returned by generate_openai_image
		$tool_result = array(
			'attachment_id'  => 123,
			'url'            => 'https://example.com/generated-image.png',
			'file_path'      => '/path/to/image.png',
			'file_name'      => 'generated-image.png',
			'mime_type'      => 'image/png',
			'bytes'          => 1024,
			'format'         => 'png',
			'size'           => '1024x1024',
			'quality'        => 'medium',
			'model'          => 'gpt-image-1',
			'response_format' => 'b64_json',
			'revised_prompt' => 'A beautiful sunset',
			'created'        => time(),
			'text'           => 'Successfully generated image (ID: 123).',
			'content'        => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'file_name' => 'generated-image.png',
				'bytes'     => 95,
			),
		);

		$sanitized = $tool->sanitize_for_llm( $tool_result );

		// Verify base64 content was stripped
		$this->assertArrayNotHasKey( 'content', $sanitized, 'Base64 content should be removed' );

		// Verify essential metadata is preserved
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'file_name', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );

		// Verify image_url structure was added
		$this->assertArrayHasKey( 'image_url', $sanitized, 'image_url structure should be added' );
		$this->assertIsArray( $sanitized['image_url'], 'image_url should be an array' );
		$this->assertArrayHasKey( 'url', $sanitized['image_url'], 'image_url should have a url field' );
		$this->assertEquals( 'https://example.com/generated-image.png', $sanitized['image_url']['url'], 'image_url.url should match the result URL' );
	}

	/**
	 * Test that sanitize_for_llm handles results without URL.
	 */
	public function test_sanitize_for_llm_without_url() {
		$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();

		// Simulate a tool result without URL (edge case)
		$tool_result = array(
			'attachment_id' => 123,
			'file_name'     => 'generated-image.png',
			'text'          => 'Successfully generated image.',
		);

		$sanitized = $tool->sanitize_for_llm( $tool_result );

		// Verify image_url is not added when there's no URL
		$this->assertArrayNotHasKey( 'image_url', $sanitized, 'image_url should not be added without URL' );

		// Verify other fields are still preserved
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'file_name', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );
	}

	/**
	 * Test that extract_images_from_tool_results creates proper user message.
	 */
	public function test_extract_images_from_tool_results() {
		$chat_service = $this->create_chat_service();
		$reflection   = new ReflectionClass( $chat_service );
		$method       = $reflection->getMethod( 'extract_images_from_tool_results' );
		$method->setAccessible( true );

		// Simulate tool results with image_url structure
		$tool_results = array(
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_openai_image',
				'content'      => wp_json_encode(
					array(
						'attachment_id' => 123,
						'url'           => 'https://example.com/image1.png',
						'image_url'     => array(
							'url' => 'https://example.com/image1.png',
						),
						'text'          => 'Image generated successfully',
					)
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_456',
				'name'         => 'generate_openai_image',
				'content'      => wp_json_encode(
					array(
						'attachment_id' => 456,
						'url'           => 'https://example.com/image2.png',
						'image_url'     => array(
							'url' => 'https://example.com/image2.png',
						),
						'text'          => 'Image generated successfully',
					)
				),
			),
		);

		$result = $method->invoke( $chat_service, $tool_results );

		// Verify a user message was created
		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertEquals( 'user', $result['role'], 'Should be a user message' );
		$this->assertIsArray( $result['content'], 'Content should be an array' );
		$this->assertCount( 3, $result['content'], 'Should have 3 content blocks: 1 text + 2 images' );

		// Verify first content block is text
		$this->assertEquals( 'text', $result['content'][0]['type'] );

		// Verify second and third content blocks are images
		$this->assertEquals( 'image_url', $result['content'][1]['type'] );
		$this->assertArrayHasKey( 'image_url', $result['content'][1] );
		$this->assertEquals( 'https://example.com/image1.png', $result['content'][1]['image_url']['url'] );

		$this->assertEquals( 'image_url', $result['content'][2]['type'] );
		$this->assertArrayHasKey( 'image_url', $result['content'][2] );
		$this->assertEquals( 'https://example.com/image2.png', $result['content'][2]['image_url']['url'] );
	}

	/**
	 * Test that extract_images_from_tool_results returns null when no images found.
	 */
	public function test_extract_images_from_tool_results_no_images() {
		$chat_service = $this->create_chat_service();
		$reflection   = new ReflectionClass( $chat_service );
		$method       = $reflection->getMethod( 'extract_images_from_tool_results' );
		$method->setAccessible( true );

		// Simulate tool results without image_url structures
		$tool_results = array(
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'some_other_tool',
				'content'      => wp_json_encode(
					array(
						'result' => 'Success',
						'data'   => array( 'key' => 'value' ),
					)
				),
			),
		);

		$result = $method->invoke( $chat_service, $tool_results );

		// Verify null was returned
		$this->assertNull( $result, 'Should return null when no images found' );
	}

	/**
	 * Test that ensure_response_has_content extracts text from tool results when LLM returns no content.
	 *
	 * This is the fix for the broken agentic loop when using generate_openai_image.
	 */
	public function test_ensure_response_has_content_extracts_text_from_tool_results() {
		$chat_service = $this->create_chat_service();
		$reflection   = new ReflectionClass( $chat_service );
		$method       = $reflection->getMethod( 'ensure_response_has_content' );
		$method->setAccessible( true );

		// Simulate an empty LLM response (no content)
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => '', // Empty content - this is the issue!
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Simulate tool results with text field
		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_openai_image',
				'content'      => wp_json_encode(
					array(
						'attachment_id' => 123,
						'url'           => 'https://example.com/image1.png',
						'text'          => 'Successfully generated image (ID: 123). Size: 1024x1024, Quality: medium',
					)
				),
			),
		);

		$assistant_id = 1;

		$result = $method->invoke( $chat_service, $response, $tool_result_messages, $assistant_id );

		// Verify the response now has content extracted from tool results
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertArrayHasKey( 'message', $result['choices'][0] );
		$this->assertArrayHasKey( 'content', $result['choices'][0]['message'] );
		$this->assertNotEmpty( $result['choices'][0]['message']['content'], 'Content should be extracted from tool results' );
		$this->assertStringContainsString( 'Successfully generated image', $result['choices'][0]['message']['content'] );
	}

	/**
	 * Test that ensure_response_has_content does not modify response when content already exists.
	 */
	public function test_ensure_response_has_content_does_not_modify_existing_content() {
		$chat_service = $this->create_chat_service();
		$reflection   = new ReflectionClass( $chat_service );
		$method       = $reflection->getMethod( 'ensure_response_has_content' );
		$method->setAccessible( true );

		$original_content = 'Here is the image I generated for you.';

		// Simulate a response with existing content
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => $original_content,
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Simulate tool results with text field
		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_openai_image',
				'content'      => wp_json_encode(
					array(
						'text' => 'This should NOT replace the original content.',
					)
				),
			),
		);

		$assistant_id = 1;

		$result = $method->invoke( $chat_service, $response, $tool_result_messages, $assistant_id );

		// Verify the original content is preserved
		$this->assertEquals( $original_content, $result['choices'][0]['message']['content'], 'Original content should be preserved' );
	}

	/**
	 * Test that ensure_response_has_content handles null content.
	 */
	public function test_ensure_response_has_content_handles_null_content() {
		$chat_service = $this->create_chat_service();
		$reflection   = new ReflectionClass( $chat_service );
		$method       = $reflection->getMethod( 'ensure_response_has_content' );
		$method->setAccessible( true );

		// Simulate a response with null content (common when LLM only wants to call tools)
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => null,
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Simulate tool results with text field
		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_openai_image',
				'content'      => wp_json_encode(
					array(
						'text' => 'Image generated successfully!',
					)
				),
			),
		);

		$assistant_id = 1;

		$result = $method->invoke( $chat_service, $response, $tool_result_messages, $assistant_id );

		// Verify the response now has content extracted from tool results
		$this->assertNotEmpty( $result['choices'][0]['message']['content'] );
		$this->assertEquals( 'Image generated successfully!', $result['choices'][0]['message']['content'] );
	}

	/**
	 * Test that ensure_response_has_content returns unchanged response when no tool results.
	 */
	public function test_ensure_response_has_content_returns_unchanged_when_no_tool_results() {
		$chat_service = $this->create_chat_service();
		$reflection   = new ReflectionClass( $chat_service );
		$method       = $reflection->getMethod( 'ensure_response_has_content' );
		$method->setAccessible( true );

		// Simulate a response with empty content
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => '',
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Empty tool results
		$tool_result_messages = array();
		$assistant_id         = 1;

		$result = $method->invoke( $chat_service, $response, $tool_result_messages, $assistant_id );

		// Verify the response is unchanged (empty content stays empty)
		$this->assertEmpty( $result['choices'][0]['message']['content'] );
	}
}
