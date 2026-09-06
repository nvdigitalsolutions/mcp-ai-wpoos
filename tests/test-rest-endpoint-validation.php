<?php
/**
 * Tests for REST API endpoint parameter validation.
 *
 * Ensures that all endpoints properly validate their input parameters
 * and return clear, actionable error messages when validation fails.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
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
	 * Extract the detailed validation message for a field from a WP REST error.
	 *
	 * Production nests per-field messages under data.params and actionable
	 * guidance under details.<field>.data.actions (the standard
	 * rest_invalid_param shape); the top-level message is only a summary.
	 *
	 * @param array  $data  Response data.
	 * @param string $field Field name.
	 * @return string
	 */
	protected function field_error_message( $data, $field ) {
		if ( isset( $data['data']['params'][ $field ] ) ) {
			return (string) $data['data']['params'][ $field ];
		}
		if ( isset( $data['details'][ $field ]['message'] ) ) {
			return (string) $data['details'][ $field ]['message'];
		}
		return '';
	}

	/**
	 * Extract actionable guidance for a field from a WP REST error.
	 *
	 * @param array  $data  Response data.
	 * @param string $field Field name.
	 * @return array
	 */
	protected function field_actions( $data, $field ) {
		if ( isset( $data['data']['details'][ $field ]['data']['actions'] ) && is_array( $data['data']['details'][ $field ]['data']['actions'] ) ) {
			return $data['data']['details'][ $field ]['data']['actions'];
		}
		return array();
	}

	/**
	 * Build an authenticated POST request to /mcp-ai/v1/chat with a JSON body.
	 *
	 * The permission gate requires either a bearer token or a valid wp_rest
	 * nonce; without one the endpoint short-circuits with 401 before the
	 * callback runs. Tests that assert callback-level validation (role values,
	 * embedded attachment segments) must authenticate so sanitize_messages()
	 * and the attachment pipeline actually execute.
	 *
	 * @param array $body Request body.
	 * @return WP_REST_Request
	 */
	private function authenticated_chat_request( array $body ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
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
		$this->assertStringContainsString( 'cannot be empty', $this->field_error_message( $data, 'messages' ), 'Error message should mention empty array' );
		$this->assertNotEmpty( $this->field_actions( $data, 'messages' ), 'Error response should include actionable guidance' );
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
		$this->assertStringContainsString( 'missing required "role"', $this->field_error_message( $data, 'messages' ), 'Error should mention missing role' );
		$this->assertNotEmpty( $this->field_actions( $data, 'messages' ), 'Error should provide actionable guidance' );
	}

	/**
	 * Test /chat endpoint rejects invalid role values.
	 */
	public function test_chat_endpoint_rejects_invalid_role() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
					array(
						'role'    => 'invalid_role',
						'content' => 'Hello',
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Role values are enforced in sanitize_messages() (after the args gate)
		// so custom roles registered via the wp_mcp_ai_allowed_message_roles
		// filter are not rejected at the REST schema layer.
		$this->assertSame( 400, $response->get_status(), 'Invalid role should return 400' );
		$this->assertSame( 'wp_mcp_ai_invalid_message_role', $data['code'] );
		$this->assertStringContainsString( 'invalid_role', $data['message'], 'Error should echo the offending role' );
		$this->assertStringContainsString( 'not supported', $data['message'], 'Error should explain the rejection' );
		$this->assertStringContainsString( 'user, assistant, system', $data['message'], 'Error should list valid roles' );
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
		$this->assertStringContainsString( 'missing required "content"', $this->field_error_message( $data, 'messages' ), 'Error should mention missing content' );
	}

	/**
	 * Test /chat endpoint rejects tool messages without tool_call_id.
	 *
	 * Orphaned tool messages are discarded before dispatch (preserving
	 * conversation flow for legacy transcript resubmissions); a request whose
	 * only message is orphaned is left with no messages and is rejected.
	 */
	public function test_chat_endpoint_rejects_orphaned_tool_message() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
					array(
						'role'    => 'tool',
						'content' => 'Tool result',
						// Missing 'tool_call_id'.
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Lone orphaned tool message should return 400' );
		$this->assertSame( 'wp_mcp_ai_invalid_messages', $data['code'] );
		$this->assertStringContainsString( 'Messages must be provided', $data['message'], 'Error should explain there are no usable messages' );
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
		$this->assertStringContainsString( 'must be an array', $this->field_error_message( $data, 'messages' ), 'Error should mention type mismatch' );
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
	 * Test /chat endpoint rejects image segments without a file reference.
	 *
	 * Attachments are embedded in message content segments (input_image /
	 * input_file); a segment without any URL, file_id, or attachment_id is
	 * rejected during sanitization.
	 */
	public function test_chat_endpoint_rejects_image_segment_without_reference() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => array(
							array( 'type' => 'input_image' ), // No URL, file_id, or attachment_id.
						),
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Image segment without reference should return 400' );
		$this->assertSame( 'wp_mcp_ai_missing_image_attachment', $data['code'] );
		$this->assertStringContainsString( 'attachment ID or URL', $data['message'], 'Error should mention the missing reference' );
	}

	/**
	 * Test /chat endpoint rejects file segments with an unknown file_id.
	 */
	public function test_chat_endpoint_rejects_invalid_file_id() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'    => 'input_file',
								'file_id' => 'not-a-real-file', // Unknown reference.
							),
						),
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Unknown file_id should return 400' );
		$this->assertSame( 'wp_mcp_ai_unknown_file_reference', $data['code'] );
		$this->assertStringContainsString( 'could not be found', $data['message'], 'Error should mention the unknown file' );
	}

	/**
	 * Test /chat endpoint rejects file segments with a disallowed URL scheme.
	 *
	 * Esc_url_raw() accepts any wp_allowed_protocols scheme (including ftp),
	 * but the attachment pipeline restricts remote file URLs to http/https,
	 * so an ftp:// URL must be rejected during sanitization.
	 */
	public function test_chat_endpoint_rejects_invalid_url() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_file',
								'url'  => 'ftp://example.com/file.pdf', // Scheme not in the allowlist.
							),
						),
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Disallowed URL scheme should return 400' );
		$this->assertSame( 'wp_mcp_ai_unsupported_file_url_scheme', $data['code'] );
		$this->assertStringContainsString( 'allowed scheme', $data['message'], 'Error should mention the URL scheme restriction' );
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

		// tools/call parameter validation is handled by the REST schema, so the
		// response carries the WP REST param-error shape.
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertStringContainsString( 'name', $this->field_error_message( $data, 'params' ), 'Error should mention missing name parameter' );
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
		$this->assertSame( 'rest_invalid_param', $data['code'] );
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
		$actions = $this->field_actions( $data, 'messages' );
		$this->assertIsArray( $actions, 'Actions should be an array' );
		$this->assertNotEmpty( $actions, 'Actions array should not be empty' );
	}

	/**
	 * Test complex nested message validation.
	 */
	public function test_complex_message_array_validation() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
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
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Invalid role in message array should fail' );
		$this->assertSame( 'wp_mcp_ai_invalid_message_role', $data['code'] );
		$this->assertStringContainsString( 'invalid', $data['message'], 'Error should identify the offending role' );
		$this->assertStringContainsString( 'not supported', $data['message'], 'Error should explain the rejection' );
	}

	/**
	 * Test validation with mixed valid and invalid parameters.
	 */
	public function test_validation_with_mixed_parameters() {
		$request = $this->authenticated_chat_request(
			array(
				'assistant_id' => self::$assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Hello', // Valid segment.
							),
							array( 'type' => 'input_image' ), // Invalid - no file reference.
						),
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'Mixed valid/invalid segments should fail' );
		$this->assertSame( 'wp_mcp_ai_missing_image_attachment', $data['code'] );
		$this->assertStringContainsString( 'attachment ID or URL', $data['message'], 'Error should identify the problematic segment' );
	}
}
