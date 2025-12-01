<?php
/**
 * Test tool result sanitization for display to ensure base64 content is stripped.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-extract-video-frames.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

/**
 * Test that tool result sanitization strips base64 content for display.
 */
class WP_MCP_AI_Tool_Result_Display_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that OpenAI image tool results are sanitized for display.
	 */
	public function test_openai_image_tool_display_sanitization() {
		$tool      = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a tool result with base64 content and usage/cost data.
		$tool_result = array(
			'attachment_id'   => 123,
			'url'             => 'https://example.com/image.png',
			'file_name'       => 'test-image.png',
			'mime_type'       => 'image/png',
			'bytes'           => 1024,
			'format'          => 'png',
			'size'            => '1024x1024',
			'quality'         => 'medium',
			'model'           => 'gpt-image-1',
			'provider'        => 'openai',
			'response_format' => 'b64_json',
			'text'            => 'Successfully generated image (ID: 123).',
			'usage'           => array(
				'prompt_tokens'     => 42,
				'completion_tokens' => 2048,
				'total_tokens'      => 2090,
				'is_estimated'      => true,
			),
			'cost'            => array(
				'cost_usd'     => 0.042,
				'is_estimated' => true,
				'provider'     => 'openai',
				'model'        => 'gpt-image-1',
			),
			'content'         => array(
				'encoding'  => 'base64',
				'data'      => str_repeat( 'A', 100000 ), // Large base64 string
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,' . str_repeat( 'A', 100000 ),
				'file_name' => 'test-image.png',
				'bytes'     => 95,
			),
		);

		// Sanitize for display.
		$display_result = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_openai_image', $tool );

		// Verify base64 content was stripped.
		$this->assertIsArray( $display_result, 'Display result should be an array' );
		$this->assertArrayNotHasKey( 'content', $display_result, 'Base64 content should be removed for display' );

		// Verify essential metadata is preserved.
		$this->assertArrayHasKey( 'attachment_id', $display_result );
		$this->assertArrayHasKey( 'url', $display_result );
		$this->assertArrayHasKey( 'file_name', $display_result );
		$this->assertArrayHasKey( 'text', $display_result );
		$this->assertEquals( 123, $display_result['attachment_id'] );
		$this->assertEquals( 'https://example.com/image.png', $display_result['url'] );

		// Verify usage/cost data is preserved (critical for UI token/cost tracking)
		$this->assertArrayHasKey( 'usage', $display_result, 'Usage data should be preserved for UI display' );
		$this->assertArrayHasKey( 'cost', $display_result, 'Cost data should be preserved for UI display' );
		$this->assertEquals( 2090, $display_result['usage']['total_tokens'] );
		$this->assertEquals( 0.042, $display_result['cost']['cost_usd'] );
	}

	/**
	 * Test that Veo video tool results are sanitized for display.
	 */
	public function test_veo_video_tool_display_sanitization() {
		$tool      = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a tool result with base64 video data URL.
		$tool_result = array(
			'success'      => true,
			'video_url'    => 'data:video/mp4;base64,' . str_repeat( 'B', 500000 ), // Large base64 video
			'prompt'       => 'A cat playing piano',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1',
			'provider'     => 'gemini',
			'message'      => 'Video generated successfully (temporary - not saved to Media Library).',
		);

		// Sanitize for display.
		$display_result = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_veo_video', $tool );

		// Verify base64 video data URL was stripped.
		$this->assertIsArray( $display_result, 'Display result should be an array' );
		$this->assertArrayNotHasKey( 'video_url', $display_result, 'Base64 video URL should be removed for display' );
		$this->assertArrayHasKey( 'video_data_stripped', $display_result, 'Should flag that video data was stripped' );
		$this->assertTrue( $display_result['video_data_stripped'] );

		// Verify essential metadata is preserved.
		$this->assertArrayHasKey( 'success', $display_result );
		$this->assertArrayHasKey( 'prompt', $display_result );
		$this->assertArrayHasKey( 'model', $display_result );
		$this->assertEquals( 'veo-3.1', $display_result['model'] );
	}

	/**
	 * Test that extract video frames tool results are sanitized for display.
	 */
	public function test_extract_video_frames_tool_display_sanitization() {
		$tool      = new WP_MCP_AI_Tool_Extract_Video_Frames();
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a tool result with base64 frame data.
		$tool_result = array(
			'video_url' => 'https://example.com/video.mp4',
			'frames'    => array(
				'data:image/png;base64,' . str_repeat( 'C', 50000 ),
				'data:image/png;base64,' . str_repeat( 'D', 50000 ),
				'data:image/png;base64,' . str_repeat( 'E', 50000 ),
			),
			'message'   => 'Successfully extracted 3 frames (temporary - not saved to Media Library).',
		);

		// Sanitize for display.
		$display_result = $validator->sanitize_tool_result_for_display( $tool_result, 'extract_video_frames', $tool );

		// Verify base64 frame data was stripped.
		$this->assertIsArray( $display_result, 'Display result should be an array' );
		$this->assertArrayNotHasKey( 'frames', $display_result, 'Base64 frames should be removed for display' );
		$this->assertArrayHasKey( 'frames_data_stripped', $display_result, 'Should flag that frames data was stripped' );
		$this->assertArrayHasKey( 'frame_count', $display_result, 'Should include frame count' );
		$this->assertEquals( 3, $display_result['frame_count'] );

		// Verify essential metadata is preserved.
		$this->assertArrayHasKey( 'video_url', $display_result );
		$this->assertArrayHasKey( 'message', $display_result );
	}

	/**
	 * Test that video tool with saved attachment IDs is NOT sanitized.
	 */
	public function test_veo_video_tool_with_attachment_not_sanitized() {
		$tool      = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a tool result where video was saved to media (no base64)
		$tool_result = array(
			'success'       => true,
			'attachment_id' => 456,
			'url'           => 'https://example.com/wp-content/uploads/2025/11/video.mp4',
			'prompt'        => 'A cat playing piano',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-3.1',
			'provider'      => 'gemini',
			'message'       => 'Video generated successfully and saved as attachment ID 456.',
		);

		// Sanitize for display.
		$display_result = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_veo_video', $tool );

		// Verify all fields are preserved (no base64 to strip)
		$this->assertIsArray( $display_result, 'Display result should be an array' );
		$this->assertArrayHasKey( 'url', $display_result, 'URL should be preserved' );
		$this->assertArrayHasKey( 'attachment_id', $display_result, 'Attachment ID should be preserved' );
		$this->assertEquals( 456, $display_result['attachment_id'] );
		$this->assertEquals( 'https://example.com/wp-content/uploads/2025/11/video.mp4', $display_result['url'] );
	}

	/**
	 * Test that frame extraction with saved attachment IDs is NOT sanitized.
	 */
	public function test_extract_frames_with_attachments_not_sanitized() {
		$tool      = new WP_MCP_AI_Tool_Extract_Video_Frames();
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a tool result where frames were saved to media (attachment IDs, not base64)
		$tool_result = array(
			'video_url' => 'https://example.com/video.mp4',
			'frames'    => array( 101, 102, 103 ), // Attachment IDs
			'message'   => 'Successfully extracted and saved 3 frames to Media Library.',
		);

		// Sanitize for display.
		$display_result = $validator->sanitize_tool_result_for_display( $tool_result, 'extract_video_frames', $tool );

		// Verify frames are preserved (they're attachment IDs, not base64)
		$this->assertIsArray( $display_result, 'Display result should be an array' );
		$this->assertArrayHasKey( 'frames', $display_result, 'Frames (attachment IDs) should be preserved' );
		$this->assertCount( 3, $display_result['frames'] );
		$this->assertEquals( array( 101, 102, 103 ), $display_result['frames'] );
	}
}
