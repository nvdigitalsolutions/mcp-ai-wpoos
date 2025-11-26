<?php
/**
 * Test tool error normalization.
 *
 * @package WP_MCP_AI
 */

/**
 * Test WP_Error normalization for tool results.
 */
class Test_Tool_Error_Normalization extends WP_UnitTestCase {

	/**
	 * Test normalize_tool_result with WP_Error.
	 */
	public function test_normalize_wp_error() {
		// Create a WP_Error.
		$error = new WP_Error(
			'wp_mcp_ai_forbidden',
			'You must be authenticated to rotate images.',
			array( 'status' => 401 )
		);

		// Get REST controller instance using reflection to access protected method.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_tool_result' );
		$method->setAccessible( true );

		// Normalize the error.
		$normalized = $method->invoke( $rest_controller, $error );

		// Assert it's converted to an array.
		$this->assertIsArray( $normalized, 'WP_Error should be converted to array' );
		$this->assertTrue( $normalized['error'], 'Error flag should be true' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $normalized['code'] );
		$this->assertEquals( 'You must be authenticated to rotate images.', $normalized['message'] );
		$this->assertArrayHasKey( 'data', $normalized );
		$this->assertEquals( array( 'status' => 401 ), $normalized['data'] );
	}

	/**
	 * Test normalize_tool_result with successful result.
	 */
	public function test_normalize_successful_result() {
		$result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.jpg',
			'text'          => 'Successfully rotated image.',
		);

		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_tool_result' );
		$method->setAccessible( true );

		// Normalize the result.
		$normalized = $method->invoke( $rest_controller, $result );

		// Assert it's unchanged.
		$this->assertEquals( $result, $normalized, 'Successful result should be unchanged' );
	}

	/**
	 * Test that normalized error can be JSON-encoded.
	 */
	public function test_normalized_error_json_encodable() {
		$error = new WP_Error(
			'wp_mcp_ai_forbidden',
			'You must be authenticated to rotate images.',
			array( 'status' => 401 )
		);

		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_tool_result' );
		$method->setAccessible( true );

		// Normalize the error.
		$normalized = $method->invoke( $rest_controller, $error );

		// Try to JSON-encode.
		$json = wp_json_encode( $normalized );

		// Assert encoding succeeds.
		$this->assertNotFalse( $json, 'Normalized error should be JSON-encodable' );
		$this->assertStringContainsString( 'wp_mcp_ai_forbidden', $json );
		$this->assertStringContainsString( 'You must be authenticated to rotate images', $json );
	}

	/**
	 * Test that WP_Error without data still normalizes correctly.
	 */
	public function test_normalize_wp_error_without_data() {
		$error = new WP_Error(
			'wp_mcp_ai_no_operation',
			'At least one parameter must be specified.'
		);

		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_tool_result' );
		$method->setAccessible( true );

		// Normalize the error.
		$normalized = $method->invoke( $rest_controller, $error );

		// Assert it's converted to an array.
		$this->assertIsArray( $normalized );
		$this->assertTrue( $normalized['error'] );
		$this->assertEquals( 'wp_mcp_ai_no_operation', $normalized['code'] );
		$this->assertEquals( 'At least one parameter must be specified.', $normalized['message'] );
		
		// Data key should not be present if error data is empty.
		$this->assertArrayNotHasKey( 'data', $normalized, 'Data key should not exist if error data is empty' );
	}
}
