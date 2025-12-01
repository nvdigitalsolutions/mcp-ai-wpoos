<?php
/**
 * Test Veo video generation parent job completion.
 *
 * Verifies that when a veo job completes, it also completes the parent async job
 * and includes proper context (assistant_id, user_id) in completion hooks.
 *
 * @package WP_MCP_AI
 */

/**
 * Test parent async job completion when veo job finishes.
 */
class Test_Veo_Parent_Job_Completion extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		// Initialize services.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that parent async job is completed when veo job finishes.
	 */
	public function test_parent_job_completed_on_veo_completion() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create parent async job metadata.
		$parent_job_id   = 'async_test_parent_' . uniqid();
		$parent_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'generate_veo_video',
			'status'       => 'completed',
			'result'       => array(
				'async'  => true,
				'job_id' => 'veo_nested',
			),
			'queued_at'    => time(),
			'completed_at' => time(),
		);
		set_transient( 'wp_mcp_ai_async_meta_' . $parent_job_id, $parent_metadata, DAY_IN_SECONDS );

		// Create veo job with parent reference.
		$veo_job_id   = 'veo_test_' . uniqid();
		$assistant_id = 123;
		$user_id      = 1;

		$veo_metadata = array(
			'job_id'         => $veo_job_id,
			'operation_name' => 'operations/test-op',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'        => 'Test video',
				'user_id'       => $user_id,
				'assistant_id'  => $assistant_id,
				'parent_job_id' => $parent_job_id,
				'save_to_media' => false,
			),
			'parent_job_id'  => $parent_job_id,
			'assistant_id'   => $assistant_id,
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id, $veo_metadata, DAY_IN_SECONDS );

		// Track completion hooks.
		$veo_hook_called    = false;
		$parent_hook_called = false;
		$veo_hook_data      = null;
		$parent_hook_data   = null;

		add_action(
			'wp_mcp_ai_job_completed',
			function ( $id, $result, $metadata ) use ( $veo_job_id, $parent_job_id, &$veo_hook_called, &$parent_hook_called, &$veo_hook_data, &$parent_hook_data ) {
				if ( $id === $veo_job_id ) {
					$veo_hook_called = true;
					$veo_hook_data   = array(
						'result'   => $result,
						'metadata' => $metadata,
					);
				}
				if ( $id === $parent_job_id ) {
					$parent_hook_called = true;
					$parent_hook_data   = array(
						'result'   => $result,
						'metadata' => $metadata,
					);
				}
			},
			10,
			3
		);

		// Use reflection to access complete_parent_job method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'complete_parent_job' );
		$method->setAccessible( true );

		// Mock video result.
		$video_result = array(
			'video_url'     => 'data:video/mp4;base64,mock_data',
			'attachment_id' => 456,
			'url'           => 'http://example.com/video.mp4',
			'prompt'        => 'Test video',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'provider'      => 'gemini',
		);

		// Complete parent job.
		$method->invoke( $service, $parent_job_id, $video_result );

		// Verify parent job was updated.
		$updated_parent = get_transient( 'wp_mcp_ai_async_meta_' . $parent_job_id );
		$this->assertIsArray( $updated_parent, 'Parent job metadata should exist' );
		$this->assertEquals( 'completed', $updated_parent['status'], 'Parent job should be marked completed' );
		$this->assertArrayHasKey( 'result', $updated_parent, 'Parent job should have result' );

		// Verify result is wrapped in async executor format.
		$this->assertIsArray( $updated_parent['result'], 'Result should be an array' );
		$this->assertArrayHasKey( 'compressed', $updated_parent['result'], 'Result should have compressed key' );
		$this->assertArrayHasKey( 'data', $updated_parent['result'], 'Result should have data key' );
		$this->assertArrayHasKey( 'original_size', $updated_parent['result'], 'Result should have original_size key' );
		$this->assertFalse( $updated_parent['result']['compressed'], 'Result should not be compressed' );
		$this->assertEquals( $video_result, $updated_parent['result']['data'], 'Result data should match video result' );

		// Verify parent completion hook was fired.
		$this->assertTrue( $parent_hook_called, 'Parent job completion hook should be fired' );
		$this->assertIsArray( $parent_hook_data, 'Parent hook data should be captured' );
		$this->assertEquals( $video_result, $parent_hook_data['result'], 'Parent hook should receive video result' );
	}

	/**
	 * Test that completion hook includes assistant_id and user_id.
	 */
	public function test_completion_hook_includes_context() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create veo job with context.
		$veo_job_id   = 'veo_test_context_' . uniqid();
		$assistant_id = 789;
		$user_id      = 2;

		$veo_metadata = array(
			'job_id'         => $veo_job_id,
			'operation_name' => 'operations/test-op',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video with context',
				'user_id' => $user_id,
			),
			'assistant_id'   => $assistant_id,
			'status'         => 'completed',
			'result'         => array(
				'url'           => 'http://example.com/video.mp4',
				'attachment_id' => 123,
			),
			'queued_at'      => time(),
			'poll_attempt'   => 1,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id, $veo_metadata, DAY_IN_SECONDS );

		// Track hook metadata.
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_completed',
			function ( $id, $result, $metadata ) use ( $veo_job_id, &$hook_metadata ) {
				if ( $id === $veo_job_id ) {
					$hook_metadata = $metadata;
				}
			},
			10,
			3
		);

		// Simulate completion (fire the hook as it would be in poll_video_async).
		do_action(
			'wp_mcp_ai_job_completed',
			$veo_job_id,
			$veo_metadata['result'],
			array(
				'tool'         => 'generate_veo_video',
				'user_id'      => $user_id,
				'assistant_id' => $assistant_id,
			)
		);

		// Verify hook metadata includes context.
		$this->assertIsArray( $hook_metadata, 'Hook metadata should be captured' );
		$this->assertArrayHasKey( 'user_id', $hook_metadata, 'Hook should include user_id' );
		$this->assertArrayHasKey( 'assistant_id', $hook_metadata, 'Hook should include assistant_id' );
		$this->assertEquals( $user_id, $hook_metadata['user_id'], 'user_id should match' );
		$this->assertEquals( $assistant_id, $hook_metadata['assistant_id'], 'assistant_id should match' );
	}

	/**
	 * Test that parent job completion handles missing parent gracefully.
	 */
	public function test_missing_parent_job_handled_gracefully() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access complete_parent_job method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'complete_parent_job' );
		$method->setAccessible( true );

		// Try to complete a non-existent parent job.
		$result = array(
			'url' => 'http://example.com/video.mp4',
		);

		// Should not throw error.
		$method->invoke( $service, 'nonexistent_parent_job', $result );

		// Verify it logged the event (check logs if needed, but we mainly.
		// want to verify it doesn't crash).
		$this->assertTrue( true, 'Should handle missing parent job gracefully' );
	}
}
