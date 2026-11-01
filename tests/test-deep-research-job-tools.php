<?php
/**
 * Tests for the deep_research background job companion tools:
 * research_status, research_result, and research_cancel.
 *
 * These tools operate on the job transient written by
 * WP_MCP_AI_Tool_Deep_Research (wp_mcp_ai_research_job_<job_id>) and, for
 * cancellation, on the scheduled cron event.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the deep research job companion tools.
 */
class Test_Deep_Research_Job_Tools extends WP_UnitTestCase {

	/**
	 * Admin user ID (owns the jobs).
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID (cannot cancel others' jobs).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up users for the tests.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Reset the current user between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Seed a job transient.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $overrides Overrides for the status record.
	 * @return void
	 */
	private function seed_job( $job_id, array $overrides = array() ) {
		$status = array_merge(
			array(
				'job_id'     => $job_id,
				'topic'      => 'Composting basics',
				'depth'      => 'standard',
				'status'     => 'scheduled',
				'progress'   => 'scheduled',
				'created_at' => time(),
				'user_id'    => $this->admin_id,
			),
			$overrides
		);

		set_transient( 'wp_mcp_ai_research_job_' . $job_id, $status, DAY_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// research_status
	// -------------------------------------------------------------------------

	/**
	 * Research status reports a scheduled job's lifecycle state.
	 */
	public function test_research_status_reports_scheduled_job() {
		$job_id = 'deep_research_test_status_1';
		$this->seed_job( $job_id );

		$tool   = new WP_MCP_AI_Tool_Research_Status();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertSame( 'Composting basics', $result['topic'] );
		$this->assertSame( 'standard', $result['depth'] );
		$this->assertArrayHasKey( 'created_at', $result );
	}

	/**
	 * Research status surfaces partial completions with warnings.
	 */
	public function test_research_status_surfaces_partial_completion() {
		$job_id = 'deep_research_test_status_2';
		$this->seed_job(
			$job_id,
			array(
				'status'       => 'completed',
				'progress'     => 'completed',
				'completed_at' => time(),
				'partial'      => true,
				'warnings'     => array( 'report_failed_quality_checks' ),
				'result'       => array(
					'word_count' => 45,
					'status'     => 'partial',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Research_Status();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertTrue( $result['partial'] );
		$this->assertSame( 45, $result['result_word_count'] );
		$this->assertSame( 'partial', $result['result_status'] );
		$this->assertContains( 'report_failed_quality_checks', $result['warnings'] );
	}

	/**
	 * Research status errors for a missing job.
	 */
	public function test_research_status_errors_for_missing_job() {
		$tool   = new WP_MCP_AI_Tool_Research_Status();
		$result = $tool->execute( array( 'job_id' => 'deep_research_does_not_exist' ), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_research_job_not_found', $result->get_error_code() );
	}

	/**
	 * Research status requires a job ID.
	 */
	public function test_research_status_requires_job_id() {
		$tool   = new WP_MCP_AI_Tool_Research_Status();
		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_job_id', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// research_result
	// -------------------------------------------------------------------------

	/**
	 * Research result returns the full report for a completed job.
	 */
	public function test_research_result_returns_completed_report() {
		$job_id = 'deep_research_test_result_1';
		$this->seed_job(
			$job_id,
			array(
				'status'       => 'completed',
				'progress'     => 'completed',
				'completed_at' => time(),
				'result'       => array(
					'topic'      => 'Composting basics',
					'report'     => '## Overview' . "\n" . 'Composting is well documented [1].',
					'sources'    => array(
						array(
							'url'   => 'https://example.com/compost',
							'title' => 'Composting',
						),
					),
					'word_count' => 12,
					'status'     => 'complete',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Research_Result();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( $job_id, $result['job_id'] );
		$this->assertStringContainsString( 'Composting is well documented', $result['report'] );
		$this->assertSame( 1, count( $result['sources'] ) );
	}

	/**
	 * Research result errors while the job is still running.
	 */
	public function test_research_result_errors_when_still_running() {
		$job_id = 'deep_research_test_result_2';
		$this->seed_job(
			$job_id,
			array(
				'status'     => 'running',
				'progress'   => 'running',
				'started_at' => time(),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Research_Result();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_research_job_not_complete', $result->get_error_code() );
		$this->assertSame( 'running', $result->get_error_data()['job_status'] );
	}

	/**
	 * Research result errors for a missing job.
	 */
	public function test_research_result_errors_for_missing_job() {
		$tool   = new WP_MCP_AI_Tool_Research_Result();
		$result = $tool->execute( array( 'job_id' => 'deep_research_does_not_exist' ), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_research_job_not_found', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// research_cancel
	// -------------------------------------------------------------------------

	/**
	 * Research cancel removes the transient and unschedules the cron event.
	 */
	public function test_research_cancel_removes_job_and_cron_event() {
		$job_id = 'deep_research_test_cancel_1';
		$this->seed_job( $job_id );

		// Schedule the cron event the same way the tool does (nested args).
		wp_schedule_single_event(
			time() + 3600,
			'wp_mcp_ai_deep_research_background',
			array(
				array(
					'job_id' => $job_id,
					'topic'  => 'Composting basics',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Research_Cancel();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['cancelled'] );
		$this->assertSame( 'scheduled', $result['was_status'] );

		// Transient is gone.
		$this->assertFalse( get_transient( 'wp_mcp_ai_research_job_' . $job_id ) );

		// Cron event is gone (scan by hook: wp_next_scheduled defaults to
		// empty args and cannot see args-carrying jobs).
		$cron = _get_cron_array();
		$this->assertIsArray( $cron );
		foreach ( $cron as $events ) {
			if ( isset( $events['wp_mcp_ai_deep_research_background'] ) ) {
				foreach ( $events['wp_mcp_ai_deep_research_background'] as $event ) {
					$this->assertNotSame( $job_id, $event['args'][0]['job_id'] ?? null );
				}
			}
		}
	}

	/**
	 * Research cancel leaves terminal jobs intact.
	 */
	public function test_research_cancel_leaves_completed_job_intact() {
		$job_id = 'deep_research_test_cancel_2';
		$this->seed_job(
			$job_id,
			array(
				'status'       => 'completed',
				'progress'     => 'completed',
				'completed_at' => time(),
				'result'       => array(
					'topic'  => 'Composting basics',
					'report' => 'Done.',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Research_Cancel();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cancelled'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertIsArray( get_transient( 'wp_mcp_ai_research_job_' . $job_id ) );
	}

	/**
	 * Non-owners without manage_options cannot cancel a job.
	 */
	public function test_research_cancel_requires_ownership() {
		$job_id = 'deep_research_test_cancel_3';
		$this->seed_job( $job_id );

		$tool   = new WP_MCP_AI_Tool_Research_Cancel();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $this->subscriber_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// The job survives the forbidden attempt.
		$this->assertIsArray( get_transient( 'wp_mcp_ai_research_job_' . $job_id ) );
	}

	/**
	 * Administrators can cancel jobs they do not own.
	 */
	public function test_research_cancel_allows_administrators() {
		$other_admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$job_id = 'deep_research_test_cancel_4';
		$this->seed_job( $job_id );

		$tool   = new WP_MCP_AI_Tool_Research_Cancel();
		$result = $tool->execute( array( 'job_id' => $job_id ), array( 'user_id' => $other_admin ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['cancelled'] );
	}

	/**
	 * The scheduled background event uses nested args so the cron handler
	 * receives the full job-parameter array as its first argument.
	 */
	public function test_background_scheduling_uses_nested_args() {
		$tool   = new WP_MCP_AI_Tool_Deep_Research();
		$result = $tool->execute(
			array(
				'topic'    => 'Composting basics',
				'depth'    => 'standard',
				'run_mode' => 'background',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertNotEmpty( $result['job_id'] );

		// Find the cron event and verify the nested shape.
		$cron  = _get_cron_array();
		$found = false;
		foreach ( $cron as $events ) {
			if ( empty( $events['wp_mcp_ai_deep_research_background'] ) ) {
				continue;
			}
			foreach ( $events['wp_mcp_ai_deep_research_background'] as $event ) {
				if ( ( $event['args'][0]['job_id'] ?? '' ) === $result['job_id'] ) {
					$found = true;
					$this->assertSame( 'Composting basics', $event['args'][0]['topic'] );
					$this->assertSame( 'standard', $event['args'][0]['depth'] );
					$this->assertSame( $this->admin_id, $event['args'][0]['user_id'] );
					$this->assertTrue( $event['args'][0]['include_site_content'] );
				}
			}
		}
		$this->assertTrue( $found, 'The scheduled cron event with nested args should exist.' );
	}
}
