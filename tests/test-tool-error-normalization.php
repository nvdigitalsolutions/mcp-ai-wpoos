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

	/**
	 * Test recursive normalization with nested WP_Error.
	 */
	public function test_normalize_data_recursive_with_nested_wp_error() {
		// Create a nested structure with WP_Error inside.
		$nested_data = array(
			'job_id'  => 'veo_123',
			'status'  => 'failed',
			'result'  => new WP_Error(
				'wp_mcp_ai_veo_failed',
				'Video generation failed due to quota exceeded.',
				array( 'status' => 429 )
			),
			'metadata' => array(
				'tool'    => 'generate_veo_video',
				'user_id' => 1,
			),
		);

		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_data_recursive' );
		$method->setAccessible( true );

		// Normalize the nested data.
		$normalized = $method->invoke( $rest_controller, $nested_data );

		// Assert the structure is preserved and WP_Error is converted.
		$this->assertIsArray( $normalized );
		$this->assertEquals( 'veo_123', $normalized['job_id'] );
		$this->assertEquals( 'failed', $normalized['status'] );
		
		// Check that the nested WP_Error was converted.
		$this->assertIsArray( $normalized['result'] );
		$this->assertTrue( $normalized['result']['error'] );
		$this->assertEquals( 'wp_mcp_ai_veo_failed', $normalized['result']['code'] );
		$this->assertEquals( 'Video generation failed due to quota exceeded.', $normalized['result']['message'] );
		
		// Metadata should be unchanged.
		$this->assertEquals( 'generate_veo_video', $normalized['metadata']['tool'] );
		$this->assertEquals( 1, $normalized['metadata']['user_id'] );
		
		// Verify JSON encoding works.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Recursively normalized data should be JSON-encodable' );
	}

	/**
	 * Test recursive normalization with deeply nested WP_Error.
	 */
	public function test_normalize_data_recursive_with_deeply_nested_wp_error() {
		// Create a deeply nested structure.
		$deeply_nested = array(
			'level1' => array(
				'level2' => array(
					'level3' => array(
						'error' => new WP_Error(
							'deep_error',
							'Error at level 3',
							array( 'depth' => 3 )
						),
						'value' => 'normal_value',
					),
				),
			),
		);

		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_data_recursive' );
		$method->setAccessible( true );

		// Normalize the deeply nested data.
		$normalized = $method->invoke( $rest_controller, $deeply_nested );

		// Navigate to the deeply nested error.
		$deep_error = $normalized['level1']['level2']['level3']['error'];
		
		// Assert the error was normalized.
		$this->assertIsArray( $deep_error );
		$this->assertTrue( $deep_error['error'] );
		$this->assertEquals( 'deep_error', $deep_error['code'] );
		$this->assertEquals( 'Error at level 3', $deep_error['message'] );
		$this->assertEquals( 3, $deep_error['data']['depth'] );
		
		// Normal value should be unchanged.
		$this->assertEquals( 'normal_value', $normalized['level1']['level2']['level3']['value'] );
		
		// Verify JSON encoding works.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Deeply normalized data should be JSON-encodable' );
	}

	/**
	 * Test recursive normalization with multiple WP_Errors.
	 */
	public function test_normalize_data_recursive_with_multiple_wp_errors() {
		// Create an array with multiple WP_Errors.
		$multiple_errors = array(
			'results' => array(
				array(
					'tool'   => 'tool_a',
					'result' => new WP_Error( 'error_a', 'Tool A failed' ),
				),
				array(
					'tool'   => 'tool_b',
					'result' => new WP_Error( 'error_b', 'Tool B failed', array( 'reason' => 'timeout' ) ),
				),
				array(
					'tool'   => 'tool_c',
					'result' => array( 'success' => true, 'data' => 'ok' ),
				),
			),
		);

		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_data_recursive' );
		$method->setAccessible( true );

		// Normalize.
		$normalized = $method->invoke( $rest_controller, $multiple_errors );

		// Check first error.
		$this->assertTrue( $normalized['results'][0]['result']['error'] );
		$this->assertEquals( 'error_a', $normalized['results'][0]['result']['code'] );
		
		// Check second error with data.
		$this->assertTrue( $normalized['results'][1]['result']['error'] );
		$this->assertEquals( 'error_b', $normalized['results'][1]['result']['code'] );
		$this->assertEquals( 'timeout', $normalized['results'][1]['result']['data']['reason'] );
		
		// Check successful result is unchanged.
		$this->assertTrue( $normalized['results'][2]['result']['success'] );
		$this->assertEquals( 'ok', $normalized['results'][2]['result']['data'] );
		
		// Verify JSON encoding works.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Data with multiple normalized errors should be JSON-encodable' );
	}

	/**
	 * Test recursive normalization with scalar values.
	 */
	public function test_normalize_data_recursive_with_scalars() {
		// Get REST controller instance using reflection.
		$rest_controller = new WP_MCP_AI_REST();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'normalize_data_recursive' );
		$method->setAccessible( true );

		// Test with string.
		$this->assertEquals( 'test string', $method->invoke( $rest_controller, 'test string' ) );
		
		// Test with integer.
		$this->assertEquals( 42, $method->invoke( $rest_controller, 42 ) );
		
		// Test with float.
		$this->assertEquals( 3.14, $method->invoke( $rest_controller, 3.14 ) );
		
		// Test with boolean.
		$this->assertTrue( $method->invoke( $rest_controller, true ) );
		$this->assertFalse( $method->invoke( $rest_controller, false ) );
		
		// Test with null.
		$this->assertNull( $method->invoke( $rest_controller, null ) );
	}

	/**
	 * Test Job Notifier's recursive normalization.
	 */
	public function test_job_notifier_normalize_data_recursive() {
		// Create test data with WP_Error.
		$result = array(
			'success'    => true,
			'nested_err' => new WP_Error(
				'nested_error',
				'This is a nested error',
				array( 'info' => 'extra' )
			),
		);

		// Use reflection to access the protected static method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Job_Notifier' );
		$method     = $reflection->getMethod( 'normalize_data_recursive' );
		$method->setAccessible( true );

		// Call the static method.
		$normalized = $method->invoke( null, $result );

		// Assert the structure.
		$this->assertTrue( $normalized['success'] );
		$this->assertTrue( $normalized['nested_err']['error'] );
		$this->assertEquals( 'nested_error', $normalized['nested_err']['code'] );
		$this->assertEquals( 'This is a nested error', $normalized['nested_err']['message'] );
		$this->assertEquals( 'extra', $normalized['nested_err']['data']['info'] );
		
		// Verify JSON encoding works.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json );
	}
}
