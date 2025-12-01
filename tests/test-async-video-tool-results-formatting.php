<?php
/**
 * Tests for async video tool results formatting in chat client responses.
 *
 * Verifies that completed async jobs include tool_results array with proper structure
 * for chat client display, including video URLs, cost badges, and usage data.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Async_Video_Tool_Results_Formatting
 */
class Test_Async_Video_Tool_Results_Formatting extends WP_UnitTestCase {

	/**
	 * Cron status service instance.
	 *
	 * @var WP_MCP_AI_Cron_Status_Service
	 */
	protected $service;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		$this->service = new WP_MCP_AI_Cron_Status_Service();
		$this->user_id = $this->factory->user->create();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_%'" );

		parent::tearDown();
	}

	/**
	 * Test that completed async video job includes tool_results array.
	 */
	public function test_completed_video_job_includes_tool_results() {
		// Create mock async job with video result.
		$job_id = 'async_test_video_' . uniqid();

		$video_result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'video_url'     => array(
				'url' => 'https://example.com/video.mp4',
			),
			'prompt'        => 'Test video',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-2.0',
			'provider'      => 'gemini',
		);

		// Store async job metadata.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'pending',
			'queued_at'    => time(),
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate job completion via Job Notifier.
		WP_MCP_AI_Job_Notifier::handle_job_completed(
			$job_id,
			$video_result,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $this->user_id,
			)
		);

		// Get job details (this triggers merge_notifier_status).
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Verify job_details includes tool_results array.
		$this->assertIsArray( $job_details, 'Job details should be an array' );
		$this->assertEquals( 'completed', $job_details['status'], 'Job status should be completed' );
		$this->assertArrayHasKey( 'tool_results', $job_details, 'Job details should include tool_results array' );
		$this->assertIsArray( $job_details['tool_results'], 'tool_results should be an array' );
		$this->assertCount( 1, $job_details['tool_results'], 'tool_results should have 1 item' );

		// Verify tool_results structure matches OpenAI format.
		$tool_result = $job_details['tool_results'][0];
		$this->assertEquals( 'tool', $tool_result['role'], 'Tool result role should be "tool"' );
		$this->assertEquals( 'generate_veo_video', $tool_result['name'], 'Tool result name should be "generate_veo_video"' );
		$this->assertNotEmpty( $tool_result['tool_call_id'], 'Tool call ID should not be empty' );
		// Tool call ID could be either original (from LLM) or fallback (async_ prefix).
		// Since this test doesn't provide tool_call_id in context, it should use fallback.
		$this->assertStringStartsWith( 'async_generate_veo_video_', $tool_result['tool_call_id'], 'Tool call ID should have async prefix when no original provided' );
		$this->assertStringContainsString( $job_id, $tool_result['tool_call_id'], 'Tool call ID should contain job ID when using fallback' );

		// Verify content is properly serialized JSON.
		$this->assertJson( $tool_result['content'], 'Tool result content should be valid JSON' );
		$content = json_decode( $tool_result['content'], true );
		$this->assertIsArray( $content, 'Decoded content should be an array' );
		$this->assertEquals( 'https://example.com/video.mp4', $content['url'], 'Content should include video URL' );
		$this->assertArrayHasKey( 'video_url', $content, 'Content should include video_url structure' );
		$this->assertEquals( 'https://example.com/video.mp4', $content['video_url']['url'], 'video_url.url should match main URL' );
	}

	/**
	 * Test that completed async job includes usage and cost data for badges.
	 */
	public function test_completed_job_includes_usage_and_cost_data() {
		// Create mock async job with usage and cost data.
		$job_id = 'async_test_cost_' . uniqid();

		$video_result = array(
			'success' => true,
			'url'     => 'https://example.com/video.mp4',
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'cost'    => array(
				'cost_usd'     => 0.0025,
				'provider'     => 'gemini',
				'model'        => 'veo-2.0',
				'is_estimated' => false,
			),
		);

		// Store async job metadata.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'pending',
			'queued_at'    => time(),
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate job completion via Job Notifier.
		WP_MCP_AI_Job_Notifier::handle_job_completed(
			$job_id,
			$video_result,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $this->user_id,
			)
		);

		// Get job details.
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Verify tool_results includes usage data.
		$this->assertArrayHasKey( 'tool_results', $job_details, 'Job details should include tool_results' );
		$tool_result = $job_details['tool_results'][0];
		$this->assertArrayHasKey( 'usage', $tool_result, 'Tool result should include usage data' );
		$this->assertEquals( 150, $tool_result['usage']['total_tokens'], 'Usage total_tokens should match' );

		// Verify tool_results includes cost data.
		$this->assertArrayHasKey( 'cost', $tool_result, 'Tool result should include cost data' );
		$this->assertEquals( 0.0025, $tool_result['cost']['cost_usd'], 'Cost should match' );
		$this->assertEquals( 'gemini', $tool_result['cost']['provider'], 'Provider should match' );

		// Verify top-level cost for aggregated display.
		$this->assertArrayHasKey( 'cost', $job_details, 'Job details should include top-level cost' );
		$this->assertEquals( 0.0025, $job_details['cost']['cost_usd'], 'Top-level cost should match' );
	}

	/**
	 * Test that pending async job does NOT include tool_results.
	 */
	public function test_pending_job_does_not_include_tool_results() {
		// Create mock async job that's still pending.
		$job_id = 'async_test_pending_' . uniqid();

		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'pending',
			'queued_at'    => time(),
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Get job details (no completion, still pending).
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Verify job_details does NOT include tool_results for pending job.
		$this->assertIsArray( $job_details, 'Job details should be an array' );
		$this->assertEquals( 'pending', $job_details['status'], 'Job status should be pending' );
		$this->assertArrayNotHasKey( 'tool_results', $job_details, 'Pending job should not include tool_results' );
	}

	/**
	 * Test that failed async job does NOT include tool_results.
	 */
	public function test_failed_job_does_not_include_tool_results() {
		// Create mock async job that failed.
		$job_id = 'async_test_failed_' . uniqid();

		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'pending',
			'queued_at'    => time(),
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate job failure via Job Notifier.
		WP_MCP_AI_Job_Notifier::handle_job_failed(
			$job_id,
			new WP_Error( 'video_generation_failed', 'Video generation failed' ),
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $this->user_id,
			)
		);

		// Get job details.
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Verify job_details does NOT include tool_results for failed job.
		$this->assertIsArray( $job_details, 'Job details should be an array' );
		$this->assertEquals( 'failed', $job_details['status'], 'Job status should be failed' );
		$this->assertArrayNotHasKey( 'tool_results', $job_details, 'Failed job should not include tool_results' );
		$this->assertArrayHasKey( 'error', $job_details, 'Failed job should include error' );
	}

	/**
	 * Test that notifier-driven completions apply sanitize_for_llm before creating tool_results.
	 *
	 * This test verifies the fix for the issue where Job Notifier completions were
	 * creating tool_results without first running the result through sanitize_for_llm().
	 * For tools like generate_veo_video that rely on the sanitizer to add display
	 * structures (video_url), this was causing videos to not display in the chat UI.
	 */
	public function test_notifier_completion_applies_sanitization() {
		// Create mock async job for a tool that implements sanitize_for_llm.
		$job_id = 'async_test_sanitization_' . uniqid();

		// Raw video result WITHOUT video_url structure (as would come from API).
		// The sanitize_for_llm() method should add the video_url structure.
		$raw_video_result = array(
			'success'       => true,
			'attachment_id' => 456,
			'url'           => 'https://example.com/test-video.mp4',
			'prompt'        => 'Test video for sanitization',
			'duration'      => 8,
			'aspect_ratio'  => '16:9',
			'resolution'    => '1080p',
			'model'         => 'veo-3.1',
			'provider'      => 'gemini',
			// Note: NO video_url structure yet - sanitizer should add it.
		);

		// Store async job metadata.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'pending',
			'queued_at'    => time(),
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate job completion via Job Notifier with raw result.
		WP_MCP_AI_Job_Notifier::handle_job_completed(
			$job_id,
			$raw_video_result,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $this->user_id,
			)
		);

		// Get job details - this should trigger sanitization before creating tool_results.
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Verify sanitization was applied by checking for video_url structure.
		$this->assertIsArray( $job_details, 'Job details should be an array' );
		$this->assertEquals( 'completed', $job_details['status'], 'Job status should be completed' );

		// Verify result was sanitized (has video_url structure added by sanitize_for_llm).
		$this->assertArrayHasKey( 'result', $job_details, 'Job details should have result' );
		$this->assertArrayHasKey( 'video_url', $job_details['result'], 'Sanitized result should have video_url structure' );
		$this->assertIsArray( $job_details['result']['video_url'], 'video_url should be an array' );
		$this->assertEquals( 'https://example.com/test-video.mp4', $job_details['result']['video_url']['url'], 'video_url.url should match main URL' );

		// Verify tool_results includes the sanitized content.
		$this->assertArrayHasKey( 'tool_results', $job_details, 'Job details should have tool_results' );
		$tool_result = $job_details['tool_results'][0];

		// Decode the content to verify sanitized data is present.
		$content = json_decode( $tool_result['content'], true );
		$this->assertIsArray( $content, 'Tool result content should be valid JSON array' );
		$this->assertArrayHasKey( 'video_url', $content, 'Tool result content should have video_url structure from sanitizer' );
		$this->assertEquals( 'https://example.com/test-video.mp4', $content['video_url']['url'], 'Sanitized video_url should be in tool result content' );
	}
}
