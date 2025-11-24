<?php
/**
 * Tests for REST chat message attachment handling.
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

		$expected_file_id = 'wp-attachment-' . $attachment_id;

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
			function ( $messages ) use ( $expected_file_id ) {
				$this->assertNotEmpty( $messages );
				$segments = $messages[0]['content'];

				$this->assertCount( 2, $segments );
				$this->assertSame( 'text', $segments[0]['type'] );
				$this->assertSame( 'Describe this image', $segments[0]['text'] );

				$image_segment = $segments[1];
				$this->assertSame( 'input_image', $image_segment['type'] );
				$this->assertSame( $expected_file_id, $image_segment['image_file']['file_id'] );
				$this->assertSame( 'high', $image_segment['detail'] );

				return true;
			},
			function ( $options ) use ( $expected_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );
				$attachment = $options['attachments'][0];

				$this->assertSame( $expected_file_id, $attachment['id'] );
				$this->assertSame( 'image/png', $attachment['mime_type'] );
				$this->assertNotEmpty( $attachment['data'] );

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
		$expected_file_id = 'wp-attachment-' . $attachment_id;

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
			function ( $messages ) use ( $expected_file_id ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_file', $segment['type'] );
				$this->assertSame( $expected_file_id, $segment['file_id'] );

				return true;
			},
			function ( $options ) use ( $expected_file_id ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );

				$attachment = $options['attachments'][0];
				$this->assertSame( $expected_file_id, $attachment['id'] );
				$this->assertSame( 'text/plain', $attachment['mime_type'] );
				$this->assertSame( base64_encode( 'Important notes.' ), $attachment['data'] );

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

		$expected_file_id = 'wp-attachment-' . $attachment_id;
		$expected_base64  = base64_encode( (string) file_get_contents( $file_path ) );

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
			function ( $messages ) use ( $expected_file_id ) {
				$this->assertNotEmpty( $messages );

				$segment = $messages[0]['content'][0];
				$this->assertSame( 'input_file', $segment['type'] );
				$this->assertSame( $expected_file_id, $segment['file_id'] );

				return true;
			},
			function ( $options ) use ( $expected_file_id, $expected_base64 ) {
				$this->assertArrayHasKey( 'attachments', $options );
				$this->assertNotEmpty( $options['attachments'] );

				$attachment = $options['attachments'][0];
				$this->assertSame( $expected_file_id, $attachment['id'] );
				$this->assertSame( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $attachment['mime_type'] );
				$this->assertSame( $expected_base64, $attachment['data'] );

				return true;
			}
		);
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
	 * Dispatch the REST request and apply expectations against the payload.
	 *
	 * @param int      $assistant_id Assistant post ID.
	 * @param array    $messages     Message payload.
	 * @param callable $message_assertion Callback that inspects messages.
	 * @param callable $options_assertion Callback that inspects options.
	 */
	protected function dispatch_chat_request( $assistant_id, array $messages, callable $message_assertion, callable $options_assertion ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

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

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'messages', $messages );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

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

		return $attachment_id;
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

		return array( $attachment_id, $upload['file'] );
	}
}
