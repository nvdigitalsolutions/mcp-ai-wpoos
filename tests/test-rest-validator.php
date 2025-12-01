<?php
/**
 * Tests for REST API Validator
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test REST API Validator functionality.
 *
 * @group rest
 * @group validator
 */
class Test_REST_Validator extends WP_UnitTestCase {

	/**
	 * Validator instance.
	 *
	 * @var WP_MCP_AI_REST_Validator
	 */
	protected $validator;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the validator class.
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$this->validator = new WP_MCP_AI_REST_Validator();
	}

	/**
	 * Test that validator instantiates correctly.
	 */
	public function test_validator_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Validator', $this->validator );
	}

	/**
	 * Test validate_messages_array with valid input.
	 */
	public function test_validate_messages_array_valid() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, world!',
			),
		);

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_messages_array( $messages, $request, 'messages' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_messages_array with empty array.
	 */
	public function test_validate_messages_array_empty() {
		$messages = array();

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_messages_array( $messages, $request, 'messages' );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_invalid_param', $result->get_error_code() );
	}

	/**
	 * Test validate_messages_array with non-array input.
	 */
	public function test_validate_messages_array_non_array() {
		$messages = 'not an array';

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_messages_array( $messages, $request, 'messages' );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_invalid_param', $result->get_error_code() );
	}

	/**
	 * Test validate_messages_array with missing role.
	 */
	public function test_validate_messages_array_missing_role() {
		$messages = array(
			array(
				'content' => 'Hello',
			),
		);

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_messages_array( $messages, $request, 'messages' );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_invalid_param', $result->get_error_code() );
	}

	/**
	 * Test validate_messages_array with invalid role.
	 */
	public function test_validate_messages_array_invalid_role() {
		$messages = array(
			array(
				'role'    => 'invalid',
				'content' => 'Hello',
			),
		);

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_messages_array( $messages, $request, 'messages' );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_invalid_param', $result->get_error_code() );
	}

	/**
	 * Test validate_attachments_array with valid input.
	 */
	public function test_validate_attachments_array_valid() {
		$attachments = array(
			array(
				'file_id' => 123,
			),
		);

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_attachments_array( $attachments, $request, 'attachments' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_attachments_array with valid URL.
	 */
	public function test_validate_attachments_array_valid_url() {
		$attachments = array(
			array(
				'url' => 'https://example.com/file.pdf',
			),
		);

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_attachments_array( $attachments, $request, 'attachments' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_attachments_array with missing file reference.
	 */
	public function test_validate_attachments_array_missing_reference() {
		$attachments = array(
			array(
				'name' => 'file.pdf',
			),
		);

		$request = new WP_REST_Request();
		$result  = $this->validator->validate_attachments_array( $attachments, $request, 'attachments' );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_invalid_param', $result->get_error_code() );
	}

	/**
	 * Test sanitize_messages with valid input.
	 */
	public function test_sanitize_messages_valid() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, world!',
			),
		);

		$result = $this->validator->sanitize_messages( $messages );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayHasKey( 'attachments', $result );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertEquals( 'user', $result['messages'][0]['role'] );
	}

	/**
	 * Test sanitize_messages with invalid role.
	 */
	public function test_sanitize_messages_invalid_role() {
		$messages = array(
			array(
				'role'    => 'invalid_role',
				'content' => 'Hello',
			),
		);

		$result = $this->validator->sanitize_messages( $messages );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_message_role', $result->get_error_code() );
	}

	/**
	 * Test sanitize_session_key_param.
	 */
	public function test_sanitize_session_key_param() {
		$result = $this->validator->sanitize_session_key_param( 'session_123' );
		$this->assertEquals( 'session_123', $result );

		$result = $this->validator->sanitize_session_key_param( 'session-with-dashes_123' );
		$this->assertEquals( 'session-with-dashes_123', $result );

		$result = $this->validator->sanitize_session_key_param( 'session!@#$%' );
		$this->assertEquals( 'session', $result );
	}

	/**
	 * Test sanitize_memory_files with valid input.
	 */
	public function test_sanitize_memory_files() {
		$files  = array( 1, 2, 3 );
		$result = $this->validator->sanitize_memory_files( $files );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertEquals( array( 1, 2, 3 ), $result );
	}

	/**
	 * Test sanitize_memory_files with array of arrays.
	 */
	public function test_sanitize_memory_files_array_of_arrays() {
		$files  = array(
			array( 'file_id' => 1 ),
			array( 'file_id' => 2 ),
		);
		$result = $this->validator->sanitize_memory_files( $files );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( array( 1, 2 ), $result );
	}

	/**
	 * Test sanitize_memory_files removes duplicates.
	 */
	public function test_sanitize_memory_files_removes_duplicates() {
		$files  = array( 1, 2, 2, 3, 3, 3 );
		$result = $this->validator->sanitize_memory_files( $files );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
	}

	/**
	 * Test validate_mcp_params with tools/call method.
	 */
	public function test_validate_mcp_params_tools_call() {
		$params = array(
			'name'      => 'my_tool',
			'arguments' => array( 'arg1' => 'value1' ),
		);

		$request = new WP_REST_Request();
		$request->set_param( 'method', 'tools/call' );

		$result = $this->validator->validate_mcp_params( $params, $request, 'params' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_mcp_params with tools/call missing name.
	 */
	public function test_validate_mcp_params_tools_call_missing_name() {
		$params = array(
			'arguments' => array( 'arg1' => 'value1' ),
		);

		$request = new WP_REST_Request();
		$request->set_param( 'method', 'tools/call' );

		$result = $this->validator->validate_mcp_params( $params, $request, 'params' );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_invalid_param', $result->get_error_code() );
	}

	/**
	 * Test sanitize_options preserves LM Studio provider.
	 */
	public function test_sanitize_options_preserves_lm_studio_provider() {
		$options = array(
			'provider' => 'lm_studio',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'lm_studio', $result['provider'], 'LM Studio provider should be preserved' );
	}

	/**
	 * Test sanitize_options preserves Ollama provider.
	 */
	public function test_sanitize_options_preserves_ollama_provider() {
		$options = array(
			'provider' => 'ollama',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'ollama', $result['provider'], 'Ollama provider should be preserved' );
	}

	/**
	 * Test sanitize_options preserves Anthropic provider.
	 */
	public function test_sanitize_options_preserves_anthropic_provider() {
		$options = array(
			'provider' => 'anthropic',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'anthropic', $result['provider'], 'Anthropic provider should be preserved' );
	}

	/**
	 * Test sanitize_options preserves OpenAI provider.
	 */
	public function test_sanitize_options_preserves_openai_provider() {
		$options = array(
			'provider' => 'openai',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'openai', $result['provider'], 'OpenAI provider should be preserved' );
	}

	/**
	 * Test sanitize_options preserves Gemini provider.
	 */
	public function test_sanitize_options_preserves_gemini_provider() {
		$options = array(
			'provider' => 'gemini',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'gemini', $result['provider'], 'Gemini provider should be preserved' );
	}

	/**
	 * Test sanitize_options uses assistant config provider when not in options.
	 */
	public function test_sanitize_options_uses_assistant_config_provider() {
		$options = array();

		$assistant_config = array(
			'provider' => 'lm_studio',
		);

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'lm_studio', $result['provider'], 'Provider should come from assistant config' );
	}

	/**
	 * Test sanitize_options rejects invalid provider and defaults to openai.
	 */
	public function test_sanitize_options_rejects_invalid_provider() {
		$options = array(
			'provider' => 'invalid_provider',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'openai', $result['provider'], 'Invalid provider should default to openai' );
	}

	/**
	 * Test sanitize_options prioritizes request provider over assistant config.
	 */
	public function test_sanitize_options_prioritizes_request_provider() {
		$options = array(
			'provider' => 'ollama',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertEquals( 'ollama', $result['provider'], 'Request provider should override assistant config' );
	}

	/**
	 * Test sanitize_options removes stream parameter.
	 *
	 * The 'stream' parameter is only used by the SSE handler to determine response
	 * format (SSE vs JSON), and should not be passed to AI provider clients which
	 * manage their own streaming behavior. This test verifies that the stream
	 * parameter is filtered out to prevent it from being sent to providers like
	 * LM Studio which explicitly disable streaming.
	 */
	public function test_sanitize_options_removes_stream_parameter() {
		$options = array(
			'provider' => 'lm_studio',
			'stream'   => true,
			'model'    => 'test-model',
		);

		$assistant_config = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayNotHasKey( 'stream', $result, 'Stream parameter should be removed from sanitized options' );
		$this->assertEquals( 'lm_studio', $result['provider'], 'Provider should be preserved' );
		$this->assertEquals( 'test-model', $result['model'], 'Other options should be preserved' );
	}

	/**
	 * Test that input_image segments are processed by sanitize_messages.
	 *
	 * This test ensures that chat-client attachments sent as input_image segments
	 * with attachment_id are properly handled by the validator.
	 */
	public function test_sanitize_messages_processes_input_image_segments() {
		// Create a test image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object(
			WP_MCP_AI_PATH . 'tests/fixtures/sample-image.png'
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'What is in this image?',
					),
					array(
						'type'          => 'input_image',
						'attachment_id' => $attachment_id,
					),
				),
			),
		);

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';

		$result = $this->validator->sanitize_messages( $messages );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayHasKey( 'attachments', $result );

		$sanitized_messages = $result['messages'];
		$this->assertCount( 1, $sanitized_messages );

		$content = $sanitized_messages[0]['content'];
		$this->assertCount( 2, $content, 'Should have both text and image segments' );

		// Verify text segment.
		$this->assertEquals( 'text', $content[0]['type'] );
		$this->assertEquals( 'What is in this image?', $content[0]['text'] );

		// Verify input_image segment was processed.
		$this->assertEquals( 'input_image', $content[1]['type'] );
		$this->assertArrayHasKey( 'file_id', $content[1], 'input_image segment should have file_id after processing' );
		$this->assertArrayNotHasKey( 'attachment_id', $content[1], 'attachment_id should be resolved to file_id' );
	}

	/**
	 * Test that input_file segments are processed by sanitize_messages.
	 *
	 * This test ensures that chat-client file attachments sent as input_file segments
	 * with attachment_id are properly handled by the validator.
	 */
	public function test_sanitize_messages_processes_input_file_segments() {
		// Create a test file attachment.
		$attachment_id = $this->factory->attachment->create_upload_object(
			WP_MCP_AI_PATH . 'tests/fixtures/test.pdf'
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Please analyze this document.',
					),
					array(
						'type'          => 'input_file',
						'attachment_id' => $attachment_id,
						'display_name'  => 'test.pdf',
					),
				),
			),
		);

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';

		$result = $this->validator->sanitize_messages( $messages );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayHasKey( 'attachments', $result );

		$sanitized_messages = $result['messages'];
		$this->assertCount( 1, $sanitized_messages );

		$content = $sanitized_messages[0]['content'];
		$this->assertCount( 2, $content, 'Should have both text and file segments' );

		// Verify text segment.
		$this->assertEquals( 'text', $content[0]['type'] );
		$this->assertEquals( 'Please analyze this document.', $content[0]['text'] );

		// Verify input_file segment was processed.
		$this->assertEquals( 'input_file', $content[1]['type'] );
		$this->assertArrayHasKey( 'file_id', $content[1], 'input_file segment should have file_id after processing' );
		$this->assertArrayNotHasKey( 'attachment_id', $content[1], 'attachment_id should be resolved to file_id' );
	}
}
