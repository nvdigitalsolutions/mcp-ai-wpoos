<?php
/**
 * Tests for REST API endpoint parameter validation.
 *
 * Ensures that all endpoints properly validate their input parameters
 * and return clear, actionable error messages when validation fails.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for REST API endpoint validation.
 */
class WP_MCP_AI_REST_Endpoint_Validation_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected static $assistant_id;

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up test fixtures once for all tests.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Create a test assistant.
		self::$assistant_id = $factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Configure assistant with basic settings.
		update_post_meta(
			self::$assistant_id,
			'_wp_mcp_ai_config',
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4',
				'tools'    => array( 'search_content' ),
			)
		);
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$admin_id ) {
			wp_delete_user( self::$admin_id );
		}

		if ( self::$assistant_id ) {
			wp_delete_post( self::$assistant_id, true );
		}
	}

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * Test /chat endpoint rejects empty messages array.
	 */
	public function test_chat_endpoint_rejects_empty_messages() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'messages' => array() ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Empty messages array should return 400' );
		$this->assertArrayHasKey( 'code', $data, 'Error response should include code' );
		$this->assertArrayHasKey( 'message', $data, 'Error response should include message' );
		$this->assertStringContainsString( 'cannot be empty', $data['message'], 'Error message should mention empty array' );
		$this->assertArrayHasKey( 'actions', $data, 'Error response should include actionable guidance' );
	}

	/**
	 * Test /chat endpoint rejects messages without role.
	 */
	public function test_chat_endpoint_rejects_message_without_role() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages' => array(
						array( 'content' => 'Hello' ), // Missing 'role'.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Message without role should return 400' );
		$this->assertStringContainsString( 'missing required "role"', $data['message'], 'Error should mention missing role' );
		$this->assertArrayHasKey( 'actions', $data, 'Error should provide actionable guidance' );
	}

	/**
	 * Test /chat endpoint rejects invalid role values.
	 */
	public function test_chat_endpoint_rejects_invalid_role() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'invalid_role',
							'content' => 'Hello',
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid role should return 400' );
		$this->assertStringContainsString( 'invalid role', $data['message'], 'Error should mention invalid role' );
		$this->assertStringContainsString( 'system, user, assistant', $data['message'], 'Error should list valid roles' );
	}

	/**
	 * Test /chat endpoint rejects messages without content (except assistant).
	 */
	public function test_chat_endpoint_rejects_message_without_content() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages' => array(
						array( 'role' => 'user' ), // Missing 'content'.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Message without content should return 400' );
		$this->assertStringContainsString( 'missing required "content"', $data['message'], 'Error should mention missing content' );
	}

	/**
	 * Test /chat endpoint rejects tool messages without tool_call_id.
	 */
	public function test_chat_endpoint_rejects_tool_message_without_tool_call_id() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'tool',
							'content' => 'Tool result',
							// Missing 'tool_call_id'.
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Tool message without tool_call_id should return 400' );
		$this->assertStringContainsString( 'missing required "tool_call_id"', $data['message'], 'Error should mention missing tool_call_id' );
	}

	/**
	 * Test /chat endpoint rejects non-array messages parameter.
	 */
	public function test_chat_endpoint_rejects_non_array_messages() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'messages' => 'not an array' ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Non-array messages should return 400' );
		$this->assertStringContainsString( 'must be an array', $data['message'], 'Error should mention type mismatch' );
	}

	/**
	 * Test /chat endpoint accepts valid messages array.
	 */
	public function test_chat_endpoint_accepts_valid_messages() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => self::$assistant_id,
					'messages'     => array(
						array(
							'role'    => 'user',
							'content' => 'Hello, how are you?',
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );

		// Should not be a 400 validation error.
		$this->assertNotEquals( 400, $response->get_status(), 'Valid messages should not return 400' );
	}

	/**
	 * Test /chat endpoint rejects attachments without file_id or url.
	 */
	public function test_chat_endpoint_rejects_attachment_without_file_reference() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
					'attachments' => array(
						array( 'name' => 'file.txt' ), // Missing both file_id and url.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Attachment without file reference should return 400' );
		$this->assertStringContainsString( 'must include either "file_id"', $data['message'], 'Error should mention missing file reference' );
		$this->assertArrayHasKey( 'actions', $data, 'Error should provide actionable guidance' );
	}

	/**
	 * Test /chat endpoint rejects attachment with invalid file_id.
	 */
	public function test_chat_endpoint_rejects_invalid_file_id() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
					'attachments' => array(
						array( 'file_id' => 0 ), // Invalid file_id.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid file_id should return 400' );
		$this->assertStringContainsString( 'invalid "file_id"', $data['message'], 'Error should mention invalid file_id' );
	}

	/**
	 * Test /chat endpoint rejects attachment with invalid URL.
	 */
	public function test_chat_endpoint_rejects_invalid_url() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
					'attachments' => array(
						array( 'url' => 'not-a-valid-url' ), // Invalid URL.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid URL should return 400' );
		$this->assertStringContainsString( 'invalid "url"', $data['message'], 'Error should mention invalid URL' );
	}

	/**
	 * Test /mcp endpoint rejects tools/call without name parameter.
	 */
	public function test_mcp_tools_call_requires_name_parameter() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'arguments' => array( 'test' => 'value' ), // Missing 'name'.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'tools/call without name should return 400' );

		// MCP returns JSON-RPC error format.
		$this->assertArrayHasKey( 'error', $data, 'Response should include error object' );
		$this->assertStringContainsString( 'name', $data['error']['message'], 'Error should mention missing name parameter' );
	}

	/**
	 * Test /mcp endpoint rejects non-object params.
	 */
	public function test_mcp_endpoint_rejects_non_object_params() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => 'not an object', // Invalid type.
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Non-object params should return 400' );
		$this->assertArrayHasKey( 'error', $data, 'Response should include error object' );
	}

	/**
	 * Test /tools endpoint with missing tool parameter.
	 */
	public function test_tools_endpoint_requires_tool_parameter() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => self::$assistant_id,
					'arguments'    => array( 'test' => 'value' ),
					// Missing 'tool' parameter.
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// WordPress REST API returns 400 for missing required parameters.
		$this->assertSame( 400, $response->get_status(), 'Missing required tool parameter should return 400' );
		$this->assertArrayHasKey( 'code', $data, 'Response should include error code' );
		$this->assertArrayHasKey( 'message', $data, 'Response should include error message' );
	}

	/**
	 * Test error responses include actionable guidance.
	 */
	public function test_error_responses_include_actions() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages' => array(
						array( 'role' => 'user' ), // Missing content.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'actions', $data, 'Validation errors should include actionable guidance' );
		$this->assertIsArray( $data['actions'], 'Actions should be an array' );
		$this->assertNotEmpty( $data['actions'], 'Actions array should not be empty' );
	}

	/**
	 * Test complex nested message validation.
	 */
	public function test_complex_message_array_validation() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => 'First message',
						),
						array(
							'role'    => 'assistant',
							'content' => 'Response',
						),
						array(
							'role'    => 'invalid', // Invalid role in middle of array.
							'content' => 'Should fail',
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid role in message array should fail' );
		$this->assertStringContainsString( 'index 2', $data['message'], 'Error should identify problematic message index' );
		$this->assertStringContainsString( 'invalid role', $data['message'], 'Error should identify the issue' );
	}

	/**
	 * Test validation with mixed valid and invalid parameters.
	 */
	public function test_validation_with_mixed_parameters() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
					'attachments' => array(
						array( 'url' => 'https://example.com/valid.pdf' ), // Valid.
						array( 'name' => 'invalid' ), // Invalid - no file_id or url.
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Mixed valid/invalid attachments should fail' );
		$this->assertStringContainsString( 'index 1', $data['message'], 'Error should identify problematic attachment' );
	}
}
