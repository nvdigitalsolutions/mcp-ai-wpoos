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
		$this->assertNotEmpty( $result );
		$this->assertEquals( 'user', $result[0]['role'] );
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
		$files = array( 1, 2, 3 );
		$result = $this->validator->sanitize_memory_files( $files );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertEquals( array( 1, 2, 3 ), $result );
	}

	/**
	 * Test sanitize_memory_files with array of arrays.
	 */
	public function test_sanitize_memory_files_array_of_arrays() {
		$files = array(
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
		$files = array( 1, 2, 2, 3, 3, 3 );
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
}
