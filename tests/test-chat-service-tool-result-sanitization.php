<?php
/**
 * Tests for Chat Service tool result sanitization.
 *
 * Verifies that tool results are properly sanitized before being sent to the LLM
 * in the agentic loop, while preserving the full result for frontend display.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-service.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';

/**
 * Tests for Chat Service tool result sanitization in agentic loop.
 */
class WP_MCP_AI_Chat_Service_Tool_Result_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that tool results are sanitized for LLM when they contain large base64 content.
	 *
	 * This test verifies the fix for the issue where Gemini image tool results with
	 * large base64 content were being sent to OpenAI, causing errors or no response.
	 */
	public function test_tool_result_with_base64_is_sanitized_for_llm() {
		// Create a mock tool result with base64 content (simulating generate_gemini_image output).
		$tool_result = array(
			'role'         => 'tool',
			'tool_call_id' => 'call_123',
			'name'         => 'generate_gemini_image',
			'content'      => wp_json_encode(
				array(
					'attachment_id' => 123,
					'url'           => 'https://example.com/image.png',
					'download_url'  => 'https://example.com/download/image.png',
					'file_name'     => 'image.png',
					'mime_type'     => 'image/png',
					'bytes'         => 50000,
					'text'          => 'Successfully generated image "Test Image" (ID: 123).',
					'content'       => array(
						'encoding'  => 'base64',
						'data'      => str_repeat( 'A', 10000 ), // Large base64 string
						'mime_type' => 'image/png',
						'data_url'  => 'data:image/png;base64,' . str_repeat( 'A', 10000 ),
					),
				)
			),
		);

		// Create mocks for dependencies.
		$router               = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rate_limiter         = $this->createMock( WP_MCP_AI_Rate_Limit_Manager::class );
		$token_budget_manager = $this->createMock( WP_MCP_AI_Token_Budget_Manager::class );
		$tool_registry        = $this->createMock( WP_MCP_AI_Tool_Registry::class );

		// Configure tool registry to return a generate_gemini_image tool instance.
		$gemini_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$tool_registry->method( 'is_tool_registered' )
			->with( 'generate_gemini_image' )
			->willReturn( true );
		$tool_registry->method( 'get_tool' )
			->with( 'generate_gemini_image' )
			->willReturn( $gemini_tool );

		// Create chat service instance.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$router,
			$rate_limiter,
			$token_budget_manager,
			$tool_registry
		);

		// Use reflection to call the private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Sanitize the tool result.
		$sanitized = $method->invoke( $chat_service, $tool_result, array() );

		// Verify the result is an array.
		$this->assertIsArray( $sanitized, 'Sanitized result should be an array' );

		// Verify the tool_call_id and name are preserved.
		$this->assertEquals( 'call_123', $sanitized['tool_call_id'], 'tool_call_id should be preserved' );
		$this->assertEquals( 'generate_gemini_image', $sanitized['name'], 'name should be preserved' );

		// Decode the sanitized content.
		$content = json_decode( $sanitized['content'], true );
		$this->assertIsArray( $content, 'Sanitized content should be JSON-decodable' );

		// Verify essential fields are preserved.
		$this->assertEquals( 123, $content['attachment_id'], 'attachment_id should be preserved' );
		$this->assertEquals( 'https://example.com/image.png', $content['url'], 'url should be preserved' );
		$this->assertEquals( 'https://example.com/download/image.png', $content['download_url'], 'download_url should be preserved' );
		$this->assertStringContainsString( 'Successfully generated image', $content['text'], 'text should be preserved' );

		// Verify base64 content fields are removed (sanitized).
		$this->assertArrayNotHasKey( 'data', $content['content'] ?? array(), 'base64 data should be stripped' );
		$this->assertArrayNotHasKey( 'data_url', $content['content'] ?? array(), 'data_url should be stripped' );

		// Verify image_url structure is added for agentic loop.
		$this->assertArrayHasKey( 'image_url', $content, 'image_url should be added for vision models' );
		$this->assertIsArray( $content['image_url'], 'image_url should be an array' );
		$this->assertEquals( 'https://example.com/download/image.png', $content['image_url']['url'], 'image_url should contain download_url' );
	}

	/**
	 * Test that non-sanitizable tool results pass through unchanged.
	 */
	public function test_tool_result_without_sanitizer_passes_through() {
		// Create a simple tool result without a sanitizer interface.
		$tool_result = array(
			'role'         => 'tool',
			'tool_call_id' => 'call_456',
			'name'         => 'unknown_tool',
			'content'      => wp_json_encode(
				array(
					'result'  => 'success',
					'message' => 'Operation completed',
				)
			),
		);

		// Create mocks for dependencies.
		$router               = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rate_limiter         = $this->createMock( WP_MCP_AI_Rate_Limit_Manager::class );
		$token_budget_manager = $this->createMock( WP_MCP_AI_Token_Budget_Manager::class );
		$tool_registry        = $this->createMock( WP_MCP_AI_Tool_Registry::class );

		// Configure tool registry to indicate the tool is not registered.
		$tool_registry->method( 'is_tool_registered' )
			->with( 'unknown_tool' )
			->willReturn( false );

		// Create chat service instance.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$router,
			$rate_limiter,
			$token_budget_manager,
			$tool_registry
		);

		// Use reflection to call the private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Sanitize the tool result.
		$sanitized = $method->invoke( $chat_service, $tool_result, array() );

		// Verify the result structure is preserved.
		$this->assertIsArray( $sanitized, 'Sanitized result should be an array' );
		$this->assertEquals( 'call_456', $sanitized['tool_call_id'], 'tool_call_id should be preserved' );
		$this->assertEquals( 'unknown_tool', $sanitized['name'], 'name should be preserved' );

		// Decode and verify content.
		$content = json_decode( $sanitized['content'], true );
		$this->assertIsArray( $content, 'Content should be JSON-decodable' );
		$this->assertEquals( 'success', $content['result'], 'result should be preserved' );
		$this->assertEquals( 'Operation completed', $content['message'], 'message should be preserved' );
	}

	/**
	 * Test that empty or invalid tool results are handled safely.
	 */
	public function test_invalid_tool_result_handled_safely() {
		// Create mocks for dependencies.
		$router               = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rate_limiter         = $this->createMock( WP_MCP_AI_Rate_Limit_Manager::class );
		$token_budget_manager = $this->createMock( WP_MCP_AI_Token_Budget_Manager::class );
		$tool_registry        = $this->createMock( WP_MCP_AI_Tool_Registry::class );

		// Create chat service instance.
		$chat_service = new WP_MCP_AI_Chat_Service(
			$router,
			$rate_limiter,
			$token_budget_manager,
			$tool_registry
		);

		// Use reflection to call the private sanitize_tool_result_for_llm method.
		$reflection = new ReflectionClass( $chat_service );
		$method     = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		// Test with empty array.
		$empty_result    = array();
		$sanitized_empty = $method->invoke( $chat_service, $empty_result, array() );
		$this->assertIsArray( $sanitized_empty, 'Empty result should return an array' );

		// Test with result missing content.
		$no_content_result    = array(
			'role'         => 'tool',
			'tool_call_id' => 'call_789',
			'name'         => 'test_tool',
		);
		$sanitized_no_content = $method->invoke( $chat_service, $no_content_result, array() );
		$this->assertIsArray( $sanitized_no_content, 'Result without content should return as-is' );
		$this->assertEquals( 'call_789', $sanitized_no_content['tool_call_id'], 'tool_call_id should be preserved' );
	}
}
