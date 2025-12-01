<?php
/**
 * Test Veo video tool async result sanitization.
 *
 * Verifies that async execution metadata (async, status, job_id) is preserved
 * when sanitizing results for display, ensuring the chat UI can properly
 * detect and handle async video generation.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

/**
 * Test Veo async result sanitization.
 */
class Test_Veo_Async_Sanitization extends WP_UnitTestCase {

	/**
	 * The tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Veo_Video
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Generate_Veo_Video();
	}

	/**
	 * Test that async fields are preserved during sanitization.
	 *
	 * This is critical for the JavaScript chat client to detect async execution
	 * and start polling for results. Without these fields, the UI shows
	 * "Completed" immediately instead of "Processing in background".
	 */
	public function test_sanitize_for_llm_preserves_async_fields() {
		// Mock async result from video generation service
		$async_result = array(
			'async'   => true,
			'status'  => 'pending',
			'job_id'  => 'veo_test_12345',
			'message' => 'Video generation started. Your video is being created in the background and will appear here when ready.',
		);

		$sanitized = $this->tool->sanitize_for_llm( $async_result );

		// Critical assertions: async metadata must be preserved
		$this->assertArrayHasKey( 'async', $sanitized, 'async field must be preserved for UI detection' );
		$this->assertArrayHasKey( 'status', $sanitized, 'status field must be preserved for UI detection' );
		$this->assertArrayHasKey( 'job_id', $sanitized, 'job_id field must be preserved for polling' );
		$this->assertArrayHasKey( 'message', $sanitized, 'message field should be preserved for user feedback' );

		// Verify values are unchanged
		$this->assertSame( true, $sanitized['async'], 'async flag must be true' );
		$this->assertSame( 'pending', $sanitized['status'], 'status must be pending' );
		$this->assertSame( 'veo_test_12345', $sanitized['job_id'], 'job_id must be preserved' );
	}

	/**
	 * Test that sanitization preserves completed video result metadata.
	 *
	 * When video generation completes, the result contains attachment info
	 * and metadata. These should be preserved while stripping binary data.
	 */
	public function test_sanitize_for_llm_preserves_completed_result_metadata() {
		// Mock completed result with attachment
		$completed_result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'edit_url'      => 'https://example.com/wp-admin/post.php?post=123&action=edit',
			'prompt'        => 'A cinematic video of a sunset',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-3.1-generate-preview',
			'provider'      => 'gemini',
			'message'       => 'Video generated successfully',
		);

		$sanitized = $this->tool->sanitize_for_llm( $completed_result );

