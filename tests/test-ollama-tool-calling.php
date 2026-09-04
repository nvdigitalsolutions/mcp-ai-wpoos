<?php
/**
 * Tests for Ollama tool-calling support.
 *
 * Verifies that the Ollama client correctly:
 *  - Serializes tools/tool_choice/parallel_tool_calls in the request payload.
 *  - Preserves assistant tool_calls and tool-role messages when replaying history.
 *  - Converts Ollama-native tool_calls (object arguments) ↔ OpenAI format (JSON-string arguments).
 *  - Returns finish_reason=tool_calls when the model calls a tool.
 *  - Accumulates tool_calls across streaming chunks.
 *  - Honors structured-output (format) and think options.
 *  - Routes to /v1/chat/completions when OpenAI-compatible mode is on.
 *  - Exposes model-capability helpers (supports_tools, supports_vision, supports_thinking).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Ollama tool-calling functionality.
 */
class Test_Ollama_Tool_Calling extends WP_UnitTestCase {

	/**
	 * Ollama client under test.
	 *
	 * @var WP_MCP_AI_Ollama_Client
	 */
	private $client;

	/**
	 * Captured HTTP request body from the last intercepted request.
	 *
	 * @var array|null
	 */
	private $last_request_body;

	/**
	 * Captured request URL from the last intercepted request.
	 *
	 * @var string|null
	 */
	private $last_request_url;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client            = new WP_MCP_AI_Ollama_Client();
		$this->last_request_body = null;
		$this->last_request_url  = null;

