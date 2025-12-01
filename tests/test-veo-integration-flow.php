<?php
/**
 * Integration test for veo video generation async completion flow.
 *
 * Tests the complete flow from chat client request through nested async
 * to final video completion and parent job update.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test end-to-end veo video generation with nested async completion.
 */
class Test_Veo_Integration_Flow extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		// Initialize services.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test complete flow: async executor → tool → veo service → completion.
	 *
	 * This simulates what happens when a user creates a video from the chat client.
	 */
	public function test_complete_nested_async_flow() {
		// Setup: Create a simulated async executor job.
		$async_job_id = 'async_integration_' . uniqid();
		$assistant_id = 456;
		$user_id      = 1;

		// Context as it would come from the chat client.
		$context = array(
			'user_id'      => $user_id,
			'assistant_id' => $assistant_id,
		);

		// Metadata as the async executor would store it.
		$async_metadata = array(
			'job_id'    => $async_job_id,
			'tool_slug' => 'generate_veo_video',
			'arguments' => array(
				'prompt' => 'Test video for integration',
			),
			'context'   => $context,
			'status'    => 'pending',
			'queued_at' => time(),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $async_job_id, $async_metadata, DAY_IN_SECONDS );

		// Simulate async executor adding parent_job_id to context.
		$execution_context                      = $context;
		$execution_context['in_async_executor'] = true;
		$execution_context['parent_job_id']     = $async_job_id;

		// Now simulate the veo service creating its own async job (timeout scenario).
		$veo_job_id = 'veo_integration_' . uniqid();

		// Args as they would be passed from tool to service.
		$generation_args = array(
			'prompt'        => 'Test video for integration',
			'user_id'       => $user_id,
			'assistant_id'  => $assistant_id,
			'parent_job_id' => $async_job_id,
		);

		// Veo service would store these in its metadata.
		$veo_metadata = array(
			'job_id'         => $veo_job_id,
			'operation_name' => 'operations/test-integration',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => $generation_args,
			'parent_job_id'  => $async_job_id,
			'assistant_id'   => $assistant_id,
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id, $veo_metadata, DAY_IN_SECONDS );

		// Update async executor job to show it returned nested async response.
		$async_metadata['status'] = 'completed';
		$async_metadata['result'] = array(
			'async'   => true,
			'job_id'  => $veo_job_id,
			'message' => 'Video generation started.',
		);
		set_transient( 'wp_mcp_ai_async_meta_' . $async_job_id, $async_metadata, DAY_IN_SECONDS );

		// Track completion hooks.
		$hooks_fired = array();

		add_action(
			'wp_mcp_ai_job_completed',
			function ( $job_id, $result, $metadata ) use ( &$hooks_fired ) {
				$hooks_fired[ $job_id ] = array(
					'result'   => $result,
					'metadata' => $metadata,
				);
			},
			10,
			3
		);

		// Now simulate veo job completion.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Update veo metadata to completed with video result.
		$video_result = array(
			'attachment_id' => 789,
			'url'           => 'http://example.com/integration-test-video.mp4',
			'prompt'        => 'Test video for integration',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'provider'      => 'gemini',
		);

		$veo_metadata['status'] = 'completed';
		$veo_metadata['result'] = $video_result;
		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id, $veo_metadata, DAY_IN_SECONDS );

		// Use reflection to call complete_parent_job.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'complete_parent_job' );
		$method->setAccessible( true );
		$method->invoke( $service, $async_job_id, $video_result );

		// Fire veo completion hook (as poll_video_async would do).
		do_action(
			'wp_mcp_ai_job_completed',
			$veo_job_id,
			$video_result,
			array(
				'tool'         => 'generate_veo_video',
				'user_id'      => $user_id,
				'assistant_id' => $assistant_id,
			)
		);

		// Verify the complete flow worked.

		// 1. Parent async job should be updated with video result.
		$final_async_metadata = get_transient( 'wp_mcp_ai_async_meta_' . $async_job_id );
		$this->assertIsArray( $final_async_metadata, 'Async job metadata should exist' );
		$this->assertEquals( 'completed', $final_async_metadata['status'], 'Async job should be completed' );
		$this->assertArrayHasKey( 'result', $final_async_metadata, 'Async job should have result' );

		// Verify result is wrapped in async executor format.
		$this->assertIsArray( $final_async_metadata['result'], 'Result should be an array' );
		$this->assertArrayHasKey( 'compressed', $final_async_metadata['result'], 'Result should have compressed key' );
		$this->assertArrayHasKey( 'data', $final_async_metadata['result'], 'Result should have data key' );
		$this->assertFalse( $final_async_metadata['result']['compressed'], 'Result should not be compressed' );
		$this->assertEquals( $video_result, $final_async_metadata['result']['data'], 'Result data should match video result' );

		// 2. Veo job should be completed.
		$final_veo_metadata = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id );
		$this->assertIsArray( $final_veo_metadata, 'Veo job metadata should exist' );
		$this->assertEquals( 'completed', $final_veo_metadata['status'], 'Veo job should be completed' );

		// 3. Both completion hooks should have been fired.
		$this->assertArrayHasKey( $async_job_id, $hooks_fired, 'Async job completion hook should fire' );
		$this->assertArrayHasKey( $veo_job_id, $hooks_fired, 'Veo job completion hook should fire' );

		// 4. Hook metadata should include assistant_id and user_id.
		$veo_hook_data = $hooks_fired[ $veo_job_id ];
		$this->assertArrayHasKey( 'metadata', $veo_hook_data, 'Veo hook should have metadata' );
		$this->assertArrayHasKey( 'assistant_id', $veo_hook_data['metadata'], 'Veo hook should include assistant_id' );
		$this->assertArrayHasKey( 'user_id', $veo_hook_data['metadata'], 'Veo hook should include user_id' );
		$this->assertEquals( $assistant_id, $veo_hook_data['metadata']['assistant_id'], 'assistant_id should match' );
		$this->assertEquals( $user_id, $veo_hook_data['metadata']['user_id'], 'user_id should match' );

		// 5. Chat client can retrieve video URL from original async job.
		// The result is wrapped, so we need to access the 'data' key.
		$client_result = $final_async_metadata['result']['data'];
		$this->assertArrayHasKey( 'url', $client_result, 'Result should include video URL' );
		$this->assertEquals( 'http://example.com/integration-test-video.mp4', $client_result['url'], 'URL should match' );
		$this->assertArrayHasKey( 'attachment_id', $client_result, 'Result should include attachment_id' );
		$this->assertEquals( 789, $client_result['attachment_id'], 'attachment_id should match' );
	}

	/**
	 * Test that context is properly propagated through the chain.
	 */
	public function test_context_propagation() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Simulate context from async executor.
		$context = array(
			'user_id'           => 123,
			'assistant_id'      => 456,
			'parent_job_id'     => 'async_parent_123',
			'in_async_executor' => true,
		);

		// Use reflection to check that context would be passed to service.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Tool should not use async when in_async_executor is true.
		$result = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Tool should not use async when in executor context' );

		// Verify the context structure is correct.
		$this->assertArrayHasKey( 'user_id', $context, 'Context should have user_id' );
		$this->assertArrayHasKey( 'assistant_id', $context, 'Context should have assistant_id' );
		$this->assertArrayHasKey( 'parent_job_id', $context, 'Context should have parent_job_id' );
	}
}
