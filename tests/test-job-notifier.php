<?php
/**
 * Tests for job notification system.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test job notifier functionality.
 */
class Test_Job_Notifier extends WP_UnitTestCase {
	/**
	 * Test job status caching.
	 */
	public function test_job_status_caching() {
		$job_id = 'test_job_' . wp_generate_uuid4();
		$status = array(
			'job_id'   => $job_id,
			'status'   => 'running',
			'progress' => 50.0,
		);

		// Simulate job started event.
		do_action( 'wp_mcp_ai_job_started', $job_id, array( 'type' => 'test' ) );

		// Retrieve cached status.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'started', $cached['status'] );
	}

	/**
	 * Test job progress updates.
	 */
	public function test_job_progress_updates() {
		$job_id = 'test_job_' . wp_generate_uuid4();

		// Send progress update.
		do_action( 'wp_mcp_ai_job_progress', $job_id, 75.5, array( 'message' => 'Almost done' ) );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( 75.5, $cached['progress'] );
		$this->assertEquals( 'Almost done', $cached['metadata']['message'] );
	}

	/**
	 * Test job completion.
	 */
	public function test_job_completion() {
		$job_id = 'test_job_' . wp_generate_uuid4();
		$result = array( 'data' => 'test result' );

		do_action( 'wp_mcp_ai_job_completed', $job_id, $result, array() );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( 'completed', $cached['status'] );
		$this->assertArrayHasKey( 'result', $cached );
		$this->assertEquals( 'test result', $cached['result']['data'] );
	}

	/**
	 * Test job failure.
	 */
	public function test_job_failure() {
		$job_id = 'test_job_' . wp_generate_uuid4();
		$error  = new WP_Error( 'test_error', 'Test error message' );

		do_action( 'wp_mcp_ai_job_failed', $job_id, $error, array() );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( 'failed', $cached['status'] );
		$this->assertArrayHasKey( 'error', $cached );
		$this->assertEquals( 'Test error message', $cached['error']['message'] );
		$this->assertEquals( 'test_error', $cached['error']['code'] );
	}

	/**
	 * Test webhook registration.
	 */
	public function test_webhook_registration() {
		$job_id      = 'test_job_' . wp_generate_uuid4();
		$webhook_url = 'https://example.com/webhook';
		$events      = array( 'completed', 'failed' );

		$result = WP_MCP_AI_Job_Notifier::register_webhook( $job_id, $webhook_url, $events );

		$this->assertTrue( $result );

		// Verify webhook is stored.
		$webhooks = get_option( 'wp_mcp_ai_job_webhooks', array() );
		$this->assertArrayHasKey( $job_id, $webhooks );
		$this->assertCount( 1, $webhooks[ $job_id ] );
		$this->assertEquals( $webhook_url, $webhooks[ $job_id ][0]['url'] );
		$this->assertEquals( $events, $webhooks[ $job_id ][0]['events'] );

		// Clean up.
		delete_option( 'wp_mcp_ai_job_webhooks' );
	}

