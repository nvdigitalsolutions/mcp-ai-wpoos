<?php
/**
 * Tests for async veo video tool_call_id preservation in chat client responses.
 *
 * Verifies that when veo video generation completes asynchronously,
 * the original tool_call_id from the LLM is preserved in the response
 * and can be properly extracted by the chat client.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Async_Veo_Tool_Call_ID_Display
 */
class Test_Async_Veo_Tool_Call_ID_Display extends WP_UnitTestCase {

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
		$this->user_id  = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
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
	 * Test that tool_call_id is preserved in tool_results array for async veo completion.
	 */
	public function test_veo_async_result_includes_tool_call_id_in_tool_results() {
		// OpenAI-style tool call ID from LLM.
		$original_tool_call_id = 'call_VeoTestVideo123456789';

		// Create mock async job with tool_call_id in context.
		$job_id = 'async_test_' . uniqid();

		$metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'generate_veo_video',
			'arguments'  => array( 'prompt' => 'Test video' ),
			'context'    => array(
				'user_id'      => $this->user_id,
				'tool_call_id' => $original_tool_call_id, // Store original LLM tool call ID.
			),
			'status'     => 'pending',
			'created_at' => time(),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate video generation completing with successful result.
		$video_result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-2.0-generate-001',
			'provider'      => 'gemini',
			'message'       => 'Video generated successfully',
		);

		// Simulate job completion via Job Notifier.
		WP_MCP_AI_Job_Notifier::set_job_status(
			$job_id,
			'completed',
			array(
				'result' => $video_result,
			)
		);

		// Get job details as the chat client would.
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Assert job details were retrieved successfully.
		$this->assertIsArray( $job_details, 'Job details should be an array' );
		$this->assertEquals( 'completed', $job_details['status'], 'Job status should be completed' );

		// Assert tool_results array exists (created by merge_notifier_status).
		$this->assertArrayHasKey( 'tool_results', $job_details, 'Job details should include tool_results array' );
		$this->assertIsArray( $job_details['tool_results'], 'tool_results should be an array' );
		$this->assertNotEmpty( $job_details['tool_results'], 'tool_results should not be empty' );

		// Extract first tool message from tool_results.
		$tool_message = $job_details['tool_results'][0];

		// Assert tool message has required fields.
		$this->assertArrayHasKey( 'role', $tool_message, 'Tool message should have role field' );
		$this->assertEquals( 'tool', $tool_message['role'], 'Tool message role should be "tool"' );

		$this->assertArrayHasKey( 'tool_call_id', $tool_message, 'Tool message should have tool_call_id field' );
		$this->assertEquals( $original_tool_call_id, $tool_message['tool_call_id'], 'tool_call_id should match original LLM call ID' );

		$this->assertArrayHasKey( 'name', $tool_message, 'Tool message should have name field' );
		$this->assertEquals( 'generate_veo_video', $tool_message['name'], 'Tool name should match' );

		$this->assertArrayHasKey( 'content', $tool_message, 'Tool message should have content field' );
	}

	/**
	 * Test that tool_call_id is available at top level of result for easy extraction.
	 */
	public function test_veo_async_result_exposes_tool_call_id_for_chat_client() {
		$original_tool_call_id = 'call_VeoExposeTest987654321';
		$job_id                = 'async_expose_' . uniqid();

		// Create async job with tool_call_id.
		$metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'generate_veo_video',
			'arguments'  => array( 'prompt' => 'Test video' ),
			'context'    => array(
				'user_id'      => $this->user_id,
				'tool_call_id' => $original_tool_call_id,
			),
			'status'     => 'pending',
			'created_at' => time(),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate completion.
		WP_MCP_AI_Job_Notifier::set_job_status(
			$job_id,
			'completed',
			array(
				'result' => array(
					'success' => true,
					'url'     => 'https://example.com/video2.mp4',
				),
			)
		);

		// Get job details.
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// The chat client JavaScript (displayAsyncToolResult) should be able to extract
		// tool_call_id from result.tool_results[0].tool_call_id.
		// Verify the structure is correct for extraction.
		$this->assertArrayHasKey( 'tool_results', $job_details, 'tool_results should exist' );
		$this->assertIsArray( $job_details['tool_results'], 'tool_results should be array' );

		if ( ! empty( $job_details['tool_results'] ) ) {
			$first_result = $job_details['tool_results'][0];
			$this->assertIsArray( $first_result, 'First tool result should be array' );

			// This is what the JavaScript will extract.
			$extracted_call_id = isset( $first_result['tool_call_id'] ) ? $first_result['tool_call_id'] : '';
			$this->assertEquals( $original_tool_call_id, $extracted_call_id, 'JavaScript should be able to extract original tool_call_id' );
		}
	}

	/**
	 * Test fallback when tool_call_id is missing from context.
	 */
	public function test_veo_async_result_generates_fallback_tool_call_id_when_missing() {
		$job_id = 'async_fallback_' . uniqid();

		// Create async job WITHOUT tool_call_id in context.
		$metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'generate_veo_video',
			'arguments'  => array( 'prompt' => 'Test video' ),
			'context'    => array(
				'user_id' => $this->user_id,
				// No tool_call_id.
			),
			'status'     => 'pending',
			'created_at' => time(),
		);

		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Simulate completion.
		WP_MCP_AI_Job_Notifier::set_job_status(
			$job_id,
			'completed',
			array(
				'result' => array( 'success' => true ),
			)
		);

		// Get job details.
		$job_details = $this->service->get_job_details( $job_id, $this->user_id );

		// Should still have tool_results with a fallback tool_call_id.
		$this->assertArrayHasKey( 'tool_results', $job_details, 'tool_results should exist even without original tool_call_id' );
		$this->assertIsArray( $job_details['tool_results'], 'tool_results should be array' );
		$this->assertNotEmpty( $job_details['tool_results'], 'tool_results should not be empty' );

		$tool_message = $job_details['tool_results'][0];
		$this->assertArrayHasKey( 'tool_call_id', $tool_message, 'Should have fallback tool_call_id' );

		// Fallback format in PHP: async_{tool_name}_{job_id}.
		// Note: JavaScript uses async_{tool_name}_{timestamp}_{random} format which is different.
		// PHP backend generates: async_generate_veo_video_{job_id}
		$fallback_pattern = '/^async_generate_veo_video_' . preg_quote( $job_id, '/' ) . '$/';
		$this->assertMatchesRegularExpression( $fallback_pattern, $tool_message['tool_call_id'], 'Fallback tool_call_id should follow expected PHP backend pattern' );
	}
}
