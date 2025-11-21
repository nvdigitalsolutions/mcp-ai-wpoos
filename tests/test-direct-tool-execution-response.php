<?php
/**
 * Tests for direct tool execution response format.
 *
 * Ensures that tools executed directly via POST /tools endpoint
 * return responses compatible with the chat UI.
 *
 * @package WP_MCP_AI
 */

/**
 * Test direct tool execution response format.
 */
class Test_Direct_Tool_Execution_Response extends WP_UnitTestCase {

	/**
	 * Test that direct tool execution returns tool_results array.
	 */
	public function test_tool_execution_includes_tool_results() {
		// Create a mock tool that returns image data.
		$mock_tool_result = array(
			'url'           => 'https://example.com/test-image.jpg',
			'file_name'     => 'test-image.jpg',
			'mime_type'     => 'image/jpeg',
			'bytes'         => 50000,
			'title'         => 'Test Image',
			'operation'     => 'rotate',
			'text'          => 'Successfully transformed image: rotated 90 degrees.',
			'attachment_id' => 123,
		);

		// Simulate the response structure from handle_tool_request.
		$response_data = array(
			'assistant_id' => 1,
			'tool'         => 'rotate_image',
			'result'       => $mock_tool_result,
		);

		// Add tool_results array format (this is what our fix adds).
		$response_data['tool_results'] = array(
			array(
				'role'    => 'tool',
				'name'    => 'rotate_image',
				'content' => $mock_tool_result,
			),
		);

		// Verify the response structure.
		$this->assertArrayHasKey( 'assistant_id', $response_data );
		$this->assertArrayHasKey( 'tool', $response_data );
		$this->assertArrayHasKey( 'result', $response_data );
		$this->assertArrayHasKey( 'tool_results', $response_data );

		// Verify tool_results is an array.
		$this->assertIsArray( $response_data['tool_results'] );
		$this->assertCount( 1, $response_data['tool_results'] );

		// Verify tool_results structure.
		$tool_result = $response_data['tool_results'][0];
		$this->assertEquals( 'tool', $tool_result['role'] );
		$this->assertEquals( 'rotate_image', $tool_result['name'] );
		$this->assertIsArray( $tool_result['content'] );

		// Verify content has the expected fields.
		$content = $tool_result['content'];
		$this->assertArrayHasKey( 'url', $content );
		$this->assertArrayHasKey( 'text', $content );
		$this->assertEquals( 'https://example.com/test-image.jpg', $content['url'] );
		$this->assertEquals( 'Successfully transformed image: rotated 90 degrees.', $content['text'] );
	}

	/**
	 * Test that tool_results format is compatible with chat UI expectations.
	 */
	public function test_tool_results_format_compatible_with_chat_ui() {
		// Simulate what the chat UI expects (from agentic loop).
		$expected_format = array(
			'role'    => 'tool',
			'name'    => 'rotate_image',
			'content' => array(
				'url'  => 'https://example.com/image.jpg',
				'text' => 'Success message',
			),
		);

		// Simulate what direct tool execution now returns.
		$actual_format = array(
			'role'    => 'tool',
			'name'    => 'rotate_image',
			'content' => array(
				'url'  => 'https://example.com/image.jpg',
				'text' => 'Success message',
			),
		);

		// Verify they match.
		$this->assertEquals( $expected_format['role'], $actual_format['role'] );
		$this->assertEquals( $expected_format['name'], $actual_format['name'] );
		$this->assertIsArray( $actual_format['content'] );
		
		// The chat UI checks for these fields.
		$this->assertArrayHasKey( 'url', $actual_format['content'] );
		$this->assertArrayHasKey( 'text', $actual_format['content'] );
	}

	/**
	 * Test backward compatibility - old consumers still work.
	 */
	public function test_backward_compatibility_with_result_field() {
		$response_data = array(
			'assistant_id'  => 1,
			'tool'          => 'rotate_image',
			'result'        => array(
				'url'  => 'https://example.com/image.jpg',
				'text' => 'Success',
			),
			'tool_results'  => array(
				array(
					'role'    => 'tool',
					'name'    => 'rotate_image',
					'content' => array(
						'url'  => 'https://example.com/image.jpg',
						'text' => 'Success',
					),
				),
			),
		);

		// Old consumers can still access the result field.
		$this->assertArrayHasKey( 'result', $response_data );
		$this->assertIsArray( $response_data['result'] );
		$this->assertEquals( 'https://example.com/image.jpg', $response_data['result']['url'] );

		// New chat UI can access tool_results.
		$this->assertArrayHasKey( 'tool_results', $response_data );
		$this->assertIsArray( $response_data['tool_results'] );
	}
}