	/**
	 * Test invalid webhook URL rejection.
	 */
	public function test_invalid_webhook_url() {
		$job_id      = 'test_job_' . wp_generate_uuid4();
		$webhook_url = 'not-a-valid-url';

		$result = WP_MCP_AI_Job_Notifier::register_webhook( $job_id, $webhook_url, array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_webhook_url', $result->get_error_code() );
	}

	/**
	 * Test webhook limit enforcement.
	 */
	public function test_webhook_limit() {
		$job_id = 'test_job_' . wp_generate_uuid4();

		// Register maximum webhooks.
		for ( $i = 0; $i < WP_MCP_AI_Job_Notifier::MAX_WEBHOOKS_PER_JOB; $i++ ) {
			$result = WP_MCP_AI_Job_Notifier::register_webhook(
				$job_id,
				"https://example.com/webhook{$i}",
				array( 'completed' )
			);
			$this->assertTrue( $result );
		}

		// Try to register one more - should fail.
		$result = WP_MCP_AI_Job_Notifier::register_webhook(
			$job_id,
			'https://example.com/webhook_extra',
			array( 'completed' )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'too_many_webhooks', $result->get_error_code() );

		// Clean up.
		delete_option( 'wp_mcp_ai_job_webhooks' );
	}

	/**
	 * Test crawl4ai automatic integration.
	 */
	public function test_crawl4ai_integration() {
		$task_id = 'crawl_' . wp_generate_uuid4();
		$result  = array( 'urls' => array( 'https://example.com' ) );

		// Simulate crawl4ai completion (automatic hook).
		do_action( 'wp_mcp_ai_crawl4ai_job_completed', $task_id, $result, array() );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $task_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( 'completed', $cached['status'] );
		$this->assertEquals( $result, $cached['result'] );
	}

	/**
	 * Test that record_step appends a step to the cached job status and fires the action.
	 *
	 * Phase 2 / slice 2a of the chat-tasks-drawer plan.
	 */
	public function test_record_step_appends_step_and_fires_action() {
		$job_id = 'step_job_' . wp_generate_uuid4();

		// Seed the cache with an existing job so we exercise the merge path.
		do_action( 'wp_mcp_ai_job_started', $job_id, array( 'type' => 'test' ) );

		$captured = array();
		$listener = function ( $observed_id, $step, $status ) use ( &$captured ) {
			$captured[] = array(
				'job_id' => $observed_id,
				'step'   => $step,
				'status' => $status,
			);
		};
		add_action( 'wp_mcp_ai_job_step', $listener, 10, 3 );

		$ok = WP_MCP_AI_Job_Notifier::record_step(
			$job_id,
			'Parsing prompt',
			'completed',
			array( 'duration_ms' => 320 )
		);

		remove_action( 'wp_mcp_ai_job_step', $listener, 10 );

		$this->assertTrue( $ok );
		$this->assertCount( 1, $captured );
		$this->assertSame( $job_id, $captured[0]['job_id'] );
		$this->assertSame( 'Parsing prompt', $captured[0]['step']['label'] );
		$this->assertSame( 'completed', $captured[0]['step']['status'] );
		$this->assertSame( 320, $captured[0]['step']['metadata']['duration_ms'] );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $cached );
		$this->assertArrayHasKey( 'steps', $cached );
		$this->assertCount( 1, $cached['steps'] );
		$this->assertSame( 'Parsing prompt', $cached['steps'][0]['label'] );
		// The seeded 'started' status should be preserved — record_step must not overwrite it.
		$this->assertSame( 'started', $cached['status'] );
	}

	/**
	 * Test that record_step rejects invalid input and clamps unknown statuses to 'running'.
	 */
	public function test_record_step_validates_input() {
		$job_id = 'step_job_' . wp_generate_uuid4();

		$this->assertFalse( WP_MCP_AI_Job_Notifier::record_step( '', 'label' ) );
		$this->assertFalse( WP_MCP_AI_Job_Notifier::record_step( $job_id, '' ) );
		$this->assertFalse( WP_MCP_AI_Job_Notifier::record_step( $job_id, '   ' ) );

		// Unknown status falls back to 'running' without bailing.
		$this->assertTrue( WP_MCP_AI_Job_Notifier::record_step( $job_id, 'Frob the widget', 'gibberish' ) );
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertSame( 'running', $cached['steps'][0]['status'] );
	}

	/**
	 * Test that the steps ring buffer caps at MAX_STEPS_PER_JOB.
	 */
	public function test_record_step_ring_buffer_caps_at_max() {
		$job_id = 'step_job_' . wp_generate_uuid4();
		$cap    = WP_MCP_AI_Job_Notifier::MAX_STEPS_PER_JOB;

		// Push two over the cap.
		for ( $i = 1; $i <= $cap + 2; $i++ ) {
			WP_MCP_AI_Job_Notifier::record_step( $job_id, 'step-' . $i, 'running' );
		}

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertCount( $cap, $cached['steps'] );
		// The oldest entries should have been trimmed off the front.
		$this->assertSame( 'step-3', $cached['steps'][0]['label'] );
		$this->assertSame( 'step-' . ( $cap + 2 ), $cached['steps'][ $cap - 1 ]['label'] );
	}
}
