<?php
/**
 * Test Veo video generation transient workflow for agentic loops.
 *
 * Validates that transients are properly set, updated, and can be retrieved
 * throughout the video generation lifecycle.
 *
 * @package WP_MCP_AI
 */

/**
 * Test transient workflow for Veo job completion notifications.
 */
class Test_Veo_Transient_Workflow extends WP_UnitTestCase {
	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

		// Initialize service and notifier hooks.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
		WP_MCP_AI_Job_Notifier::init();

		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up any test transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional cleanup in test teardown.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%wp_mcp_ai%'" );
	}

	/**
	 * Test that job transient is created when async polling is queued.
	 */
	public function test_transient_created_on_queue_async_polling() {
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$operation = array(
			'operation_name' => 'operations/test-op-123',
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Test video prompt',
			'user_id' => 1,
		);

		$result = $method->invoke( $this->service, $operation, $args );

		// Verify job ID was returned.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertStringStartsWith( 'veo_', $result['job_id'] );

		$job_id = $result['job_id'];

		// Verify transient was created with correct data.
		$transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;
		$metadata      = get_transient( $transient_key );

		$this->assertIsArray( $metadata );
		$this->assertEquals( $job_id, $metadata['job_id'] );
		$this->assertEquals( 'pending', $metadata['status'] );
		$this->assertEquals( 'operations/test-op-123', $metadata['operation_name'] );
		$this->assertEquals( 0, $metadata['poll_attempt'] );
	}

	/**
	 * Test that job_completed hook updates notification cache.
	 */
	public function test_job_completed_hook_updates_notification_cache() {
		$job_id = 'veo_test_' . uniqid( '', true );

		$result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'http://example.com/test-video.mp4',
		);

		$metadata = array(
			'tool'   => 'generate_veo_video',
			'prompt' => 'Test prompt',
		);

		// Fire the completion hook.
		do_action( 'wp_mcp_ai_job_completed', $job_id, $result, $metadata );

		// Verify notification cache was set.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached_status );
		$this->assertEquals( $job_id, $cached_status['job_id'] );
		$this->assertEquals( 'completed', $cached_status['status'] );
		$this->assertArrayHasKey( 'result', $cached_status );
		$this->assertEquals( 123, $cached_status['result']['attachment_id'] );
	}

	/**
	 * Test that job_failed hook updates notification cache.
	 */
	public function test_job_failed_hook_updates_notification_cache() {
		$job_id = 'veo_fail_' . uniqid( '', true );

		$error = new WP_Error( 'test_error', 'Test error message' );

		$metadata = array(
			'tool' => 'generate_veo_video',
		);

		// Fire the failure hook.
		do_action( 'wp_mcp_ai_job_failed', $job_id, $error, $metadata );

		// Verify notification cache was set.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached_status );
		$this->assertEquals( $job_id, $cached_status['job_id'] );
		$this->assertEquals( 'failed', $cached_status['status'] );
		$this->assertArrayHasKey( 'error', $cached_status );
		$this->assertEquals( 'Test error message', $cached_status['error']['message'] );
	}

	/**
	 * Test that transient is updated when status changes from pending to polling.
	 */
	public function test_transient_updated_on_status_change() {
		$job_id = 'veo_status_' . uniqid( '', true );

		// Create initial metadata (pending).
		$initial_metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array(
				'prompt'  => 'Test',
				'user_id' => 1,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		$transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;
		set_transient( $transient_key, $initial_metadata, DAY_IN_SECONDS );

		// Simulate status update.
		$initial_metadata['status']       = 'polling';
		$initial_metadata['poll_attempt'] = 5;
		$initial_metadata['last_poll']    = time();
		set_transient( $transient_key, $initial_metadata, DAY_IN_SECONDS );

		// Verify transient was updated.
		$updated_metadata = get_transient( $transient_key );

		$this->assertIsArray( $updated_metadata );
		$this->assertEquals( 'polling', $updated_metadata['status'] );
		$this->assertEquals( 5, $updated_metadata['poll_attempt'] );
		$this->assertArrayHasKey( 'last_poll', $updated_metadata );
	}

	/**
	 * Test that sanitize_key preserves consistency for job IDs with dots.
	 */
	public function test_sanitize_key_consistency_for_job_ids() {
		// Job IDs from uniqid('', true) contain dots.
		$job_id = 'veo_6924cf2ac54851.61478921';

		// Both storage and retrieval should sanitize consistently.
		$sanitized_for_storage   = sanitize_key( $job_id );
		$sanitized_for_retrieval = sanitize_key( $job_id );

		$this->assertEquals( $sanitized_for_storage, $sanitized_for_retrieval );

		// The dot should be removed by sanitize_key.
		$this->assertStringNotContainsString( '.', $sanitized_for_storage );

		// Test with actual transient storage.
		$cache_prefix = 'wp_mcp_ai_job_status_';
		$cache_key    = $cache_prefix . $sanitized_for_storage;

		$test_data = array( 'status' => 'completed' );
		set_transient( $cache_key, $test_data, 3600 );

		// Retrieve using same sanitization.
		$retrieved = get_transient( $cache_prefix . sanitize_key( $job_id ) );

		$this->assertIsArray( $retrieved );
		$this->assertEquals( 'completed', $retrieved['status'] );

		// Clean up.
		delete_transient( $cache_key );
	}

	/**
	 * Test that get_async_status returns correct data from transient.
	 */
	public function test_get_async_status_from_transient() {
		$job_id = 'veo_async_' . uniqid( '', true );

		// Create completed job metadata.
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => 1,
			),
			'status'         => 'completed',
			'queued_at'      => time() - 120,
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
			'result'         => array(
				'attachment_id' => 456,
				'url'           => 'http://example.com/video.mp4',
			),
		);

		$transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;
		set_transient( $transient_key, $metadata, DAY_IN_SECONDS );

		// Get status via service.
		$status = $this->service->get_async_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertEquals( 'completed', $status['status'] );
		$this->assertEquals( $job_id, $status['job_id'] );
		$this->assertArrayHasKey( 'result', $status );
		$this->assertEquals( 456, $status['result']['attachment_id'] );
	}

	/**
	 * Test that notification cache uses correct transient duration.
	 */
	public function test_notification_cache_duration() {
		$job_id = 'veo_duration_' . uniqid( '', true );

		// Fire completion hook.
		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			array( 'success' => true ),
			array( 'tool' => 'generate_veo_video' )
		);

		// Verify transient exists.
		$cache_key = WP_MCP_AI_Job_Notifier::CACHE_PREFIX . sanitize_key( $job_id );
		$status    = get_transient( $cache_key );

		$this->assertIsArray( $status );

		// Verify the cache duration constant is 1 hour.
		$this->assertEquals( 3600, WP_MCP_AI_Job_Notifier::CACHE_DURATION );
	}

	/**
	 * Test that multiple transient updates don't cause data loss.
	 */
	public function test_multiple_transient_updates_preserve_data() {
		$job_id = 'veo_multi_' . uniqid( '', true );

		$transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;

		// Simulate multiple updates during polling.
		for ( $i = 1; $i <= 10; $i++ ) {
			$metadata = array(
				'job_id'         => $job_id,
				'status'         => 'polling',
				'poll_attempt'   => $i,
				'max_attempts'   => 60,
				'queued_at'      => time() - ( $i * 5 ),
				'last_poll'      => time(),
				'operation_name' => 'operations/test-op',
				'args'           => array( 'prompt' => 'Original prompt' ),
			);

			set_transient( $transient_key, $metadata, DAY_IN_SECONDS );
		}

		// Verify final state.
		$final_metadata = get_transient( $transient_key );

		$this->assertIsArray( $final_metadata );
		$this->assertEquals( 10, $final_metadata['poll_attempt'] );
		$this->assertEquals( 'Original prompt', $final_metadata['args']['prompt'] );
	}

	/**
	 * Test that veo job and notification cache are both set on completion.
	 */
	public function test_both_caches_set_on_completion() {
		$job_id = 'veo_both_' . uniqid( '', true );

		// Set the veo job transient first (simulating async polling).
		$veo_metadata = array(
			'job_id'         => $job_id,
			'status'         => 'completed',
			'poll_attempt'   => 15,
			'max_attempts'   => 60,
			'queued_at'      => time() - 180,
			'operation_name' => 'operations/test-op',
			'args'           => array(
				'prompt'  => 'Test',
				'user_id' => 1,
			),
			'result'         => array(
				'attachment_id' => 789,
				'url'           => 'http://example.com/final.mp4',
			),
		);

		$veo_transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;
		set_transient( $veo_transient_key, $veo_metadata, DAY_IN_SECONDS );

		// Fire completion hook (which should set notification cache).
		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			$veo_metadata['result'],
			array( 'tool' => 'generate_veo_video' )
		);

		// Verify both caches are set.
		$veo_status = get_transient( $veo_transient_key );
		$this->assertIsArray( $veo_status );
		$this->assertEquals( 'completed', $veo_status['status'] );

		$notification_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $notification_status );
		$this->assertEquals( 'completed', $notification_status['status'] );
		$this->assertEquals( 789, $notification_status['result']['attachment_id'] );
	}
}
