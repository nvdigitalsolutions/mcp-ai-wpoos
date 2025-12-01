<?php
/**
 * Tests for REST chat message attachment handling.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_REST_Message_Attachments_Test extends WP_UnitTestCase {
	use WP_MCP_AI_Docx_Test_Helper;

	/**
	 * Ensure plain string messages are normalised into text segments.
	 */
	public function test_text_message_is_normalised_to_segment() {
		$assistant_id = $this->create_assistant_post();
		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello world',
				),
			),
			function ( $messages ) {
				$this->assertNotEmpty( $messages );
				$first = $messages[0];

				$this->assertArrayHasKey( 'content', $first );
				$this->assertIsArray( $first['content'] );
				$this->assertSame( 'text', $first['content'][0]['type'] );
				$this->assertSame( 'Hello world', $first['content'][0]['text'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure single segment objects are normalised into arrays.
	 */
	public function test_text_segment_object_is_normalised() {
		$assistant_id = $this->create_assistant_post();

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => 'Hello world',
					),
				),
			),
			function ( $messages ) {
				$this->assertNotEmpty( $messages );
				$first = $messages[0];

				$this->assertArrayHasKey( 'content', $first );
				$this->assertIsArray( $first['content'] );
				$this->assertCount( 1, $first['content'] );
				$this->assertSame( 'text', $first['content'][0]['type'] );
				$this->assertSame( 'Hello world', $first['content'][0]['text'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure remote image segments reject URLs that use unsupported schemes.
	 */
	public function test_remote_image_segment_rejects_disallowed_scheme() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		$result = $attachments_helper->prepare_input_image_segment(
			array(
				'url' => 'ftp://example.com/image.png',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_unsupported_image_url_scheme', $result->get_error_code() );
	}

	/**
	 * Ensure legacy input_text segments are still accepted and normalised to the new schema.
	 */
	public function test_legacy_input_text_segment_is_normalised() {
		$assistant_id = $this->create_assistant_post();

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => 'Legacy segment',
						),
					),
				),
			),
			function ( $messages ) {
				$this->assertNotEmpty( $messages );
				$first = $messages[0];

				$this->assertArrayHasKey( 'content', $first );
				$this->assertIsArray( $first['content'] );
				$this->assertSame( 'text', $first['content'][0]['type'] );
				$this->assertSame( 'Legacy segment', $first['content'][0]['text'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure system messages are forwarded to the model.
	 */
	public function test_system_role_message_is_preserved() {
		$assistant_id = $this->create_assistant_post();

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'system',
					'content' => 'Stay focused.',
				),
				array(
					'role'    => 'user',
					'content' => 'Hello world',
				),
			),
			function ( $messages ) {
				$this->assertCount( 2, $messages );

				$system_message = $messages[0];
				$this->assertSame( 'system', $system_message['role'] );
				$this->assertArrayHasKey( 'content', $system_message );
				$this->assertSame( 'Stay focused.', $system_message['content'][0]['text'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure legacy clients sending a top-level attachments parameter do not trigger validation errors.
	 */
	public function test_top_level_attachments_parameter_is_ignored() {
		$assistant_id = $this->create_assistant_post();

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello world',
				),
			),
			function ( $messages ) {
				$this->assertNotEmpty( $messages );

				$first = $messages[0];
				$this->assertArrayHasKey( 'content', $first );
				$this->assertSame( 'text', $first['content'][0]['type'] );
				$this->assertSame( 'Hello world', $first['content'][0]['text'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			},
			array(
				'attachments' => array(
					array( 'id' => 'file-123' ),
				),
			)
		);
	}

	/**
	 * Ensure tool role messages are accepted and metadata is preserved.
	 */
	public function test_tool_role_message_is_preserved() {
		$assistant_id = $this->create_assistant_post();

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => 'Call the tool',
				),
				array(
					'role'         => 'tool',
					'content'      => '{"result":"ok"}',
					'tool_call_id' => 'call_123',
				),
			),
			function ( $messages ) {
				$this->assertCount( 2, $messages );

				$tool_message = $messages[1];
				$this->assertSame( 'tool', $tool_message['role'] );
				$this->assertSame( 'call_123', $tool_message['tool_call_id'] );
				$this->assertSame( 'text', $tool_message['content'][0]['type'] );
				$this->assertSame( '{"result":"ok"}', $tool_message['content'][0]['text'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure assistant tool calls are preserved even when the content is empty.
	 */
	public function test_assistant_tool_calls_are_preserved_when_content_empty() {
		$assistant_id = $this->create_assistant_post();

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => 'Call the tool',
				),
				array(
					'role'       => 'assistant',
					'content'    => '',
					'tool_calls' => array(
						array(
							'id'       => 'call_123',
							'type'     => 'function',
							'function' => array(
								'name'      => 'fetch_data',
								'arguments' => '{"foo":"bar"}',
							),
						),
					),
				),
			),
			function ( $messages ) {
				$this->assertCount( 2, $messages );

				$assistant_message = $messages[1];
				$this->assertSame( 'assistant', $assistant_message['role'] );
				$this->assertArrayHasKey( 'content', $assistant_message );
				$this->assertIsArray( $assistant_message['content'] );
				$this->assertEmpty( $assistant_message['content'] );

				$this->assertArrayHasKey( 'tool_calls', $assistant_message );
				$this->assertIsArray( $assistant_message['tool_calls'] );
				$this->assertCount( 1, $assistant_message['tool_calls'] );

				$tool_call = $assistant_message['tool_calls'][0];
				$this->assertSame( 'call_123', $tool_call['id'] );
				$this->assertSame( 'function', $tool_call['type'] );
				$this->assertArrayHasKey( 'function', $tool_call );
				$this->assertSame( 'fetch_data', $tool_call['function']['name'] );
				$this->assertSame( '{"foo":"bar"}', $tool_call['function']['arguments'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure image attachments are transformed into attachment-backed segments.
	 */
	public function test_image_attachment_segment_is_prepared() {
		$assistant_id  = $this->create_assistant_post();
		$attachment_id = $this->create_image_attachment( 'vision.png' );

		$resolved_file_id = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Describe this image',
						),
						array(
							'type'          => 'input_image',
							'attachment_id' => $attachment_id,
							'detail'        => 'high',
						),
					),
				),
			),
			function ( $messages ) use ( &$resolved_file_id ) {
				$this->assertNotEmpty( $messages );
				$segments = $messages[0]['content'];

				$this->assertCount( 2, $segments );
				$this->assertSame( 'text', $segments[0]['type'] );
				$this->assertSame( 'Describe this image', $segments[0]['text'] );

				$image_segment = $segments[1];
				$this->assertSame( 'input_image', $image_segment['type'] );
				$this->assertArrayHasKey( 'file_id', $image_segment );
				$resolved_file_id = $image_segment['file_id'];
				$this->assertArrayNotHasKey( 'image', $image_segment );
				$this->assertStringStartsWith( 'file-test-', $resolved_file_id );
				$this->assertSame( 'high', $image_segment['detail'] );

				// Verify that the image URL is included for OpenAI/Gemini compatibility.
				$this->assertArrayHasKey( 'image_url', $image_segment );
				$this->assertIsArray( $image_segment['image_url'] );
				$this->assertArrayHasKey( 'url', $image_segment['image_url'] );
				$this->assertNotEmpty( $image_segment['image_url']['url'] );

				return true;
			},
			function ( $options ) use ( &$resolved_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );
				$attachment = $options['attachments'][0];

				$this->assertSame( $resolved_file_id, $attachment['id'] );
				$this->assertSame( $resolved_file_id, $attachment['file_id'] );
				$this->assertSame( 'image/png', $attachment['mime_type'] );
				$this->assertArrayNotHasKey( 'data', $attachment );

				return true;
			}
		);
	}

	/**
	 * Ensure attachment segments provided as single objects are normalised.
	 */
	public function test_single_object_attachment_segment_is_normalised() {
		$assistant_id     = $this->create_assistant_post();
		$attachment_id    = $this->create_image_attachment( 'normalise.png' );
		$resolved_file_id = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						'type'          => 'input_image',
						'attachment_id' => $attachment_id,
						'detail'        => 'low',
					),
				),
			),
			function ( $messages ) use ( &$resolved_file_id ) {
				$this->assertCount( 1, $messages );

				$first = $messages[0];
				$this->assertSame( 'user', $first['role'] );
				$this->assertArrayHasKey( 'content', $first );
				$this->assertCount( 1, $first['content'] );

				$segment = $first['content'][0];
				$this->assertSame( 'input_image', $segment['type'] );
				$this->assertArrayHasKey( 'file_id', $segment );
				$resolved_file_id = $segment['file_id'];
				$this->assertArrayNotHasKey( 'image', $segment );
				$this->assertStringStartsWith( 'file-test-', $resolved_file_id );
				$this->assertSame( 'low', $segment['detail'] );

				// Verify that the image URL is included for OpenAI/Gemini compatibility.
				$this->assertArrayHasKey( 'image_url', $segment );
				$this->assertIsArray( $segment['image_url'] );
				$this->assertArrayHasKey( 'url', $segment['image_url'] );
				$this->assertNotEmpty( $segment['image_url']['url'] );

				return true;
			},
			function ( $options ) use ( &$resolved_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertCount( 1, $options['attachments'] );

				$attachment = $options['attachments'][0];
				$this->assertSame( $resolved_file_id, $attachment['id'] );
				$this->assertSame( $resolved_file_id, $attachment['file_id'] );
				$this->assertSame( 'image/png', $attachment['mime_type'] );

				return true;
			}
		);
	}

	/**
	 * Ensure file attachments are converted into file segments with attachment payloads.
	 */
	public function test_file_attachment_segment_is_prepared() {
		$assistant_id     = $this->create_assistant_post();
		$attachment_id    = $this->create_text_attachment( 'notes.txt', 'Important notes.' );
		$resolved_file_id = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_file',
							'attachment_id' => $attachment_id,
						),
					),
				),
			),
			function ( $messages ) use ( &$resolved_file_id ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_file', $segment['type'] );
				$resolved_file_id = $segment['file_id'];
				$this->assertStringStartsWith( 'file-test-', $resolved_file_id );

				return true;
			},
			function ( $options ) use ( &$resolved_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );

				$attachment = $options['attachments'][0];
				$this->assertSame( $resolved_file_id, $attachment['id'] );
				$this->assertSame( $resolved_file_id, $attachment['file_id'] );
				$this->assertSame( 'text/plain', $attachment['mime_type'] );
				$this->assertArrayNotHasKey( 'data', $attachment );

				return true;
			}
		);
	}

	/**
	 * Ensure zero-byte file attachments are accepted when readable.
	 */
	public function test_zero_byte_file_attachment_is_allowed() {
		$assistant_id     = $this->create_assistant_post();
		$attachment_id    = $this->create_text_attachment( 'empty.txt', '' );
		$resolved_file_id = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_file',
							'attachment_id' => $attachment_id,
						),
					),
				),
			),
			function ( $messages ) use ( &$resolved_file_id ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_file', $segment['type'] );
				$resolved_file_id = $segment['file_id'];
				$this->assertStringStartsWith( 'file-test-', $resolved_file_id );

				return true;
			},
			function ( $options ) use ( &$resolved_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );

				$attachment = $options['attachments'][0];
				$this->assertSame( $resolved_file_id, $attachment['id'] );
				$this->assertSame( $resolved_file_id, $attachment['file_id'] );
				$this->assertSame( 'text/plain', $attachment['mime_type'] );
				$this->assertSame( 0, $attachment['bytes'] );
				$this->assertArrayNotHasKey( 'data', $attachment );

				return true;
			}
		);
	}

	/**
	 * Ensure attachments embedded throughout a conversation are preserved.
	 */
	public function test_conversation_with_multiple_attachments_is_normalised() {
		$assistant_id        = $this->create_assistant_post();
		$image_attachment_id = $this->create_image_attachment( 'scene.png' );
		$file_attachment_id  = $this->create_text_attachment( 'outline.txt', 'Outline details.' );

		$image_file_id = null;
		$file_file_id  = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Please review these assets.',
						),
						array(
							'type'          => 'input_image',
							'attachment_id' => $image_attachment_id,
							'detail'        => 'low',
							'caption'       => '  Scene reference  ',
						),
					),
				),
				array(
					'role'    => 'assistant',
					'content' => 'Thanks, taking a look now.',
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_file',
							'attachment_id' => $file_attachment_id,
							'display_name'  => '  Outline draft  ',
						),
					),
				),
			),
			function ( $messages ) use ( &$image_file_id, &$file_file_id ) {
				$this->assertCount( 3, $messages );

				$first_message = $messages[0];
				$this->assertSame( 'user', $first_message['role'] );
				$this->assertCount( 2, $first_message['content'] );
				$this->assertSame( 'text', $first_message['content'][0]['type'] );
				$this->assertSame( 'Please review these assets.', $first_message['content'][0]['text'] );

				$image_segment = $first_message['content'][1];
				$this->assertSame( 'input_image', $image_segment['type'] );
				$this->assertArrayHasKey( 'file_id', $image_segment );
				$image_file_id = $image_segment['file_id'];
				$this->assertArrayNotHasKey( 'image', $image_segment );
				$this->assertStringStartsWith( 'file-test-', $image_file_id );
				$this->assertSame( 'Scene reference', $image_segment['caption'] );
				$this->assertSame( 'low', $image_segment['detail'] );

				// Verify that the image URL is included for OpenAI/Gemini compatibility.
				$this->assertArrayHasKey( 'image_url', $image_segment );
				$this->assertIsArray( $image_segment['image_url'] );
				$this->assertArrayHasKey( 'url', $image_segment['image_url'] );
				$this->assertNotEmpty( $image_segment['image_url']['url'] );

				$second_message = $messages[1];
				$this->assertSame( 'assistant', $second_message['role'] );
				$this->assertCount( 1, $second_message['content'] );
				$this->assertSame( 'text', $second_message['content'][0]['type'] );
				$this->assertSame( 'Thanks, taking a look now.', $second_message['content'][0]['text'] );

				$third_message = $messages[2];
				$this->assertSame( 'user', $third_message['role'] );
				$this->assertCount( 1, $third_message['content'] );
				$this->assertSame( 'input_file', $third_message['content'][0]['type'] );
				$file_file_id = $third_message['content'][0]['file_id'];
				$this->assertStringStartsWith( 'file-test-', $file_file_id );
				$this->assertSame( 'Outline draft', $third_message['content'][0]['display_name'] );

				return true;
			},
			function ( $options ) use ( &$image_file_id, &$file_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertCount( 2, $options['attachments'] );

				$image_attachment = $options['attachments'][0];
				$this->assertSame( $image_file_id, $image_attachment['id'] );
				$this->assertSame( $image_file_id, $image_attachment['file_id'] );
				$this->assertSame( 'image/png', $image_attachment['mime_type'] );
				$this->assertArrayNotHasKey( 'data', $image_attachment );

				$file_attachment = $options['attachments'][1];
				$this->assertSame( $file_file_id, $file_attachment['id'] );
				$this->assertSame( $file_file_id, $file_attachment['file_id'] );
				$this->assertSame( 'text/plain', $file_attachment['mime_type'] );
				$this->assertArrayNotHasKey( 'data', $file_attachment );

				return true;
			}
		);
	}

	/**
	 * Ensure multiple image attachments in a single message are processed with URLs.
	 */
	public function test_multiple_image_attachments_in_single_message() {
		$assistant_id         = $this->create_assistant_post();
		$image_attachment_id1 = $this->create_image_attachment( 'photo1.png' );
		$image_attachment_id2 = $this->create_image_attachment( 'photo2.png' );
		$image_attachment_id3 = $this->create_image_attachment( 'photo3.png' );

		$image_file_id1 = null;
		$image_file_id2 = null;
		$image_file_id3 = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Compare these three images.',
						),
						array(
							'type'          => 'input_image',
							'attachment_id' => $image_attachment_id1,
							'detail'        => 'high',
						),
						array(
							'type'          => 'input_image',
							'attachment_id' => $image_attachment_id2,
							'detail'        => 'auto',
						),
						array(
							'type'          => 'input_image',
							'attachment_id' => $image_attachment_id3,
							'detail'        => 'low',
						),
					),
				),
			),
			function ( $messages ) use ( &$image_file_id1, &$image_file_id2, &$image_file_id3 ) {
				$this->assertCount( 1, $messages );

				$first_message = $messages[0];
				$this->assertSame( 'user', $first_message['role'] );
				$this->assertCount( 4, $first_message['content'] );

				// First segment is text.
				$this->assertSame( 'text', $first_message['content'][0]['type'] );
				$this->assertSame( 'Compare these three images.', $first_message['content'][0]['text'] );

				// Second segment is first image.
				$image_segment1 = $first_message['content'][1];
				$this->assertSame( 'input_image', $image_segment1['type'] );
				$this->assertArrayHasKey( 'file_id', $image_segment1 );
				$image_file_id1 = $image_segment1['file_id'];
				$this->assertStringStartsWith( 'file-test-', $image_file_id1 );
				$this->assertSame( 'high', $image_segment1['detail'] );
				$this->assertArrayHasKey( 'image_url', $image_segment1 );
				$this->assertIsArray( $image_segment1['image_url'] );
				$this->assertArrayHasKey( 'url', $image_segment1['image_url'] );
				$this->assertNotEmpty( $image_segment1['image_url']['url'] );

				// Third segment is second image.
				$image_segment2 = $first_message['content'][2];
				$this->assertSame( 'input_image', $image_segment2['type'] );
				$this->assertArrayHasKey( 'file_id', $image_segment2 );
				$image_file_id2 = $image_segment2['file_id'];
				$this->assertStringStartsWith( 'file-test-', $image_file_id2 );
				$this->assertSame( 'auto', $image_segment2['detail'] );
				$this->assertArrayHasKey( 'image_url', $image_segment2 );
				$this->assertIsArray( $image_segment2['image_url'] );
				$this->assertArrayHasKey( 'url', $image_segment2['image_url'] );
				$this->assertNotEmpty( $image_segment2['image_url']['url'] );

				// Fourth segment is third image.
				$image_segment3 = $first_message['content'][3];
				$this->assertSame( 'input_image', $image_segment3['type'] );
				$this->assertArrayHasKey( 'file_id', $image_segment3 );
				$image_file_id3 = $image_segment3['file_id'];
				$this->assertStringStartsWith( 'file-test-', $image_file_id3 );
				$this->assertSame( 'low', $image_segment3['detail'] );
				$this->assertArrayHasKey( 'image_url', $image_segment3 );
				$this->assertIsArray( $image_segment3['image_url'] );
				$this->assertArrayHasKey( 'url', $image_segment3['image_url'] );
				$this->assertNotEmpty( $image_segment3['image_url']['url'] );

				// Verify all three file IDs are unique.
				$this->assertNotEquals( $image_file_id1, $image_file_id2 );
				$this->assertNotEquals( $image_file_id1, $image_file_id3 );
				$this->assertNotEquals( $image_file_id2, $image_file_id3 );

				return true;
			},
			function ( $options ) use ( &$image_file_id1, &$image_file_id2, &$image_file_id3 ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertCount( 3, $options['attachments'] );

				// Verify first image attachment.
				$attachment1 = $options['attachments'][0];
				$this->assertSame( $image_file_id1, $attachment1['id'] );
				$this->assertSame( $image_file_id1, $attachment1['file_id'] );
				$this->assertSame( 'image/png', $attachment1['mime_type'] );

				// Verify second image attachment.
				$attachment2 = $options['attachments'][1];
				$this->assertSame( $image_file_id2, $attachment2['id'] );
				$this->assertSame( $image_file_id2, $attachment2['file_id'] );
				$this->assertSame( 'image/png', $attachment2['mime_type'] );

				// Verify third image attachment.
				$attachment3 = $options['attachments'][2];
				$this->assertSame( $image_file_id3, $attachment3['id'] );
				$this->assertSame( $image_file_id3, $attachment3['file_id'] );
				$this->assertSame( 'image/png', $attachment3['mime_type'] );

				return true;
			}
		);
	}

	/**
	 * Ensure DOCX file attachments are accepted and forwarded to the model.
	 */
	public function test_docx_file_attachment_is_prepared() {
		$assistant_id = $this->create_assistant_post();

		list( $attachment_id, $file_path ) = $this->create_docx_attachment( 'notes.docx', "Important\nDOCX notes." );

		$resolved_file_id = null;

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_file',
							'attachment_id' => $attachment_id,
						),
					),
				),
			),
			function ( $messages ) use ( &$resolved_file_id ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_file', $segment['type'] );
				$resolved_file_id = $segment['file_id'];
				$this->assertStringStartsWith( 'file-test-', $resolved_file_id );

				return true;
			},
			function ( $options ) use ( &$resolved_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );

				$attachment = $options['attachments'][0];
				$this->assertSame( $resolved_file_id, $attachment['id'] );
				$this->assertSame( $resolved_file_id, $attachment['file_id'] );
				$this->assertSame( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $attachment['mime_type'] );
				$this->assertArrayNotHasKey( 'data', $attachment );

				return true;
			}
		);
	}

	/**
	 * Ensure previously referenced file segments can be resubmitted without attachment payloads.
	 */
	public function test_existing_file_segment_is_preserved_without_attachment_payload() {
		$assistant_id = $this->create_assistant_post();

		$attachment_id = $this->create_text_attachment( 'existing-notes.txt', 'Existing notes' );
		$this->override_attachment_file_id( $attachment_id, 'file-123' );

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'         => 'input_file',
							'file_id'      => 'file-123',
							'display_name' => 'Existing notes',
						),
					),
				),
			),
			function ( $messages ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_file', $segment['type'] );
				$this->assertSame( 'file-123', $segment['file_id'] );
				$this->assertSame( 'Existing notes', $segment['display_name'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure previously referenced image segments can be resubmitted without attachment payloads.
	 */
	public function test_existing_image_segment_is_preserved_without_attachment_payload() {
		$assistant_id = $this->create_assistant_post();

		$attachment_id = $this->create_image_attachment( 'existing-upload.png' );
		$this->override_attachment_file_id( $attachment_id, 'file-456' );

		$this->dispatch_chat_request(
			$assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'    => 'input_image',
							'file_id' => 'file-456',
							'caption' => 'Prior upload',
							'detail'  => 'high',
						),
					),
				),
			),
			function ( $messages ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_image', $segment['type'] );
				$this->assertArrayHasKey( 'file_id', $segment );
				$this->assertSame( 'file-456', $segment['file_id'] );
				$this->assertArrayNotHasKey( 'image', $segment );
				$this->assertSame( 'Prior upload', $segment['caption'] );
				$this->assertSame( 'high', $segment['detail'] );

				return true;
			},
			function ( $options ) {
				$this->assertArrayNotHasKey( 'attachments', $options );

				return true;
			}
		);
	}

	/**
	 * Ensure existing file identifiers must map to accessible attachments.
	 */
	public function test_existing_file_segment_rejects_unknown_file_id() {
		$assistant_id = $this->create_assistant_post();

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'    => 'input_file',
							'file_id' => 'file-missing',
						),
					),
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'wp_mcp_ai_unknown_file_reference', $data['code'] );
	}

	/**
	 * Ensure an invalid message role triggers a REST error response.
	 */
	public function test_invalid_role_is_rejected() {
		$assistant_id = $this->create_assistant_post();
		$user_id      = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'moderator',
					'content' => 'Unsupported role',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'wp_mcp_ai_invalid_message_role', $data['code'] );
		$this->assertStringContainsString( 'moderator', $data['message'] );
	}

	/**
	 * Ensure custom roles can be permitted via the filter.
	 */
	public function test_custom_role_is_allowed_via_filter() {
		$assistant_id = $this->create_assistant_post();

		$filter = static function ( $roles ) {
			$roles[] = 'moderator';

			return $roles;
		};

		add_filter( 'wp_mcp_ai_allowed_message_roles', $filter );

		try {
			$this->dispatch_chat_request(
				$assistant_id,
				array(
					array(
						'role'    => 'moderator',
						'content' => 'Custom role payload',
					),
				),
				function ( $messages ) {
					$this->assertCount( 1, $messages );
					$this->assertSame( 'moderator', $messages[0]['role'] );
					$this->assertSame( 'text', $messages[0]['content'][0]['type'] );
					$this->assertSame( 'Custom role payload', $messages[0]['content'][0]['text'] );

					return true;
				},
				function ( $options ) {
					$this->assertArrayNotHasKey( 'attachments', $options );

					return true;
				}
			);
		} finally {
			remove_filter( 'wp_mcp_ai_allowed_message_roles', $filter );
		}
	}

	/**
	 * Ensure attachment uploads reuse cached OpenAI file metadata when unchanged.
	 */
	public function test_attachment_upload_reuses_cached_openai_file_metadata() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$attachment_id = $this->create_text_attachment( 'notes.txt', 'Attachment contents' );

		$upload_counter = 0;
		$upload_filter  = function ( $preempt, $args, $url ) use ( &$upload_counter ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT !== $url ) {
				return false;
			}

			++$upload_counter;

			$response_body = array(
				'id'         => 'file-reuse-' . $upload_counter,
				'created_at' => time(),
				'status'     => 'processed',
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $response_body ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $upload_filter, 10, 3 );

		try {
			$first_helper  = new WP_MCP_AI_Message_Attachments();
			$first_segment = $first_helper->prepare_input_file_segment(
				array(
					'attachment_id' => $attachment_id,
				)
			);

			$this->assertIsArray( $first_segment );
			$this->assertArrayHasKey( 'file_id', $first_segment );

			$second_helper  = new WP_MCP_AI_Message_Attachments();
			$second_segment = $second_helper->prepare_input_file_segment(
				array(
					'attachment_id' => $attachment_id,
				)
			);

			$this->assertIsArray( $second_segment );
			$this->assertArrayHasKey( 'file_id', $second_segment );
			$this->assertSame( $first_segment['file_id'], $second_segment['file_id'] );
		} finally {
			remove_filter( 'pre_http_request', $upload_filter, 10 );
			delete_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY );
		}

		$this->assertSame( 1, $upload_counter );
	}

	/**
	 * Ensure the file download REST route proxies OpenAI content responses.
	 */
	public function test_file_download_route_streams_openai_file() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$assistant_id = $this->create_assistant_post();
		$user_id      = get_current_user_id();

		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$existing_keys = array();
		if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
			$existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[999] ?? array() );
		}

		$filter_callback = function ( $preempt, $args, $url ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/file-download-456/content' !== $url ) {
				return null;
			}

			return array(
				'headers'  => array(
					'Content-Type' => 'text/plain',
				),
				'body'     => 'Example content',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$attachment_id = $this->create_text_attachment( 'download.txt', 'Attachment contents' );
		$metadata      = get_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, true );
		$this->assertIsArray( $metadata );
		$metadata['file_id']   = 'file-download-456';
		$metadata['filename']  = 'download.txt';
		$metadata['mime_type'] = 'text/plain';
		update_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, $metadata );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/files/file-download-456/download' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
			? $GLOBALS['wp_filter']['rest_pre_serve_request']
			: null;

		$this->assertInstanceOf( WP_Hook::class, $hook );

		$current_keys = array_keys( $hook->callbacks[999] ?? array() );
		$added_keys   = array_diff( $current_keys, $existing_keys );

		$this->assertNotEmpty( $added_keys );

		$closure_key = array_pop( $added_keys );
		$closure     = $hook->callbacks[999][ $closure_key ]['function'];

		ob_start();
		$served = call_user_func( $closure, false, $response, $request, rest_get_server() );
		$output = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( 'Example content', $output );

		unset( $hook->callbacks[999][ $closure_key ] );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure assistant responses that reference OpenAI files are downloaded to the Media Library.
	 */
	public function test_response_files_are_downloaded_to_media() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		WP_MCP_AI_Response_Attachments::init();

		$binary_payload = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2b4lUAAAAASUVORK5CYII=' );

		$download_filter = function ( $preempt, $args, $url ) use ( $binary_payload ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/file-response-123/content' !== $url ) {
				return null;
			}

			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => $binary_payload,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $download_filter, 10, 3 );

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => array(
							array(
								'type'       => 'output_image',
								'file_id'    => 'file-response-123',
								'image_file' => array(
									'file_id'  => 'file-response-123',
									'filename' => 'response.png',
								),
							),
						),
					),
				),
			),
		);

		try {
			do_action( 'wp_mcp_ai_after_chat_response', 123, $response, $request );
		} finally {
			remove_filter( 'pre_http_request', $download_filter, 10 );
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();
		$attachment_id      = $attachments_helper->get_attachment_id_for_openai_file( 'file-response-123' );

		$this->assertNotEmpty( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );

		$metadata = get_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, true );
		$this->assertIsArray( $metadata );
		$this->assertSame( 'file-response-123', $metadata['file_id'] );

		$file_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $file_path );
		$this->assertSame( $binary_payload, file_get_contents( $file_path ) );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Ensure downloads fail when the OpenAI file is not associated with a local attachment.
	 */
	public function test_file_download_route_requires_local_attachment() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$assistant_id = $this->create_assistant_post();
		$user_id      = get_current_user_id();

		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$download_counter = 0;
		$filter_callback  = function ( $preempt, $args, $url ) use ( &$download_counter ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/file-missing-123/content' === $url ) {
				++$download_counter;
			}

			return null;
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/files/file-missing-123/download' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'wp_mcp_ai_file_download_not_found', $data['code'] );
		$this->assertSame( 0, $download_counter );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure downloads honour attachment access permissions.
	 */
	public function test_file_download_route_respects_attachment_permissions() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$assistant_id = $this->create_assistant_post();

		$admin_id = get_current_user_id();
		if ( ! $admin_id ) {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		}

		wp_set_current_user( $admin_id );

		$attachment_id = $this->create_text_attachment( 'private-download.txt', 'Private attachment contents' );
		$metadata      = get_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, true );
		$this->assertIsArray( $metadata );
		$metadata['file_id']   = 'file-private-789';
		$metadata['filename']  = 'private-download.txt';
		$metadata['mime_type'] = 'text/plain';
		update_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, $metadata );
		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_status' => 'private',
			)
		);

		$subscriber_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$capability_filter = function ( $capability, $assistant, $context ) {
			return 'read';
		};

		add_filter( 'wp_mcp_ai_chat_capability', $capability_filter, 10, 3 );

		wp_set_current_user( $subscriber_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$download_counter = 0;
		$filter_callback  = function ( $preempt, $args, $url ) use ( &$download_counter ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/file-private-789/content' === $url ) {
				++$download_counter;
			}

			return null;
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/files/file-private-789/download' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', $filter_callback, 10 );
		remove_filter( 'wp_mcp_ai_chat_capability', $capability_filter, 10 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'wp_mcp_ai_file_download_forbidden', $data['code'] );
		$this->assertSame( 0, $download_counter );

		wp_set_current_user( 0 );
	}

	/**
	 * Ensure OpenAI files are deleted when attachment metadata is removed.
	 */
	public function test_openai_file_deleted_when_attachment_metadata_removed() {
		WP_MCP_AI_Message_Attachments::init();
		WP_MCP_AI_Message_Attachments::reset_deleted_file_cache();

		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$attachment_id = $this->create_text_attachment( 'notes-cleanup.txt', 'Cleanup contents' );

		$upload_filter = function ( $preempt, $args, $url ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT !== $url ) {
				return false;
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'         => 'file-cleanup-1',
						'created_at' => time(),
						'status'     => 'processed',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $upload_filter, 10, 3 );

		try {
			$helper  = new WP_MCP_AI_Message_Attachments();
			$segment = $helper->prepare_input_file_segment(
				array(
					'attachment_id' => $attachment_id,
				)
			);

			$this->assertIsArray( $segment );
			$this->assertArrayHasKey( 'file_id', $segment );
		} finally {
			remove_filter( 'pre_http_request', $upload_filter, 10 );
		}

		$metadata = get_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, true );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'file_id', $metadata );
		$this->assertNotEmpty( $metadata['file_id'] );

		$expected_file_id = $metadata['file_id'];
		$delete_triggered = false;

		$delete_filter = function ( $preempt, $args, $url ) use ( &$delete_triggered, $expected_file_id ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/' . $expected_file_id !== $url ) {
				return false;
			}

			$delete_triggered = true;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => $expected_file_id,
						'deleted' => true,
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $delete_filter, 10, 3 );

		try {
			delete_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, $metadata );
		} finally {
			remove_filter( 'pre_http_request', $delete_filter, 10 );
			WP_MCP_AI_Message_Attachments::reset_deleted_file_cache();
		}

		$this->assertTrue( $delete_triggered );
		$this->assertSame( '', get_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, true ) );
	}

	/**
	 * Dispatch the REST request and apply expectations against the payload.
	 *
	 * @param int      $assistant_id Assistant post ID.
	 * @param array    $messages     Message payload.
	 * @param callable $message_assertion Callback that inspects messages.
	 * @param callable $options_assertion Callback that inspects options.
	 * @param array    $extra_params Optional additional parameters to include with the request.
	 */
	protected function dispatch_chat_request( $assistant_id, array $messages, callable $message_assertion, callable $options_assertion, array $extra_params = array() ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$upload_counter = 0;
		$upload_filter  = function ( $preempt, $args, $url ) use ( &$upload_counter ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT !== $url ) {
				return false;
			}

			++$upload_counter;

			$response_body = array(
				'id'         => 'file-test-' . $upload_counter,
				'created_at' => time(),
				'status'     => 'processed',
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $response_body ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $upload_filter, 10, 3 );

		$options_callback = function ( $options ) use ( $options_assertion ) {
			$this->assertArrayHasKey( 'provider', $options );
			$this->assertSame( 'openai', $options['provider'] );

			return call_user_func( $options_assertion, $options );
		};

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->callback( $message_assertion ),
				$this->callback( $options_callback )
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( empty( $settings['openai_api_key'] ) ) {
			$settings['openai_api_key'] = 'sk-test';
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		$http_stub = function ( $preempt, $args, $url ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT === $url ) {
				return array(
					'body'     => wp_json_encode(
						array(
							'id'         => 'file-test',
							'created_at' => time(),
							'status'     => 'processed',
						)
					),
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'messages', $messages );

		foreach ( $extra_params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		try {
			$response = rest_get_server()->dispatch( $request );
		} finally {
			remove_filter( 'pre_http_request', $upload_filter, 10 );
		}

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Prepare the REST controller instance for testing.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mocked language model router.
	 */
	protected function bootstrap_rest_controller( WP_MCP_AI_Language_Model_Router $mock_client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a published assistant post for testing.
	 *
	 * @return int
	 */
	protected function create_assistant_post() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}

	/**
	 * Create an image attachment for testing.
	 *
	 * @param string $filename File name.
	 * @return int
	 */
	protected function create_image_attachment( $filename ) {
		$binary = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='
		);

		$upload = wp_upload_bits( $filename, null, $binary );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'Vision Image',
				'post_status' => 'inherit',
			)
		);

		$this->prime_attachment_openai_metadata( $attachment_id, $upload['file'], 'image/png' );

		return $attachment_id;
	}

	/**
	 * Override the cached OpenAI file identifier for an attachment.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $file_id       Desired OpenAI file identifier.
	 */
	protected function override_attachment_file_id( $attachment_id, $file_id ) {
		$metadata = get_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, true );

		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$metadata['file_id'] = $file_id;

		if ( empty( $metadata['mime_type'] ) ) {
			$metadata['mime_type'] = get_post_mime_type( $attachment_id );
		}

		if ( empty( $metadata['filename'] ) ) {
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path ) {
				$metadata['filename'] = wp_basename( $file_path );
				$metadata['bytes']    = (int) filesize( $file_path );
			}
		}

		if ( empty( $metadata['status'] ) ) {
			$metadata['status'] = 'processed';
		}

		update_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, $metadata );
	}

	/**
	 * Create a text attachment.
	 *
	 * @param string $filename File name.
	 * @param string $contents File contents.
	 * @return int
	 */
	protected function create_text_attachment( $filename, $contents ) {
		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'Notes Document',
				'post_status' => 'inherit',
			)
		);

		$this->prime_attachment_openai_metadata( $attachment_id, $upload['file'], 'text/plain' );

		return $attachment_id;
	}

	/**
	 * Create a DOCX attachment for testing.
	 *
	 * @param string $filename File name.
	 * @param string $text     Text content.
	 * @return array
	 */
	protected function create_docx_attachment( $filename, $text ) {
		$upload = $this->create_docx_upload( $filename, $text );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'DOCX Document',
				'post_status' => 'inherit',
			)
		);

		$this->prime_attachment_openai_metadata(
			$attachment_id,
			$upload['file'],
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
		);

		return array( $attachment_id, $upload['file'] );
	}

	/**
	 * Prime cached OpenAI metadata for an attachment to avoid outbound requests during tests.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $file_path     Absolute path to the file on disk.
	 * @param string $mime_type     MIME type for the attachment.
	 */
	protected function prime_attachment_openai_metadata( $attachment_id, $file_path, $mime_type ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return;
		}

		$bytes    = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;
		$hash     = is_readable( $file_path ) ? md5_file( $file_path ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5_file
		$modified = file_exists( $file_path ) ? (int) filemtime( $file_path ) : time();
		$data     = is_readable( $file_path ) ? base64_encode( file_get_contents( $file_path ) ) : '';

		$metadata = array(
			'file_id'    => 'wp-attachment-' . $attachment_id,
			'filename'   => wp_basename( $file_path ),
			'mime_type'  => $mime_type,
			'bytes'      => $bytes,
			'hash'       => $hash,
			'modified'   => $modified,
			'purpose'    => 'assistants',
			'status'     => 'processed',
			'created_at' => time(),
		);

		$metadata['data'] = $data;

		update_post_meta( $attachment_id, WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY, $metadata );
	}
}
