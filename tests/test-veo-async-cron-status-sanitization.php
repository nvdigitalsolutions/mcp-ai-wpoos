<?php
/**
 * Test Veo video async result sanitization in cron-status service.
 *
 * Verifies that when async video generation completes and the result is
 * retrieved via /cron-status endpoint, the tool's sanitize_for_llm()
 * method is properly applied to add video_url structure for chat client.
 *
 * This test addresses the bug where videos generated via async executor
 * were not being returned to the chat client with OpenAI as provider.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';

/**
 * Test cron-status service sanitization of async video results.
 */
class Test_Veo_Async_Cron_Status_Sanitization extends WP_UnitTestCase {

	/**
	 * The cron status service instance.
	 *
	 * @var WP_MCP_AI_Cron_Status_Service
	 */
	protected $cron_status_service;

	/**
	 * The tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize tool registry and register the veo tool.
		$this->tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->tool_registry->register_tool( new WP_MCP_AI_Tool_Generate_Veo_Video() );

		// Initialize cron status service.
		$this->cron_status_service = new WP_MCP_AI_Cron_Status_Service();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up test transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional cleanup in test teardown.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%wp_mcp_ai_async%'" );
	}

	/**
	 * Test that async video result gets video_url structure added.
	 *
	 * This is the core fix: when an async tool result is retrieved via
	 * get_job_details(), the tool's sanitize_for_llm() should be called
	 * to add the video_url structure required by the chat client.
	 */
	public function test_get_job_details_adds_video_url_structure_for_async_video() {
		// Create a mock async tool execution result (completed video).
		$job_id = 'async_test_video_' . uniqid( '', true );

		// This is what the video generation service stores in the async executor.
		$video_result = array(
			'success'       => true,
			'attachment_id' => 456,
			'url'           => 'https://example.com/wp-content/uploads/2025/11/veo-video-test.mp4',
			'file_name'     => 'veo-video-test.mp4',
			'edit_url'      => 'https://example.com/wp-admin/post.php?post=456&action=edit',
			'prompt'        => 'A cinematic video of a sunset over mountains',
			'duration'      => 5,
			'aspect_ratio'  => '3:2',
			'resolution'    => '720p',
			'model'         => 'veo-3.1-generate-preview',
			'provider'      => 'gemini',
			'message'       => 'Video generated successfully',
			'text'          => 'Successfully generated video (ID: 456). Format: 5s, 720p, 16:9',
		);

		// Store in transient as async executor does.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'status'       => 'completed',
			'completed_at' => time(),
			'context'      => array(
				'user_id'      => 1,
				'assistant_id' => 1,
			),
			'result'       => array(
				'compressed' => false,
				'data'       => $video_result,
			),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Call get_job_details() as the REST endpoint does.
		$result = $this->cron_status_service->get_job_details( $job_id, 1 );

		// Verify result was retrieved.
		$this->assertIsArray( $result, 'Should return array result' );
		$this->assertArrayHasKey( 'status', $result, 'Should have status' );
		$this->assertSame( 'completed', $result['status'], 'Status should be completed' );

		// Critical assertion: video_url structure should be added by sanitize_for_llm().
		$this->assertArrayHasKey( 'result', $result, 'Should have result field' );
		$this->assertIsArray( $result['result'], 'Result should be array' );
		$this->assertArrayHasKey( 'video_url', $result['result'], 'Result should have video_url structure added by sanitization' );

		// Verify video_url structure format.
		$this->assertIsArray( $result['result']['video_url'], 'video_url should be array structure' );
		$this->assertArrayHasKey( 'url', $result['result']['video_url'], 'video_url should have url field' );
		$this->assertSame(
			'https://example.com/wp-content/uploads/2025/11/veo-video-test.mp4',
			$result['result']['video_url']['url'],
			'video_url.url should contain the video URL'
		);

		// Verify other metadata is preserved.
		$this->assertArrayHasKey( 'attachment_id', $result['result'], 'Should preserve attachment_id' );
		$this->assertArrayHasKey( 'duration', $result['result'], 'Should preserve duration' );
		$this->assertArrayHasKey( 'resolution', $result['result'], 'Should preserve resolution' );
	}

	/**
	 * Test that pending async jobs don't get sanitization applied.
	 *
	 * Sanitization should only be applied to completed jobs with results.
	 */
	public function test_get_job_details_skips_sanitization_for_pending_jobs() {
		// Create a mock pending async job.
		$job_id = 'async_test_pending_' . uniqid( '', true );

		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'generate_veo_video',
			'status'    => 'pending',
			'context'   => array(
				'user_id' => 1,
			),
			// No result yet - job is still pending.
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Call get_job_details().
		$result = $this->cron_status_service->get_job_details( $job_id, 1 );

		// Verify result was retrieved.
		$this->assertIsArray( $result, 'Should return array result' );
		$this->assertArrayHasKey( 'status', $result, 'Should have status' );
		$this->assertSame( 'pending', $result['status'], 'Status should be pending' );

		// Should not have result field (job not complete).
		$this->assertArrayNotHasKey( 'result', $result, 'Pending job should not have result' );
	}

	/**
	 * Test that failed async jobs don't get sanitization applied.
	 *
	 * Failed jobs may have error info but no successful result to sanitize.
	 */
	public function test_get_job_details_skips_sanitization_for_failed_jobs() {
		// Create a mock failed async job.
		$job_id = 'async_test_failed_' . uniqid( '', true );

		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'generate_veo_video',
			'status'    => 'failed',
			'error'     => 'Video generation timed out',
			'context'   => array(
				'user_id' => 1,
			),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Call get_job_details().
		$result = $this->cron_status_service->get_job_details( $job_id, 1 );

		// Verify result was retrieved.
		$this->assertIsArray( $result, 'Should return array result' );
		$this->assertArrayHasKey( 'status', $result, 'Should have status' );
		$this->assertSame( 'failed', $result['status'], 'Status should be failed' );
	}

	/**
	 * Test that sanitization works for tools without LLM sanitizer interface.
	 *
	 * If a tool doesn't implement WP_MCP_AI_Tool_LLM_Sanitizer_Interface,
	 * the result should be returned as-is without errors.
	 */
	public function test_get_job_details_handles_tools_without_sanitizer_interface() {
		// Create a mock async job for a tool that doesn't implement sanitizer.
		$job_id = 'async_test_nosani_' . uniqid( '', true );

		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'some_tool_without_sanitizer',
			'status'    => 'completed',
			'context'   => array(
				'user_id' => 1,
			),
			'result'    => array(
				'compressed' => false,
				'data'       => array(
					'success' => true,
					'data'    => 'Some tool result',
				),
			),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Call get_job_details() - should not error even without sanitizer.
		$result = $this->cron_status_service->get_job_details( $job_id, 1 );

		// Verify result was retrieved without errors.
		$this->assertIsArray( $result, 'Should return array result' );
		$this->assertArrayHasKey( 'status', $result, 'Should have status' );
		$this->assertSame( 'completed', $result['status'], 'Status should be completed' );
		$this->assertArrayHasKey( 'result', $result, 'Should have result' );
	}
}
