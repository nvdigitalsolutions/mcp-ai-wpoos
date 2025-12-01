<?php
/**
 * Test that messages containing only images are preserved in the agentic workflow.
 *
 * This test validates the fix for the issue where image-only messages cause
 * "Invalid parameter(s): messages" errors in the agentic workflow.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for image-only message handling.
 */
class WP_MCP_AI_Chat_Image_Only_Messages_Test extends WP_UnitTestCase {

	/**
	 * Test that extract_request_messages preserves messages with image content.
	 */
	public function test_extract_request_messages_preserves_image_only_messages() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$rest = new WP_MCP_AI_REST();

		// Create a mock transcript row with an image-only message.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Generate an image of a cat',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'generate_openai_image',
							'arguments' => '{"prompt":"a cat"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_openai_image',
				'content'      => '{"image_url":"https://example.com/cat.jpg"}',
			),
			// This is the critical message - assistant returns only an image, no text.
			array(
				'role'    => 'assistant',
				'content' => array(
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => 'https://example.com/cat.jpg',
						),
					),
				),
			),
		);

		$request_payload = wp_json_encode(
			array(
				'messages' => $messages,
			)
		);

		$row = array(
			'request_payload' => $request_payload,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'extract_request_messages' );
		$method->setAccessible( true );

		// Extract messages.
		$extracted = $method->invoke( $rest, $row );

		// Verify all messages were preserved.
		$this->assertCount( 4, $extracted, 'All 4 messages should be preserved' );

		// Verify the image-only assistant message was preserved.
		$last_message = end( $extracted );
		$this->assertSame( 'assistant', $last_message['role'], 'Last message should be assistant role' );
		$this->assertIsArray( $last_message['content'], 'Image-only message content should be preserved as array' );
		$this->assertCount( 1, $last_message['content'], 'Should have one content segment' );
		$this->assertSame( 'image_url', $last_message['content'][0]['type'], 'Content should be image_url type' );
	}

	/**
	 * Test that extract_response_messages preserves image-only assistant responses.
	 */
	public function test_extract_response_messages_preserves_image_only_responses() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$rest = new WP_MCP_AI_REST();

		// Create a mock response payload with an image-only message.
		$response_payload = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => array(
								array(
									'type'      => 'image_url',
									'image_url' => array(
										'url' => 'https://example.com/generated-image.jpg',
									),
								),
							),
						),
					),
				),
			)
		);

		$row = array(
			'response_payload' => $response_payload,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'extract_response_messages' );
		$method->setAccessible( true );

		// Extract messages.
		$extracted = $method->invoke( $rest, $row );

		// Verify the message was preserved.
		$this->assertCount( 1, $extracted, 'Image-only response message should be preserved' );
		$this->assertSame( 'assistant', $extracted[0]['role'], 'Message should be assistant role' );
		$this->assertIsArray( $extracted[0]['content'], 'Image-only message content should be preserved as array' );
		$this->assertSame( 'image_url', $extracted[0]['content'][0]['type'], 'Content should be image_url type' );
	}

	/**
	 * Test message_has_image_content helper method.
	 */
	public function test_message_has_image_content_detection() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$rest = new WP_MCP_AI_REST();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'message_has_image_content' );
		$method->setAccessible( true );

		// Test 1: Message with image_url type should be detected.
		$message_with_image_url = array(
			'role'    => 'assistant',
			'content' => array(
				array(
					'type'      => 'image_url',
					'image_url' => array(
						'url' => 'https://example.com/image.jpg',
					),
				),
			),
		);
		$this->assertTrue(
			$method->invoke( $rest, $message_with_image_url ),
			'Should detect message with image_url type'
		);

		// Test 2: Message with image_file type should be detected.
		$message_with_image_file = array(
			'role'    => 'assistant',
			'content' => array(
				array(
					'type'       => 'image_file',
					'image_file' => array(
						'file_id' => 'file-123',
					),
				),
			),
		);
		$this->assertTrue(
			$method->invoke( $rest, $message_with_image_file ),
			'Should detect message with image_file type'
		);

		// Test 3: Message with mixed text and image should be detected.
		$message_with_mixed_content = array(
			'role'    => 'assistant',
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'Here is your image:',
				),
				array(
					'type'      => 'image_url',
					'image_url' => array(
						'url' => 'https://example.com/image.jpg',
					),
				),
			),
		);
		$this->assertTrue(
			$method->invoke( $rest, $message_with_mixed_content ),
			'Should detect message with mixed text and image content'
		);

		// Test 4: Text-only message should not be detected as having image content.
		$message_text_only = array(
			'role'    => 'assistant',
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'This is just text',
				),
			),
		);
		$this->assertFalse(
			$method->invoke( $rest, $message_text_only ),
			'Should not detect text-only message as having image content'
		);

		// Test 5: String content should not be detected as having image content.
		$message_string_content = array(
			'role'    => 'assistant',
			'content' => 'This is a plain string',
		);
		$this->assertFalse(
			$method->invoke( $rest, $message_string_content ),
			'Should not detect string content as having image content'
		);

		// Test 6: Empty message should not be detected as having image content.
		$empty_message = array(
			'role' => 'assistant',
		);
		$this->assertFalse(
			$method->invoke( $rest, $empty_message ),
			'Should not detect empty message as having image content'
		);
	}

	/**
	 * Test that agentic workflow continues correctly after image-only messages.
	 *
	 * This simulates the real scenario where an image is returned in one iteration
	 * and the workflow needs to continue to the next iteration.
	 */
	public function test_agentic_workflow_with_image_only_message() {
		// Create an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Image Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Configure assistant with a model and tools.
		update_post_meta( $assistant_id, 'model', 'gpt-4o-mini' );
		update_post_meta( $assistant_id, 'provider', 'openai' );
		update_post_meta( $assistant_id, 'enabled_tools', array( 'get_current_time' ) );

		// Build a message array that includes an image-only message.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What time is it?',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_time',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_current_time',
							'arguments' => '{}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_time',
				'name'         => 'get_current_time',
				'content'      => wp_json_encode( array( 'time' => '2:00 PM' ) ),
			),
			// Image-only message that previously caused issues.
			array(
				'role'    => 'assistant',
				'content' => array(
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => 'https://example.com/clock.jpg',
						),
					),
				),
			),
		);

		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$rest = new WP_MCP_AI_REST();

		// Create a mock transcript row.
		$request_payload = wp_json_encode(
			array(
				'messages' => $messages,
			)
		);

		$row = array(
			'request_payload' => $request_payload,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'extract_request_messages' );
		$method->setAccessible( true );

		// Extract messages - this should not fail.
		$extracted = $method->invoke( $rest, $row );

		// Verify all messages were preserved, including the image-only one.
		$this->assertCount( 4, $extracted, 'All messages including image-only should be preserved' );

		// Verify the image-only message structure is intact.
		$image_message = $extracted[3];
		$this->assertSame( 'assistant', $image_message['role'] );
		$this->assertIsArray( $image_message['content'] );
		$this->assertArrayHasKey( 'type', $image_message['content'][0] );
		$this->assertSame( 'image_url', $image_message['content'][0]['type'] );
		$this->assertArrayHasKey( 'image_url', $image_message['content'][0] );
		$this->assertSame( 'https://example.com/clock.jpg', $image_message['content'][0]['image_url']['url'] );
	}

	/**
	 * Test that user messages with images are also preserved.
	 */
	public function test_user_messages_with_images_preserved() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$rest = new WP_MCP_AI_REST();

		// Create messages with user sending an image.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'What is in this image?',
					),
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => 'https://example.com/photo.jpg',
						),
					),
				),
			),
			array(
				'role'    => 'assistant',
				'content' => 'I see a photo of a cat.',
			),
		);

		$request_payload = wp_json_encode(
			array(
				'messages' => $messages,
			)
		);

		$row = array(
			'request_payload' => $request_payload,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'extract_request_messages' );
		$method->setAccessible( true );

		// Extract messages.
		$extracted = $method->invoke( $rest, $row );

		// Verify both messages were preserved.
		$this->assertCount( 2, $extracted, 'Both user and assistant messages should be preserved' );

		// Verify user message with mixed content is preserved correctly.
		$user_message = $extracted[0];
		$this->assertSame( 'user', $user_message['role'] );
		$this->assertIsArray( $user_message['content'], 'Mixed content should be preserved as array' );
		$this->assertCount( 2, $user_message['content'], 'Should have 2 content segments (text + image)' );
		$this->assertSame( 'text', $user_message['content'][0]['type'] );
		$this->assertSame( 'image_url', $user_message['content'][1]['type'] );
	}

	/**
	 * Test that image-only user messages are preserved.
	 */
	public function test_user_image_only_messages_preserved() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$rest = new WP_MCP_AI_REST();

		// User sends only an image, no text.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => 'https://example.com/screenshot.jpg',
						),
					),
				),
			),
		);

		$request_payload = wp_json_encode(
			array(
				'messages' => $messages,
			)
		);

		$row = array(
			'request_payload' => $request_payload,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'extract_request_messages' );
		$method->setAccessible( true );

		// Extract messages.
		$extracted = $method->invoke( $rest, $row );

		// Verify the image-only user message was preserved.
		$this->assertCount( 1, $extracted, 'Image-only user message should be preserved' );
		$this->assertSame( 'user', $extracted[0]['role'] );
		$this->assertIsArray( $extracted[0]['content'] );
		$this->assertSame( 'image_url', $extracted[0]['content'][0]['type'] );
	}
}
