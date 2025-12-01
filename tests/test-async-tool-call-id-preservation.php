<?php
/**
 * Tests for async tool call ID preservation.
 *
 * Verifies that the original tool_call_id from the LLM is preserved
 * in async job execution and returned in the completion response.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Async_Tool_Call_ID_Preservation
 */
class Test_Async_Tool_Call_ID_Preservation extends WP_UnitTestCase {

	/**
	 * Cron status service instance.
	 *
	 * @var WP_MCP_AI_Cron_Status_Service
	 */
	protected $service;

	/**
	 * Async executor instance.
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor
	 */
	protected $executor;

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

		$this->service  = new WP_MCP_AI_Cron_Status_Service();
		$this->executor = new WP_MCP_AI_Tool_Async_Executor();
		$this->user_id  = $this->factory->user->create();
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
	 * Test that tool_call_id from context is stored in async job metadata.
	 */
	public function test_tool_call_id_stored_in_async_job_metadata() {
		$tool_call_id = 'call_test123456789';

		// Queue async job with tool_call_id in context.
		$job_id = $this->executor->queue_tool(
			'generate_veo_video',
			array( 'prompt' => 'Test video' ),
			array(
				'user_id'      => $this->user_id,
				'tool_call_id' => $tool_call_id,
			)
		);

		$this->assertNotWPError( $job_id, 'Job should be queued successfully' );

		// Retrieve job metadata.
		$metadata = get_transient( 'wp_mcp_ai_async_meta_' . $job_id );

		$this->assertIsArray( $metadata, 'Job metadata should exist' );
		$this->assertArrayHasKey( 'context', $metadata, 'Job metadata should include context' );
		$this->assertArrayHasKey( 'tool_call_id', $metadata['context'], 'Context should include tool_call_id' );
		$this->assertEquals( $tool_call_id, $metadata['context']['tool_call_id'], 'tool_call_id should match original' );
	}

	/**
	 * Test that original tool_call_id is used in completed async job tool_results.
	 */
	public function test_original_tool_call_id_used_in_tool_results() {
		// OpenAI-style tool call ID.
		$original_tool_call_id = 'call_ZEa0pnAIDkaf7olagamVRUYY';

		// Create mock async job with tool_call_id in context.
		$job_id = 'async_test_' . uniqid();

		$video_result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'video_url'     => array(
				'url' => 'https://example.com/video.mp4',
			),
			'prompt'        => 'Test video',
			'duration'      => 5,
			'aspect_ratio'  => '3:2',
			'resolution'    => '720p',
			'model'         => 'veo-2.0',
			'provider'      => 'gemini',
		);

		// Store async job metadata WITH tool_call_id in context.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array(
				'user_id'      => $this->user_id,
				'tool_call_id' => $original_tool_call_id,
			),
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

		// Verify original tool_call_id is preserved in tool_results.
		$tool_result = $job_details['tool_results'][0];
		$this->assertEquals( $original_tool_call_id, $tool_result['tool_call_id'], 'Tool call ID should match original from OpenAI' );
		$this->assertStringNotContainsString( 'async_', $tool_result['tool_call_id'], 'Tool call ID should NOT have async_ prefix when original is available' );
	}

	/**
	 * Test that fallback tool_call_id is generated when context lacks tool_call_id.
	 */
	public function test_fallback_tool_call_id_generated_when_missing() {
		// Create mock async job WITHOUT tool_call_id in context.
		$job_id = 'async_test_fallback_' . uniqid();

		$video_result = array(
			'success' => true,
			'url'     => 'https://example.com/video.mp4',
		);

		// Store async job metadata WITHOUT tool_call_id in context.
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

		// Verify fallback tool_call_id is generated with async_ prefix.
		$this->assertArrayHasKey( 'tool_results', $job_details, 'Job details should include tool_results' );
		$tool_result = $job_details['tool_results'][0];
		$this->assertStringStartsWith( 'async_generate_veo_video_', $tool_result['tool_call_id'], 'Fallback tool call ID should have async_ prefix' );
		$this->assertStringContainsString( $job_id, $tool_result['tool_call_id'], 'Fallback tool call ID should contain job ID' );
	}
}
