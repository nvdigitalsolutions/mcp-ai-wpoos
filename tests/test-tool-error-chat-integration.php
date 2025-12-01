<?php
/**
 * Test tool error handling in chat context.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that tool errors are properly normalized when used in chat endpoints.
 */
class Test_Tool_Error_Chat_Integration extends WP_UnitTestCase {

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
		update_post_meta( $assistant_id, 'mcp_ai_tools', array( 'rotate_image' ) );
		update_post_meta( $assistant_id, 'mcp_ai_provider', 'openai' );
		update_post_meta( $assistant_id, 'mcp_ai_model', 'gpt-4o-mini' );

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
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'execute_tool_call_internal' );
		$method->setAccessible( true );

		// Execute the tool call with no authentication (user_id = 0, no token).
		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$context          = array(
			'user_id'          => 0,
			'assistant_id'     => $assistant_id,
			'assistant_config' => $assistant_config,
		);

		$result = $method->invoke(
			$rest_controller,
			$tool_call,
			$assistant_id,
			$assistant_config,
			0, // user_id
			null, // request
			0, // iteration
			5 // max_iterations
		);

		// The result should be a WP_Error (not normalized yet by execute_tool_call_internal).
		$this->assertWPError( $result, 'Tool should return WP_Error for unauthenticated user' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Now test normalization.
		$normalize_method = $reflection->getMethod( 'normalize_tool_result' );
		$normalize_method->setAccessible( true );

		$normalized = $normalize_method->invoke( $rest_controller, $result );

		// After normalization, should be an array.
		$this->assertIsArray( $normalized, 'Normalized result should be an array' );
		$this->assertTrue( $normalized['error'], 'Error flag should be true' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $normalized['code'] );
		$this->assertStringContainsString( 'authenticated', $normalized['message'] );

		// Verify it can be JSON-encoded.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Normalized error should be JSON-encodable' );
		$this->assertStringContainsString( 'wp_mcp_ai_forbidden', $json );

		// Verify the JSON is valid.
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'JSON should decode back to array' );
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

		$rest_controller  = new WP_MCP_AI_REST();
		$reflection       = new ReflectionClass( $rest_controller );
		$normalize_method = $reflection->getMethod( 'normalize_tool_result' );
		$normalize_method->setAccessible( true );

		$normalized = $normalize_method->invoke( $rest_controller, $successful_result );

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

		$rest_controller  = new WP_MCP_AI_REST();
		$reflection       = new ReflectionClass( $rest_controller );
		$normalize_method = $reflection->getMethod( 'normalize_tool_result' );
		$normalize_method->setAccessible( true );

		$normalized = $normalize_method->invoke( $rest_controller, $error );

		// Simulate frontend extractGenericToolResponse() behavior.
		// The frontend checks for result.message.
		$this->assertArrayHasKey( 'message', $normalized );
		$extracted_message = $normalized['message'];

		$this->assertEquals( 'You must be authenticated to rotate images.', $extracted_message );
		$this->assertIsString( $extracted_message );
		$this->assertNotEmpty( $extracted_message );
	}
}
