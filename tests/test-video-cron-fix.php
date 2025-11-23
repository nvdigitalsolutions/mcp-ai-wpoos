<?php
/**
 * Test video cron fixes for stuck pending jobs.
 *
 * @package WP_MCP_AI
 */

/**
 * Test video cron fixes.
 */
class Test_Video_Cron_Fix extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

		// Initialize service hooks.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
	}

	/**
	 * Test that cron hook is registered.
	 */
	public function test_cron_hook_registered() {
		global $wp_filter;

		$hook = WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK;
		$this->assertTrue( isset( $wp_filter[ $hook ] ), 'Cron hook should be registered after init' );
	}

	/**
	 * Test queue_async_polling with scheduling failure.
	 */
	public function test_queue_async_polling_scheduling_failure() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$args = array(
			'prompt'  => 'Test video',
			'user_id' => 1,
		);

		$operation = array(
			'operation_name' => 'operations/test-op',
			'metadata'       => array(),
		);

		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		// Clear all cron events to ensure we're testing from clean state.
		wp_clear_scheduled_hook( WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK );

		$result = $method->invoke( $service, $operation, $args );

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertStringStartsWith( 'veo_', $result['job_id'] );

		// Verify status is pending (scheduling should succeed in test environment).
		$this->assertEquals( 'pending', $result['status'] );

		// Verify transient exists.
		$job_id   = $result['job_id'];
		$metadata = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		$this->assertIsArray( $metadata );
		$this->assertEquals( 'pending', $metadata['status'] );

		// Verify cron event was scheduled.
		$scheduled = wp_next_scheduled( WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK, array( $job_id ) );
		$this->assertNotFalse( $scheduled, 'Cron event should be scheduled' );
	}

	/**
	 * Test poll_video_async with missing API key.
	 */
	public function test_poll_video_async_missing_api_key() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$job_id   = 'veo_test_missing_key';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test' ),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Clear API key to trigger error.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => '',
			)
		);

		// Execute poll.
		$service->poll_video_async( $job_id );

		// Verify metadata was updated with failure.
		$updated = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		$this->assertIsArray( $updated );
		$this->assertEquals( 'failed', $updated['status'] );
		$this->assertNotEmpty( $updated['error'] );
		$this->assertArrayHasKey( 'completed_at', $updated );
		$this->assertStringContainsString( 'API key', $updated['error'] );
	}

	/**
	 * Test poll_video_async with max attempts exceeded.
	 */
	public function test_poll_video_async_max_attempts() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$job_id   = 'veo_test_timeout';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test' ),
			'status'         => 'polling',
			'queued_at'      => time() - 3600, // 1 hour ago.
			'poll_attempt'   => 60, // At max attempts.
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Execute poll.
		$service->poll_video_async( $job_id );

		// Verify metadata was updated with timeout failure.
		$updated = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		$this->assertIsArray( $updated );
		$this->assertEquals( 'failed', $updated['status'] );
		$this->assertNotEmpty( $updated['error'] );
		$this->assertArrayHasKey( 'completed_at', $updated );
		$this->assertStringContainsString( 'timeout', strtolower( $updated['error'] ) );
	}

	/**
	 * Test poll_video_async re-registers hook if missing.
	 */
	public function test_poll_video_async_reregisters_hook() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Remove the hook to simulate missing registration.
		remove_all_actions( WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK );

		$job_id   = 'veo_test_rereg';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test' ),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Set API key to allow polling to proceed.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-key',
			)
		);

		// Execute poll - should re-register hook.
		$service->poll_video_async( $job_id );

		// Verify hook was re-registered.
		global $wp_filter;
		$hook = WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK;
		$this->assertTrue( isset( $wp_filter[ $hook ] ), 'Hook should be re-registered' );
	}

	/**
	 * Test schedule_next_poll saves metadata before scheduling.
	 */
	public function test_schedule_next_poll_saves_before_scheduling() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		$job_id   = 'veo_test_schedule';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test', 'user_id' => 1 ),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 1,
			'max_attempts'   => 60,
		);

		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );

		// Clear any existing scheduled events.
		wp_clear_scheduled_hook( WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK );

		$method->invoke( $service, $job_id, $metadata );

		// Verify transient was saved with polling status.
		$saved = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		$this->assertIsArray( $saved );
		$this->assertEquals( 'polling', $saved['status'] );

		// Verify cron event was scheduled.
		$scheduled = wp_next_scheduled( WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK, array( $job_id ) );
		$this->assertNotFalse( $scheduled, 'Cron event should be scheduled' );
	}
}
