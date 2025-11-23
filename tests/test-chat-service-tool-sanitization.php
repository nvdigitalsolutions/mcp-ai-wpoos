<?php
/**
 * Tests for Chat Service tool result sanitization.
 *
 * Verifies that the chat service properly sanitizes tool results
 * before sending them to the LLM, preventing timeout errors from
 * oversized payloads (e.g., base64 image data).
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat service tool result sanitization.
 */
class WP_MCP_AI_Chat_Service_Tool_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Check if Chat Service is available.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Chat_Service' ) ) {
			$this->markTestSkipped( 'Chat Service not available' );
		}
	}

	/**
	 * Create a chat service instance with mock dependencies.
	 *
	 * @return WP_MCP_AI_Chat_Service
	 */
	private function create_chat_service() {
		$router               = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rate_limiter         = $this->createMock( WP_MCP_AI_Rate_Limit_Manager::class );
		$token_budget_manager = $this->createMock( WP_MCP_AI_Token_Budget_Manager::class );
		$tool_registry        = WP_MCP_AI_Tool_Registry::get_instance();

		return new WP_MCP_AI_Chat_Service(
			$router,
			$rate_limiter,
			$token_budget_manager,
			$tool_registry
		);
	}

	/**
	 * Test that generate_openai_image results are sanitized in chat flow.
	 *
	 * This test verifies that when generate_openai_image tool is executed
	 * in the chat flow, large base64 image data is stripped from the result
	 * before being sent to the LLM.
	 */
	public function test_generate_openai_image_sanitization_in_chat_flow() {
		$chat_service = $this->create_chat_service();

		// Use reflection to access private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Create a mock tool result with large base64 data (simulating generate_openai_image output).
		$mock_result = array(
			'attachment_id'   => 123,
			'url'             => 'https://example.com/image.png',
			'file_name'       => 'generated-image.png',
			'mime_type'       => 'image/png',
			'bytes'           => 50000,
			'size'            => '1024x1024',
			'quality'         => 'medium',
			'format'          => 'png',
			'model'           => 'gpt-image-1',
			'revised_prompt'  => 'A beautiful landscape scene',
			'text'            => 'Successfully generated image (ID: 123).',
			'content'         => array(
				'encoding'  => 'base64',
				'data'      => str_repeat( 'A', 100000 ), // 100KB of base64 data.
				'data_url'  => 'data:image/png;base64,' . str_repeat( 'A', 100000 ),
				'mime_type' => 'image/png',
				'file_name' => 'generated-image.png',
				'bytes'     => 50000,
			),
			'image_url'       => array(
				'url' => 'https://example.com/image.png',
			),
		);

		// Call sanitization method.
		$sanitized = $method->invoke( $chat_service, $mock_result, 'generate_openai_image', array() );

		// Verify that large base64 data was stripped.
		$this->assertArrayNotHasKey( 'data', $sanitized['content'] ?? array(), 'Base64 data should be stripped' );
		$this->assertArrayNotHasKey( 'data_url', $sanitized['content'] ?? array(), 'Data URL should be stripped' );

		// Verify that essential metadata is preserved.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertEquals( 123, $sanitized['attachment_id'] );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertEquals( 'https://example.com/image.png', $sanitized['url'] );
		$this->assertArrayHasKey( 'file_name', $sanitized );
		$this->assertArrayHasKey( 'mime_type', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );

		// Verify that image_url structure is preserved for vision models.
		$this->assertArrayHasKey( 'image_url', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized['image_url'] );
		$this->assertEquals( 'https://example.com/image.png', $sanitized['image_url']['url'] );
	}

	/**
	 * Test that tools without custom sanitization still work.
	 */
	public function test_tools_without_sanitization_interface_work() {
		$chat_service = $this->create_chat_service();

		// Use reflection to access private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Create a simple tool result.
		$mock_result = array(
			'status'  => 'success',
			'message' => 'Operation completed',
			'data'    => array(
				'id'   => 456,
				'name' => 'Test Item',
			),
		);

		// Call sanitization method with a tool that doesn't implement sanitization.
		$sanitized = $method->invoke( $chat_service, $mock_result, 'some_other_tool', array() );

		// Result should be unchanged (no sanitization applied).
		$this->assertEquals( $mock_result, $sanitized );
	}

	/**
	 * Test that WP_Error results are not sanitized.
	 */
	public function test_wp_error_results_not_sanitized() {
		$chat_service = $this->create_chat_service();

		// Use reflection to access private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Create a WP_Error result.
		$error_result = new WP_Error( 'test_error', 'Test error message' );

		// Call sanitization method.
		$sanitized = $method->invoke( $chat_service, $error_result, 'generate_openai_image', array() );

		// WP_Error should be returned as-is.
		$this->assertInstanceOf( WP_Error::class, $sanitized );
		$this->assertEquals( 'test_error', $sanitized->get_error_code() );
	}

	/**
	 * Test that filters are applied during sanitization.
	 */
	public function test_sanitization_filters_applied() {
		$chat_service = $this->create_chat_service();

		// Add filter to modify result (filter receives 3 params: result, tool_name, assistant_config).
		$filter_called = false;
		$filter        = function ( $result, $tool_name, $assistant_config ) use ( &$filter_called ) {
			$filter_called = true;
			if ( is_array( $result ) ) {
				$result['filtered'] = true;
			}
			return $result;
		};
		add_filter( 'wp_mcp_ai_sanitize_tool_result_llm', $filter, 10, 3 );

		// Use reflection to access private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Create a simple result.
		$mock_result = array( 'status' => 'success' );

		// Call sanitization method.
		$sanitized = $method->invoke( $chat_service, $mock_result, 'test_tool', array() );

		// Verify filter was called.
		$this->assertTrue( $filter_called, 'Filter should have been called' );
		$this->assertArrayHasKey( 'filtered', $sanitized );
		$this->assertTrue( $sanitized['filtered'] );

		// Clean up.
		remove_filter( 'wp_mcp_ai_sanitize_tool_result_llm', $filter );
	}
}
