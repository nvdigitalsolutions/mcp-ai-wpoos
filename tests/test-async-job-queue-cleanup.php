<?php
/**
 * Tests for WP_MCP_AI_Async_Job_Queue cleanup regression coverage.
 *
 * Covers the daily cleanup's terminal-row DELETE and the stale-job reaper
 * added in v1.1.98 (rows stuck in running/queued beyond the stale window
 * are failed instead of accumulating forever).
 *
 * The wp-phpunit harness rewrites CREATE/DROP TABLE into their TEMPORARY
 * variants while a test transaction is open, so the DDL helpers here lift
 * those query filters (same pattern as
 * test-async-job-queue-table-resilience.php).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test async job queue cleanup (terminal-row deletion + stale-job reaper).
 *
 * @covers WP_MCP_AI_Async_Job_Queue::cleanup_old_jobs
 */
class Test_Async_Job_Queue_Cleanup extends WP_UnitTestCase {

	/**
	 * Full table name (with prefix).
	 *
	 * @var string
	 */
	private $table_name = '';

	/**
	 * Create the real queue table for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Async_Job_Queue unavailable.' );
		}

		global $wpdb;
		$this->table_name = $wpdb->prefix . WP_MCP_AI_Async_Job_Queue::TABLE_NAME;
		$this->create_real_table();
	}

	/**
	 * Restore the real table so later suites see a consistent database.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->create_real_table();
		}
		parent::tearDown();
	}

	/**
	 * Create the real queue table, bypassing the harness's
	 * CREATE TABLE → CREATE TEMPORARY TABLE rewrite.
	 */
	private function create_real_table() {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		WP_MCP_AI_Async_Job_Queue::create_table();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
	}

	/**
	 * Insert a job row with explicit timestamps.
	 *
	 * @param string $status       Job status.
	 * @param string $created_at   MySQL datetime for created_at.
	 * @param string $completed_at Optional MySQL datetime for completed_at.
	 * @return int Inserted row ID.
	 */
	private function insert_job( $status, $created_at, $completed_at = null ) {
		global $wpdb;

		$data = array(
			'job_type'    => 'test_type',
			'job_data'    => wp_json_encode( array( 'payload' => 'x' ) ),
			'priority'    => 3,
			'status'      => $status,
			'progress'    => 0,
			'created_at'  => $created_at,
			'retries'     => 0,
			'max_retries' => 3,
		);
		if ( null !== $completed_at ) {
			$data['completed_at'] = $completed_at;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test-only fixture on a custom plugin table.
		$wpdb->insert( $this->table_name, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * The stale-job reaper fails rows stuck in running/queued beyond the
	 * stale window instead of leaving them to accumulate forever.
	 */
	public function test_cleanup_reaps_stale_running_and_queued_jobs() {
		global $wpdb;

		$stale_running = $this->insert_job(
			WP_MCP_AI_Async_Job_Queue::STATUS_RUNNING,
			gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS )
		);
		$stale_queued  = $this->insert_job(
			WP_MCP_AI_Async_Job_Queue::STATUS_QUEUED,
			gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS )
		);
		$fresh_queued  = $this->insert_job(
			WP_MCP_AI_Async_Job_Queue::STATUS_QUEUED,
			gmdate( 'Y-m-d H:i:s', time() )
		);
		$old_completed = $this->insert_job(
			WP_MCP_AI_Async_Job_Queue::STATUS_COMPLETED,
			gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS )
		);

		WP_MCP_AI_Async_Job_Queue::cleanup_old_jobs();

		$rows  = $wpdb->get_results( "SELECT id, status, completed_at, error FROM {$this->table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only reads on a custom plugin table.
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		// Stale running/queued rows are failed with a completed_at timestamp.
		$this->assertEquals( WP_MCP_AI_Async_Job_Queue::STATUS_FAILED, $by_id[ $stale_running ]['status'] );
		$this->assertNotEmpty( $by_id[ $stale_running ]['completed_at'] );
		$this->assertStringContainsString( 'Stale job', $by_id[ $stale_running ]['error'] );
		$this->assertEquals( WP_MCP_AI_Async_Job_Queue::STATUS_FAILED, $by_id[ $stale_queued ]['status'] );

		// Fresh queued rows are untouched.
		$this->assertEquals( WP_MCP_AI_Async_Job_Queue::STATUS_QUEUED, $by_id[ $fresh_queued ]['status'] );

		// Terminal rows older than the cleanup window are deleted.
		$this->assertArrayNotHasKey( $old_completed, $by_id );
	}

	/**
	 * Rows inside the stale window are left alone (no premature reaping).
	 */
	public function test_cleanup_spares_fresh_running_jobs() {
		global $wpdb;

		$fresh_running = $this->insert_job(
			WP_MCP_AI_Async_Job_Queue::STATUS_RUNNING,
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
		);

		WP_MCP_AI_Async_Job_Queue::cleanup_old_jobs();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$this->table_name} WHERE id = %d", $fresh_running ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only read on a custom plugin table.

		$this->assertEquals( WP_MCP_AI_Async_Job_Queue::STATUS_RUNNING, $row['status'] );
	}
}