		// Verify all metadata is preserved
		$this->assertArrayHasKey( 'success', $sanitized );
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'edit_url', $sanitized );
		$this->assertArrayHasKey( 'prompt', $sanitized );
		$this->assertArrayHasKey( 'duration', $sanitized );
		$this->assertArrayHasKey( 'aspect_ratio', $sanitized );
		$this->assertArrayHasKey( 'resolution', $sanitized );
		$this->assertArrayHasKey( 'model', $sanitized );
		$this->assertArrayHasKey( 'provider', $sanitized );
		$this->assertArrayHasKey( 'message', $sanitized );

		// Verify values
		$this->assertSame( 123, $sanitized['attachment_id'] );
		$this->assertSame( 'https://example.com/video.mp4', $sanitized['url'] );
		$this->assertSame( 5, $sanitized['duration'] );
	}

	/**
	 * Test that base64 video data is stripped during sanitization.
	 *
	 * When save_to_media=false, the result contains a base64 data URL
	 * which can be several megabytes. This should be stripped to avoid
	 * bloating the LLM context.
	 */
	public function test_sanitize_for_llm_strips_base64_video_data() {
		// Mock result with base64 video data URL
		$result_with_data = array(
			'success'      => true,
			'video_url'    => 'data:video/mp4;base64,AAAA...verylongbase64string...ZZZZ',
			'prompt'       => 'Test video',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		$sanitized = $this->tool->sanitize_for_llm( $result_with_data );

		// Video data URL should be stripped
		$this->assertArrayNotHasKey( 'video_url', $sanitized, 'Base64 video URL should be stripped' );
		$this->assertArrayHasKey( 'video_data_stripped', $sanitized, 'Should indicate data was stripped' );
		$this->assertTrue( $sanitized['video_data_stripped'], 'Flag should be true' );

		// Other metadata should be preserved
		$this->assertArrayHasKey( 'success', $sanitized );
		$this->assertArrayHasKey( 'prompt', $sanitized );
		$this->assertArrayHasKey( 'duration', $sanitized );
	}

	/**
	 * Test that regular HTTP video URLs are preserved.
	 *
	 * Regular URLs (not data URLs) should be kept in the sanitized result.
	 */
	public function test_sanitize_for_llm_preserves_http_video_urls() {
		// Mock result with HTTP URL (not base64 data URL)
		$result_with_url = array(
			'success'      => true,
			'video_url'    => 'https://example.com/video.mp4',
			'prompt'       => 'Test video',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		$sanitized = $this->tool->sanitize_for_llm( $result_with_url );

		// HTTP URL should be preserved (it's just a reference, not base64 data)
		// Note: video_url is not in the keep_fields list, so it will be stripped
		// This is intentional - we only keep attachment URLs and edit URLs
		$this->assertArrayNotHasKey( 'video_url', $sanitized, 'video_url is not in keep_fields list' );
	}

	/**
	 * Test that parent_job_id is preserved during sanitization.
	 *
	 * When video generation is called through async executor, there's a
	 * parent job ID that needs to be preserved for proper job completion.
	 */
	public function test_sanitize_for_llm_preserves_parent_job_id() {
		// Mock result with parent job ID
		$result = array(
			'async'         => true,
			'status'        => 'pending',
			'job_id'        => 'veo_test_12345',
			'parent_job_id' => 'async_test_67890',
			'message'       => 'Video generation started',
		);

		$sanitized = $this->tool->sanitize_for_llm( $result );

		// Parent job ID must be preserved
		$this->assertArrayHasKey( 'parent_job_id', $sanitized, 'parent_job_id must be preserved' );
		$this->assertSame( 'async_test_67890', $sanitized['parent_job_id'] );
	}

	/**
	 * Test that usage and cost data is preserved.
	 *
	 * Token usage and cost information should be kept for UI display.
	 */
	public function test_sanitize_for_llm_preserves_usage_and_cost() {
		// Mock result with usage and cost data
		$result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'usage'         => array(
				'tokens'  => 1000,
				'credits' => 5,
			),
			'cost'          => array(
				'amount'   => 0.05,
				'currency' => 'USD',
			),
		);

		$sanitized = $this->tool->sanitize_for_llm( $result );

		// Usage and cost should be preserved
		$this->assertArrayHasKey( 'usage', $sanitized );
		$this->assertArrayHasKey( 'cost', $sanitized );
		$this->assertSame(
			array(
				'tokens'  => 1000,
				'credits' => 5,
			),
			$sanitized['usage']
		);
		$this->assertSame(
			array(
				'amount'   => 0.05,
				'currency' => 'USD',
			),
			$sanitized['cost']
		);
	}

	/**
	 * Test that expected_url is used for video_url when url is not available.
	 *
	 * When video generation is pending, the result has expected_url (where
	 * the video WILL be) but no url yet (the video doesn't exist).
	 * The sanitization should use expected_url to create the video_url
	 * structure, allowing the chat client to display a placeholder video.
	 */
	public function test_sanitize_for_llm_uses_expected_url_when_url_not_available() {
		// Mock pending async result with expected_url but no url
		$pending_result = array(
			'async'             => true,
			'status'            => 'pending',
			'job_id'            => 'veo_test_12345',
			'expected_filename' => 'veo-video-veo_test_12345.mp4',
			'expected_url'      => 'https://example.com/wp-content/uploads/2024/01/veo-video-veo_test_12345.mp4',
			'message'           => 'Video generation started.',
		);

		$sanitized = $this->tool->sanitize_for_llm( $pending_result );

		// video_url structure should be created from expected_url
		$this->assertArrayHasKey( 'video_url', $sanitized, 'video_url should be created from expected_url' );
		$this->assertIsArray( $sanitized['video_url'], 'video_url should be an array structure' );
		$this->assertArrayHasKey( 'url', $sanitized['video_url'], 'video_url should have url key' );
		$this->assertSame(
			'https://example.com/wp-content/uploads/2024/01/veo-video-veo_test_12345.mp4',
			$sanitized['video_url']['url'],
			'video_url should use expected_url value'
		);

		// Other async fields should be preserved
		$this->assertTrue( $sanitized['async'] );
		$this->assertSame( 'pending', $sanitized['status'] );
		$this->assertSame( 'veo_test_12345', $sanitized['job_id'] );
		$this->assertSame( 'veo-video-veo_test_12345.mp4', $sanitized['expected_filename'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2024/01/veo-video-veo_test_12345.mp4', $sanitized['expected_url'] );
	}

	/**
	 * Test that url takes precedence over expected_url for video_url.
	 *
	 * When the video is completed, it has a real url. This should be
	 * used for video_url, even if expected_url is also present.
	 */
	public function test_sanitize_for_llm_prefers_url_over_expected_url() {
		// Mock completed result with both url and expected_url
		$completed_result = array(
			'success'           => true,
			'attachment_id'     => 123,
			'url'               => 'https://example.com/wp-content/uploads/2024/01/video.mp4',
			'expected_url'      => 'https://example.com/wp-content/uploads/2024/01/expected.mp4',
			'expected_filename' => 'expected.mp4',
			'prompt'            => 'Test video',
			'duration'          => 5,
		);

		$sanitized = $this->tool->sanitize_for_llm( $completed_result );

		// video_url should use the real url, not expected_url
		$this->assertArrayHasKey( 'video_url', $sanitized );
		$this->assertSame(
			'https://example.com/wp-content/uploads/2024/01/video.mp4',
			$sanitized['video_url']['url'],
			'video_url should use url (not expected_url) when available'
		);
	}

	/**
	 * Test that non-array results pass through unchanged.
	 *
	 * If the result is a string or other non-array type, it should
	 * be returned as-is without modification.
	 */
	public function test_sanitize_for_llm_handles_non_array_results() {
		// Test with string result
		$string_result = 'Video generation failed: API error';
		$sanitized     = $this->tool->sanitize_for_llm( $string_result );
		$this->assertSame( $string_result, $sanitized, 'String results should pass through unchanged' );

		// Test with null result
		$null_result = null;
		$sanitized   = $this->tool->sanitize_for_llm( $null_result );
		$this->assertNull( $sanitized, 'Null results should pass through unchanged' );

		// Test with numeric result
		$numeric_result = 42;
		$sanitized      = $this->tool->sanitize_for_llm( $numeric_result );
		$this->assertSame( $numeric_result, $sanitized, 'Numeric results should pass through unchanged' );
	}
}
