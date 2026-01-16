<?php
/**
 * Tests for Job Notifier Little's Law enhancements.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Job Notifier Little's Law functionality.
 */
class Test_Job_Notifier_Littles_Law extends WP_UnitTestCase {
	/**
	 * Test Little's Law metrics are added to running job status.
	 */
	public function test_littles_law_metrics_added_to_running_jobs() {
		$job_id = 'test_job_' . wp_generate_uuid4();

		// Start a job.
		do_action( 'wp_mcp_ai_job_started', $job_id, array( 'tool' => 'web_search' ) );

		// Update progress.
		do_action( 'wp_mcp_ai_job_progress', $job_id, 50.0, array( 'tool' => 'web_search' ) );

		// Retrieve status.
		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		// Verify Little's Law metrics are present.
		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'littles_law', $status );
		$this->assertArrayHasKey( 'sla_tier', $status['littles_law'] );
		$this->assertArrayHasKey( 'sla_target', $status['littles_law'] );
		$this->assertArrayHasKey( 'elapsed_time', $status['littles_law'] );
		$this->assertArrayHasKey( 'estimated_remaining', $status['littles_law'] );
		$this->assertArrayHasKey( 'sla_compliance', $status['littles_law'] );
		$this->assertArrayHasKey( 'predicted_completion', $status['littles_law'] );
	}

	/**
	 * Test SLA tier inference from tool name.
	 */
	public function test_sla_tier_inference() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Job_Notifier' );
		$method     = $reflection->getMethod( 'infer_sla_tier_from_tool' );
		$method->setAccessible( true );

		// Web search should be near_realtime.
		$tier = $method->invoke( null, 'web_search' );
		$this->assertEquals( 'near_realtime', $tier );

		// Video generation should be batch.
		$tier = $method->invoke( null, 'generate_veo_video' );
		$this->assertEquals( 'batch', $tier );

		// Save post should be realtime.
		$tier = $method->invoke( null, 'save_post' );
		$this->assertEquals( 'realtime', $tier );

		// Unknown tool defaults to batch.
		$tier = $method->invoke( null, 'unknown_tool_xyz' );
		$this->assertEquals( 'batch', $tier );
	}

	/**
	 * Test SLA compliance calculation.
	 */
	public function test_sla_compliance_calculation() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Job_Notifier' );
		$method     = $reflection->getMethod( 'calculate_sla_compliance' );
		$method->setAccessible( true );

		// Job on track (5s elapsed, 5s remaining, 30s target).
		$compliance = $method->invoke( null, 5.0, 5.0, 30.0 );
		$this->assertEquals( 'on_track', $compliance );

		// Job at risk (20s elapsed, 15s remaining, 30s target = 35s total).
		$compliance = $method->invoke( null, 20.0, 15.0, 30.0 );
		$this->assertEquals( 'at_risk', $compliance );

		// Job violated (35s elapsed, any remaining, 30s target).
		$compliance = $method->invoke( null, 35.0, 0.0, 30.0 );
		$this->assertEquals( 'violated', $compliance );
	}

	/**
	 * Test estimated completion time calculation.
	 */
	public function test_estimated_completion_calculation() {
		$job_id = 'test_job_' . wp_generate_uuid4();

		// Start job at known time.
		do_action( 'wp_mcp_ai_job_started', $job_id, array( 'tool' => 'crawl4ai' ) );

		// Simulate 10 seconds passing and 50% progress.
		sleep( 1 ); // Small delay to ensure time difference.
		do_action( 'wp_mcp_ai_job_progress', $job_id, 50.0, array( 'tool' => 'crawl4ai' ) );

		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'littles_law', $status );

		// With 50% progress, estimated total should be roughly 2x elapsed time.
		$littles_law = $status['littles_law'];
		$this->assertGreaterThan( 0, $littles_law['elapsed_time'] );
		$this->assertGreaterThan( 0, $littles_law['estimated_remaining'] );
		$this->assertIsString( $littles_law['predicted_completion'] );

		// Verify predicted_completion is a valid ISO 8601 date.
		$predicted = DateTime::createFromFormat( DateTime::ATOM, $littles_law['predicted_completion'] );
		$this->assertInstanceOf( DateTime::class, $predicted );
	}

	/**
	 * Test completed jobs don't get Little's Law metrics.
	 */
	public function test_completed_jobs_no_littles_law_metrics() {
		$job_id = 'test_job_' . wp_generate_uuid4();

		// Complete a job immediately.
		do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'result' => 'success' ), array( 'tool' => 'web_search' ) );

		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertEquals( 'completed', $status['status'] );
		// Completed jobs shouldn't have Little's Law metrics added.
		$this->assertArrayNotHasKey( 'littles_law', $status );
	}

	/**
	 * Test different SLA targets for different tool types.
	 */
	public function test_different_sla_targets_per_tool() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Job_Notifier' );
		$tier_method = $reflection->getMethod( 'infer_sla_tier_from_tool' );
		$tier_method->setAccessible( true );
		$target_method = $reflection->getMethod( 'get_sla_target_for_tier' );
		$target_method->setAccessible( true );

		// Realtime tool = 1s target.
		$tier   = $tier_method->invoke( null, 'save_post' );
		$target = $target_method->invoke( null, $tier );
		$this->assertEquals( 1.0, $target );

		// Near realtime tool = 30s target.
		$tier   = $tier_method->invoke( null, 'web_search' );
		$target = $target_method->invoke( null, $tier );
		$this->assertEquals( 30.0, $target );

		// Batch tool = 300s (5 min) target.
		$tier   = $tier_method->invoke( null, 'generate_veo_video' );
		$target = $target_method->invoke( null, $tier );
		$this->assertEquals( 300.0, $target );
	}

	/**
	 * Test job with zero progress uses SLA target as estimate.
	 */
	public function test_job_zero_progress_uses_sla_estimate() {
		$job_id = 'test_job_' . wp_generate_uuid4();

		// Start job with no progress updates.
		do_action( 'wp_mcp_ai_job_started', $job_id, array( 'tool' => 'web_search' ) );

		// Manually set status to running without progress.
		$status = array(
			'job_id'     => $job_id,
			'status'     => 'running',
			'started_at' => current_time( 'mysql', true ),
			'metadata'   => array( 'tool' => 'web_search' ),
		);

		$cache_key = 'wp_mcp_ai_job_status_' . sanitize_key( $job_id );
		set_transient( $cache_key, $status, 3600 );

		$retrieved = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $retrieved );
		$this->assertArrayHasKey( 'littles_law', $retrieved );

		// Without progress, estimated_remaining should be close to SLA target (30s for web_search).
		$this->assertGreaterThan( 0, $retrieved['littles_law']['estimated_remaining'] );
	}
}