		// Configure a fake endpoint and model so the client does not early-exit.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'ollama_endpoint_url'                   => 'http://localhost:11434',
				'ollama_model'                          => 'llama3.1:8b',
				'ollama_use_openai_compatible_endpoint' => false,
			)
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Register a pre_http_request filter that intercepts the next request and
	 * returns a custom JSON response body.
	 *
	 * @param array  $response_body Array to JSON-encode as the mock response.
	 * @param int    $http_code     HTTP status code (default 200).
	 * @param string $url_fragment  Optional substring to match against the URL.
	 */
	private function mock_http_response( array $response_body, $http_code = 200, $url_fragment = '' ) {
		$capture = function ( $preempt, $args, $url ) use ( $response_body, $http_code, $url_fragment, &$capture ) {
			if ( '' !== $url_fragment && false === strpos( $url, $url_fragment ) ) {
				return $preempt;
			}
			$this->last_request_body = json_decode( $args['body'], true );
			$this->last_request_url  = $url;
			remove_filter( 'pre_http_request', $capture, 10 );
			return array(
				'response' => array( 'code' => $http_code ),
				'body'     => wp_json_encode( $response_body ),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );
	}

	/**
	 * Build a minimal Ollama /api/chat response (no tool calls).
	 *
	 * @param string $content Assistant message content.
	 * @return array
	 */
	private function make_chat_response( $content = 'Hello!' ) {
		return array(
			'model'             => 'llama3.1:8b',
			'message'           => array(
				'role'    => 'assistant',
				'content' => $content,
			),
			'done'              => true,
			'done_reason'       => 'stop',
			'prompt_eval_count' => 10,
			'eval_count'        => 20,
		);
	}

	/**
	 * Build a minimal Ollama /api/chat response with tool calls.
	 *
	 * @param string $tool_name     Tool name.
	 * @param array  $tool_args     Tool arguments (object, not JSON string).
	 * @return array
	 */
	private function make_tool_call_response( $tool_name = 'get_weather', array $tool_args = array( 'location' => 'Paris' ) ) {
		return array(
			'model'       => 'llama3.1:8b',
			'message'     => array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'function' => array(
							'name'      => $tool_name,
							'arguments' => $tool_args,
						),
					),
				),
			),
			'done'        => true,
			'done_reason' => 'stop',
		);
	}

	/**
	 * Minimal tool definition in OpenAI format.
	 *
	 * @return array
	 */
	private function make_tool_definition() {
		return array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Returns the current weather for a location.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'City name.',
							),
						),
						'required'   => array( 'location' ),
					),
				),
			),
		);
	}

	// -----------------------------------------------------------------------
	// Phase 1 — tool serialization in payload
	// -----------------------------------------------------------------------

	/**
	 * Tools array must be present in the request payload when options['tools'] is set.
	 */
	public function test_tools_serialized_in_payload() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'What is the weather in Paris?' ) ),
			array( 'tools' => $this->make_tool_definition() )
		);

		$this->assertNotNull( $this->last_request_body, 'HTTP request should have been made.' );
		$this->assertArrayHasKey( 'tools', $this->last_request_body, 'tools key must be present in payload.' );
		$this->assertCount( 1, $this->last_request_body['tools'], 'Exactly one tool should be in the payload.' );
		$this->assertEquals( 'get_weather', $this->last_request_body['tools'][0]['function']['name'] );
	}

	/**
	 * tool_choice string value must be forwarded verbatim.
	 */
	public function test_tool_choice_string_forwarded() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array(
				'tools'       => $this->make_tool_definition(),
				'tool_choice' => 'auto',
			)
		);

		$this->assertEquals( 'auto', $this->last_request_body['tool_choice'] );
	}

	/**
	 * parallel_tool_calls must be forwarded as a boolean.
	 */
	public function test_parallel_tool_calls_forwarded() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array(
				'tools'               => $this->make_tool_definition(),
				'parallel_tool_calls' => false,
			)
		);

		$this->assertArrayHasKey( 'parallel_tool_calls', $this->last_request_body );
		$this->assertFalse( $this->last_request_body['parallel_tool_calls'] );
	}

	/**
	 * No tools key should appear in the payload when tools are not provided.
	 */
	public function test_no_tools_key_when_tools_not_provided() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) )
		);

		$this->assertArrayNotHasKey( 'tools', $this->last_request_body, 'tools key must not appear when not needed.' );
	}

	// -----------------------------------------------------------------------
	// Phase 1 — message loop: tool-role and assistant tool_calls preservation
	// -----------------------------------------------------------------------

	/**
	 * When $has_tools, tool-role messages must preserve tool_call_id and name.
	 */
	public function test_tool_role_messages_preserve_tool_call_id() {
		$this->mock_http_response( $this->make_chat_response() );

		$messages = array(
			array( 'role' => 'user', 'content' => 'What is the weather?' ),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_0_abc',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"Paris"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'content'      => '22°C and sunny',
				'tool_call_id' => 'call_0_abc',
				'name'         => 'get_weather',
			),
		);

		$this->client->create_chat_completion( $messages, array( 'tools' => $this->make_tool_definition() ) );

		$sent_messages = $this->last_request_body['messages'];

		// Find the tool-role message.
		$tool_msg = null;
		foreach ( $sent_messages as $m ) {
			if ( 'tool' === $m['role'] ) {
				$tool_msg = $m;
				break;
			}
		}

		$this->assertNotNull( $tool_msg, 'A tool-role message should be present.' );
		$this->assertEquals( 'call_0_abc', $tool_msg['tool_call_id'], 'tool_call_id must be preserved.' );
		$this->assertEquals( 'get_weather', $tool_msg['name'], 'name must be preserved.' );
		$this->assertStringContainsString( '22', $tool_msg['content'], 'Content must be preserved.' );
	}

	/**
	 * When $has_tools, assistant tool_calls must be converted to Ollama native
	 * format (object arguments, no id/type wrapper).
	 */
	public function test_assistant_tool_calls_converted_to_ollama_format() {
		$this->mock_http_response( $this->make_chat_response() );

		$messages = array(
			array( 'role' => 'user', 'content' => 'Weather?' ),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_0_abc',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"Paris"}', // JSON string (OpenAI format).
						),
					),
				),
			),
		);

		$this->client->create_chat_completion( $messages, array( 'tools' => $this->make_tool_definition() ) );

		$sent_messages = $this->last_request_body['messages'];
		$assistant_msg = null;
		foreach ( $sent_messages as $m ) {
			if ( 'assistant' === $m['role'] ) {
				$assistant_msg = $m;
				break;
			}
		}

		$this->assertNotNull( $assistant_msg );
		$this->assertArrayHasKey( 'tool_calls', $assistant_msg, 'tool_calls must be in assistant message.' );
		$tc = $assistant_msg['tool_calls'][0];
		// Ollama native format: no 'id', no 'type', arguments is an array.
		$this->assertArrayNotHasKey( 'id', $tc, 'id should not be present in Ollama native format.' );
		$this->assertEquals( 'get_weather', $tc['function']['name'] );
		$this->assertIsArray( $tc['function']['arguments'], 'arguments must be a decoded object (array).' );
		$this->assertEquals( 'Paris', $tc['function']['arguments']['location'] );
	}

	/**
	 * Without tools, orphan tool-role messages (no matching assistant
	 * tool_calls) are dropped from the payload, mirroring the OpenAI-client
	 * parity filter, instead of being stringified into user messages.
	 */
	public function test_tool_role_stringified_without_tools() {
		$this->mock_http_response( $this->make_chat_response() );

		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
			array(
				'role'         => 'tool',
				'content'      => 'result',
				'name'         => 'some_tool',
				'tool_call_id' => 'call_0',
			),
		);

		$this->client->create_chat_completion( $messages ); // No tools option.

		$sent_messages = $this->last_request_body['messages'];
		$tool_messages = array_filter( $sent_messages, function ( $m ) { return 'tool' === $m['role']; } );

		$this->assertEmpty( $tool_messages, 'No tool-role messages should remain when tools are absent.' );

		$found = false;
		foreach ( $sent_messages as $m ) {
			if ( false !== strpos( $m['content'], 'some_tool' ) ) {
				$found = true;
			}
		}
		$this->assertFalse( $found, 'Orphan tool result should be dropped, not stringified.' );
	}

	// -----------------------------------------------------------------------
	// Phase 1 — normalize_response: parse tool_calls, set finish_reason
	// -----------------------------------------------------------------------

	/**
	 * Response with tool_calls must return finish_reason=tool_calls and
	 * tool_calls in OpenAI format (stringified arguments, id, type).
	 */
	public function test_tool_calls_parsed_from_response() {
		$this->mock_http_response(
			$this->make_tool_call_response( 'get_weather', array( 'location' => 'Paris' ) )
		);

		$result = $this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Weather in Paris?' ) ),
			array( 'tools' => $this->make_tool_definition() )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$choice = $result['choices'][0];

		$this->assertEquals( 'tool_calls', $choice['finish_reason'], 'finish_reason must be tool_calls.' );
		$this->assertArrayHasKey( 'tool_calls', $choice['message'], 'message must have tool_calls.' );

		$tc = $choice['message']['tool_calls'][0];
		$this->assertEquals( 'function', $tc['type'] );
		$this->assertNotEmpty( $tc['id'], 'Tool call must have a generated id.' );
		$this->assertEquals( 'get_weather', $tc['function']['name'] );
		// Arguments must be a JSON string in OpenAI format.
		$this->assertIsString( $tc['function']['arguments'], 'arguments must be a JSON string.' );
		$decoded_args = json_decode( $tc['function']['arguments'], true );
		$this->assertEquals( 'Paris', $decoded_args['location'] );
	}

	/**
	 * Normal text response must not have tool_calls and finish_reason=stop.
	 */
	public function test_normal_response_has_no_tool_calls() {
		$this->mock_http_response( $this->make_chat_response( 'The weather is 22°C.' ) );

		$result = $this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Weather?' ) )
		);

		$choice = $result['choices'][0];
		$this->assertEquals( 'stop', $choice['finish_reason'] );
		$this->assertArrayNotHasKey( 'tool_calls', $choice['message'], 'No tool_calls in plain response.' );
	}

	// -----------------------------------------------------------------------
	// Phase 1 — streaming: tool_calls accumulation
	// -----------------------------------------------------------------------

	/**
	 * Streaming response with tool_calls in a chunk must be collected and
	 * normalized correctly.
	 */
	public function test_streaming_response_collects_tool_calls() {
		// Build a fake streaming NDJSON body.
		$chunk1 = array(
			'model'   => 'llama3.1:8b',
			'message' => array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => array( 'location' => 'Paris' ),
						),
					),
				),
			),
			'done'    => false,
		);
		$chunk2 = array(
			'model'       => 'llama3.1:8b',
			'message'     => array(
				'role'    => 'assistant',
				'content' => '',
			),
			'done'        => true,
			'done_reason' => 'stop',
		);

		$streaming_body = wp_json_encode( $chunk1 ) . "\n" . wp_json_encode( $chunk2 ) . "\n";

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $streaming_body ) {
				if ( false !== strpos( $url, '/api/chat' ) ) {
					$this->last_request_body = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => $streaming_body,
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Weather?' ) ),
			array(
				'tools'  => $this->make_tool_definition(),
				'stream' => true,
			)
		);

		$this->assertIsArray( $result );
		$choice = $result['choices'][0];
		$this->assertEquals( 'tool_calls', $choice['finish_reason'], 'Streaming: finish_reason must be tool_calls.' );
		$this->assertArrayHasKey( 'tool_calls', $choice['message'], 'Streaming: tool_calls must be present.' );
		$tc = $choice['message']['tool_calls'][0];
		$this->assertEquals( 'get_weather', $tc['function']['name'] );
		$this->assertIsString( $tc['function']['arguments'] );
		$args = json_decode( $tc['function']['arguments'], true );
		$this->assertEquals( 'Paris', $args['location'] );
	}

	// -----------------------------------------------------------------------
	// Phase 2 — structured output, think, keep_alive
	// -----------------------------------------------------------------------

	/**
	 * response_format=json_object must set format:'json' in the payload.
	 */
	public function test_response_format_json_object() {
		$this->mock_http_response( $this->make_chat_response( '{"answer":"yes"}' ) );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Answer with JSON.' ) ),
			array( 'response_format' => array( 'type' => 'json_object' ) )
		);

		$this->assertEquals( 'json', $this->last_request_body['format'], 'json_object → format:"json".' );
	}

	/**
	 * response_format=json_schema must set format to the schema object.
	 */
	public function test_response_format_json_schema() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'answer' => array( 'type' => 'string' ) ),
		);

		$this->mock_http_response( $this->make_chat_response( '{"answer":"yes"}' ) );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Answer with JSON schema.' ) ),
			array(
				'response_format' => array(
					'type'        => 'json_schema',
					'json_schema' => array( 'schema' => $schema ),
				),
			)
		);

		$this->assertEquals( $schema, $this->last_request_body['format'], 'json_schema → format:<schema object>.' );
	}

	/**
	 * options['think'] must be forwarded to the payload.
	 */
	public function test_think_option_forwarded() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Think about it.' ) ),
			array( 'think' => true )
		);

		$this->assertArrayHasKey( 'think', $this->last_request_body );
		$this->assertTrue( $this->last_request_body['think'] );
	}

	/**
	 * options['reasoning'] (generic flag) should also enable think.
	 */
	public function test_reasoning_option_enables_think() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Reason.' ) ),
			array( 'reasoning' => true )
		);

		$this->assertArrayHasKey( 'think', $this->last_request_body );
		$this->assertTrue( $this->last_request_body['think'] );
	}

	/**
	 * options['keep_alive'] must be forwarded to the payload.
	 */
	public function test_keep_alive_forwarded() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array( 'keep_alive' => '5m' )
		);

		$this->assertEquals( '5m', $this->last_request_body['keep_alive'] );
	}

	/**
	 * num_thread / num_gpu / low_vram must be forwarded inside options.
	 */
	public function test_hardware_options_forwarded() {
		$this->mock_http_response( $this->make_chat_response() );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array(
				'num_thread' => 4,
				'num_gpu'    => 1,
				'low_vram'   => true,
			)
		);

		$opts = $this->last_request_body['options'];
		$this->assertEquals( 4, $opts['num_thread'] );
		$this->assertEquals( 1, $opts['num_gpu'] );
		$this->assertTrue( $opts['low_vram'] );
	}

	// -----------------------------------------------------------------------
	// Phase 2 — thinking content in response
	// -----------------------------------------------------------------------

	/**
	 * When the response includes message.thinking, it should be exposed as
	 * reasoning_content in the normalized message.
	 */
	public function test_thinking_content_normalized() {
		$mock_response = array(
			'model'       => 'llama3.1:8b',
			'message'     => array(
				'role'     => 'assistant',
				'content'  => 'The answer is 42.',
				'thinking' => 'I need to think about this carefully...',
			),
			'done'        => true,
			'done_reason' => 'stop',
		);

		$this->mock_http_response( $mock_response );

		$result = $this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'What is the answer?' ) ),
			array( 'think' => true )
		);

		$this->assertEquals( 'I need to think about this carefully...', $result['choices'][0]['message']['reasoning_content'] );
	}

	// -----------------------------------------------------------------------
	// Phase 3 — OpenAI-compatible loopback mode
	// -----------------------------------------------------------------------

	/**
	 * When loopback mode is enabled, requests must go to /v1/chat/completions.
	 */
	public function test_loopback_mode_uses_openai_endpoint() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'ollama_endpoint_url'                   => 'http://localhost:11434',
				'ollama_model'                          => 'llama3.1:8b',
				'ollama_use_openai_compatible_endpoint' => true,
			)
		);

		// OpenAI-compat response.
		$compat_response = array(
			'id'      => 'chatcmpl-test',
			'object'  => 'chat.completion',
			'model'   => 'llama3.1:8b',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from loopback!',
					),
					'finish_reason' => 'stop',
				),
			),
		);
		$this->mock_http_response( $compat_response, 200, '/v1/chat/completions' );

		$result = $this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) )
		);

		$this->assertStringContainsString( '/v1/chat/completions', $this->last_request_url, 'Loopback must use /v1/chat/completions.' );
		$this->assertEquals( 'ollama', $result['provider'] );
	}

	/**
	 * In loopback mode the system prompt must be sent as a system-role message,
	 * not as a top-level 'system' key.
	 */
	public function test_loopback_mode_system_as_message() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'ollama_endpoint_url'                   => 'http://localhost:11434',
				'ollama_model'                          => 'llama3.1:8b',
				'ollama_use_openai_compatible_endpoint' => true,
			)
		);

		$compat_response = array(
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array( 'role' => 'assistant', 'content' => 'Hi' ),
					'finish_reason' => 'stop',
				),
			),
		);
		$this->mock_http_response( $compat_response );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array( 'system_prompt' => 'You are a helpful assistant.' )
		);

		$messages = $this->last_request_body['messages'];
		$system_messages = array_filter( $messages, function ( $m ) { return 'system' === $m['role']; } );

		$this->assertNotEmpty( $system_messages, 'System prompt must be a system-role message in loopback mode.' );
		$first_system = array_values( $system_messages )[0];
		$this->assertStringContainsString( 'helpful assistant', $first_system['content'] );
		$this->assertArrayNotHasKey( 'system', $this->last_request_body, 'No top-level system key in loopback mode.' );
	}

	/**
	 * In loopback mode, tools must be forwarded verbatim (no conversion needed).
	 */
	public function test_loopback_mode_tools_forwarded() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'ollama_endpoint_url'                   => 'http://localhost:11434',
				'ollama_model'                          => 'llama3.1:8b',
				'ollama_use_openai_compatible_endpoint' => true,
			)
		);

		$compat_response = array(
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array( 'role' => 'assistant', 'content' => '' ),
					'finish_reason' => 'stop',
				),
			),
		);
		$this->mock_http_response( $compat_response );

		$this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Weather?' ) ),
			array( 'tools' => $this->make_tool_definition() )
		);

		$this->assertArrayHasKey( 'tools', $this->last_request_body, 'Tools must be in loopback payload.' );
		$this->assertEquals( 'get_weather', $this->last_request_body['tools'][0]['function']['name'] );
	}

	// -----------------------------------------------------------------------
	// Phase 2 — model capability helpers
	// -----------------------------------------------------------------------

	/**
	 * supports_tools() must return true when 'tools' is in capabilities array.
	 */
	public function test_supports_tools_returns_true() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/api/show' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'capabilities' => array( 'completion', 'tools', 'vision' ) ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->assertTrue( $this->client->supports_tools( 'llama3.1:8b' ) );
		$this->assertTrue( $this->client->supports_vision( 'llama3.1:8b' ) );
		$this->assertFalse( $this->client->supports_thinking( 'llama3.1:8b' ) );
	}

	/**
	 * supports_tools() must return false when 'tools' is not in capabilities.
	 */
	public function test_supports_tools_returns_false_without_capability() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/api/show' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'capabilities' => array( 'completion' ) ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->assertFalse( $this->client->supports_tools( 'mistral:latest' ) );
	}

	/**
	 * get_model_capabilities() must return empty array on HTTP error.
	 */
	public function test_get_model_capabilities_returns_empty_on_error() {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection refused.' );
			}
		);

		$caps = $this->client->get_model_capabilities( 'some_model' );
		$this->assertIsArray( $caps );
		$this->assertEmpty( $caps );
	}
}
