<?php
/**
 * Test tool error handling in chat context.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test that tool errors are properly normalized when used in chat endpoints.
 */
class Test_Tool_Error_Chat_Integration extends WP_UnitTestCase {

	/**
	 * Mock WP_MCP_AI_REST instance (no constructor args needed for tested methods).
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		// The REST controller needs a client, but no real API calls are made
		// for the paths under test.
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );
	}

	/**
	 * Test that rotate_image error is properly normalized in chat.
	 */
	public function test_rotate_image_error_normalization_in_chat() {
		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		// Configure assistant with rotate_image tool.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'rotate_image' ) );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4o-mini' );

		// Simulate a tool call from the LLM requesting rotate_image.
		$tool_call = array(
			'id'       => 'call_123',
			'function' => array(
				'name'      => 'rotate_image',
				'arguments' => wp_json_encode(
					array(
						'attachment_id' => 999,
						'angle'         => 90,
					)
				),
			),
		);

		// Get REST controller and use reflection to access protected method.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'execute_tool_call_internal' );
		$method->setAccessible( true );

		// Execute the tool call with no authentication (user_id = 0, no token).
		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$context          = array(
			'user_id'          => 0,
			'assistant_id'     => $assistant_id,
			'assistant_config' => $assistant_config,
		);

		$result = $method->invoke(
			$this->rest_controller,
			$tool_call,
			$assistant_id,
			$assistant_config,
			0, // user_id.
			null, // request.
			0, // iteration.
			5 // max_iterations.
		);

		// In the agentic loop, tool WP_Errors are converted into a message
		// string so the LLM can react instead of receiving a broken flow.
		$this->assertIsString( $result, 'Tool failure should surface as a message string in the agentic loop' );
		$this->assertStringContainsString( 'rotate_image', $result );
		$this->assertStringContainsString( 'authenticated', $result );

		// Now test normalization: only WP_Error instances are transformed;
		// string results pass through unchanged.
		$normalize_method = $reflection->getMethod( 'normalize_tool_result' );
		$normalize_method->setAccessible( true );

		$normalized = $normalize_method->invoke( $this->rest_controller, $result );

		// After normalization, the string should be unchanged.
		$this->assertIsString( $normalized, 'Normalized string should remain a string' );
		$this->assertSame( $result, $normalized, 'String results should pass through normalization unchanged' );

		// Verify it can be JSON-encoded.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Normalized error should be JSON-encodable' );
		$this->assertStringContainsString( 'authenticated', $json );

		// Verify the JSON is valid and round-trips the message string.
		$decoded = json_decode( $json, true );
		$this->assertIsString( $decoded, 'JSON should decode back to the message string' );
		$this->assertEquals( $normalized, $decoded, 'Round-trip should preserve data' );
	}

	/**
	 * Test that successful tool results pass through normalization unchanged.
	 */
	public function test_successful_tool_result_unchanged() {
		$successful_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/rotated.jpg',
			'text'          => 'Successfully rotated image 90 degrees.',
		);

		$reflection       = new ReflectionClass( $this->rest_controller );
		$normalize_method = $reflection->getMethod( 'normalize_tool_result' );
		$normalize_method->setAccessible( true );

		$normalized = $normalize_method->invoke( $this->rest_controller, $successful_result );

		$this->assertEquals( $successful_result, $normalized, 'Successful result should be unchanged' );
	}

	/**
	 * Test that normalized errors work with frontend message extraction.
	 *
	 * This simulates what the frontend JavaScript does with tool results.
	 */
	public function test_normalized_error_message_extraction() {
		$error = new WP_Error(
			'wp_mcp_ai_forbidden',
			'You must be authenticated to rotate images.',
			array( 'status' => 401 )
		);

		$reflection       = new ReflectionClass( $this->rest_controller );
		$normalize_method = $reflection->getMethod( 'normalize_tool_result' );
		$normalize_method->setAccessible( true );

		$normalized = $normalize_method->invoke( $this->rest_controller, $error );

		// Simulate frontend extractGenericToolResponse() behavior.
		// The frontend checks for result.message.
		$this->assertArrayHasKey( 'message', $normalized );
		$extracted_message = $normalized['message'];

		$this->assertEquals( 'You must be authenticated to rotate images.', $extracted_message );
		$this->assertIsString( $extracted_message );
		$this->assertNotEmpty( $extracted_message );
	}
}
