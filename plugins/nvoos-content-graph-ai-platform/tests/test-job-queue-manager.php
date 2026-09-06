<?php
/**
 * Job queue manager port tests (Wave E2).
 *
 * Characterization suite for the ported `JobQueueManager`:
 * byte-identical table schema, priority/status constants, enqueue
 * validation + callable extraction, atomic claiming with priority
 * ordering, the process-cycle envelopes (capacity / no-pending /
 * success), retry-then-fail handling, stale-job cleanup, stats, and
 * queue clearing. Exercises real DDL against the custom table.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\JobQueueManager;

/**
 * Fixture callable for queue jobs (instantiated per execution, matching
 * the base's callable-resolution contract).
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The queue-callable fixture shares this file with its test case.
class JobQueueFixture {

	/**
	 * Success result.
	 *
	 * Static so the `array( Class::class, 'run' )` callable form passes
	 * `is_callable()` on PHP 8+ (non-static methods are no longer valid
	 * static callables), matching the base plugin's test-worker pattern.
	 *
	 * @return string
	 */
	public static function run() {
		return 'fixture-ran';
	}

	/**
	 * Failure result (WP_Error flows into the retry/fail path).
	 *
	 * Static for the same callable-validation reason as run().
	 *
	 * @return \WP_Error
	 */
	public static function fail() {
		return new \WP_Error( 'fixture_fail', 'Fixture failed.' );
	}
}

/**
 * @group queues
 */
class Test_Job_Queue_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		$this->suspend_temporary_table_rewrite();
		JobQueueManager::create_table();
		$this->truncate_table();
	}

	public function tearDown(): void {
		global $wpdb;

		// Drop the real table BEFORE re-arming the framework rewrite so the
		// cleanup is not redirected to a TEMPORARY table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . JobQueueManager::TABLE_NAME );

		// The drop happens behind the class's back — reset the memoized
		// table-existence probe so later test files (SlaManager's queue
		// metrics) re-check instead of querying a table that no longer
		// exists (which would print wpdb errors and mark them risky).
		JobQueueManager::reset_table_exists_cache();

		$this->restore_temporary_table_rewrite();

		parent::tearDown();
	}

	/**
	 * Suspend the WP test framework's CREATE/DROP TABLE → TEMPORARY rewrite.
	 *
	 * WP_UnitTestCase::start_transaction() rewrites every `CREATE TABLE` and
	 * `DROP TABLE` issued through $wpdb->query() to operate on TEMPORARY
	 * tables so tests cannot touch the real database. Temporary tables are
	 * invisible to `SHOW TABLES`, so JobQueueManager::use_custom_table() can
	 * never observe them. The queue tests in this file exercise the real
	 * schema contract, so they opt out for their duration and clean up in
	 * tearDown().
	 *
	 * Also drops any TEMPORARY shadow table left on this connection: MySQL
	 * prefers the temporary table over a real one of the same name, which
	 * would silently divert the subsequent real CREATE TABLE.
	 *
	 * @return void
	 */
	private function suspend_temporary_table_rewrite(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$table_name = $wpdb->prefix . JobQueueManager::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Re-arm the framework's TEMPORARY-table rewrite for subsequent tests.
	 *
	 * @return void
	 */
	private function restore_temporary_table_rewrite(): void {
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Truncate the queue table for deterministic counts.
	 *
	 * @return void
	 */
	private function truncate_table(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . JobQueueManager::TABLE_NAME ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name; test isolation on the custom table.
	}

	/**
	 * Fetch a raw row by job ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Raw row.
	 */
	private function raw_row( string $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read on the custom table.
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . JobQueueManager::TABLE_NAME . ' WHERE job_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name.
				$job_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ─── Constants + schema ─────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp_ai_concurrent_jobs', JobQueueManager::TABLE_NAME );
		$this->assertSame( 'wp_mcp_ai_job_queue_state', JobQueueManager::QUEUE_STATE_OPTION );
		$this->assertSame( 'wp_mcp_ai_active_jobs', JobQueueManager::ACTIVE_JOBS_OPTION );

		$this->assertSame( 3, JobQueueManager::DEFAULT_MAX_CONCURRENT );
		$this->assertSame( 300, JobQueueManager::DEFAULT_JOB_TIMEOUT );

		$this->assertSame( 10, JobQueueManager::PRIORITY_HIGH );
		$this->assertSame( 5, JobQueueManager::PRIORITY_NORMAL );
		$this->assertSame( 1, JobQueueManager::PRIORITY_LOW );

		$this->assertSame( 'pending', JobQueueManager::STATUS_PENDING );
		$this->assertSame( 'active', JobQueueManager::STATUS_ACTIVE );
		$this->assertSame( 'failed', JobQueueManager::STATUS_FAILED );
		$this->assertSame( 'complete', JobQueueManager::STATUS_COMPLETE );
	}

	public function test_create_table_schema(): void {
		global $wpdb;

		$table   = $wpdb->prefix . JobQueueManager::TABLE_NAME;
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static test table name.

		$expected = array(
			'id',
			'job_id',
			'callable_class',
			'callable_method',
			'args',
			'priority',
			'sla_tier',
			'status',
			'timeout',
			'retry_count',
			'max_retries',
			'last_error',
			'enqueued_at',
			'started_at',
			'completed_at',
			'failed_at',
		);

		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	// ─── Enqueue ────────────────────────────────────────────────────

	public function test_enqueue_validation(): void {
		$this->assertFalse( JobQueueManager::enqueue_job( '', array( 'callable' => 'strlen' ) ) );
		$this->assertFalse( JobQueueManager::enqueue_job( 'bad-job', array() ) );
		$this->assertFalse( JobQueueManager::enqueue_job( 'bad-job', array( 'callable' => 'no_such_function_xyz' ) ) );
	}

	public function test_enqueue_stores_row_and_extracts_callable(): void {
		$enqueued = JobQueueManager::enqueue_job(
			'job-1',
			array(
				'callable' => array( JobQueueFixture::class, 'run' ),
				'args'     => array(),
				'priority' => JobQueueManager::PRIORITY_HIGH,
			)
		);

		$this->assertTrue( $enqueued );

		$row = $this->raw_row( 'job-1' );
		$this->assertSame( JobQueueFixture::class, $row['callable_class'] );
		$this->assertSame( 'run', $row['callable_method'] );
		$this->assertSame( JobQueueManager::STATUS_PENDING, $row['status'] );
		$this->assertSame( (string) JobQueueManager::PRIORITY_HIGH, $row['priority'] );
		$this->assertSame( '[]', $row['args'] );
	}

	public function test_enqueue_rejects_duplicates(): void {
		$this->assertTrue(
			JobQueueManager::enqueue_job( 'job-dup', array( 'callable' => 'strlen' ) )
		);
		$this->assertFalse(
			JobQueueManager::enqueue_job( 'job-dup', array( 'callable' => 'strlen' ) )
		);
	}

	// ─── Process cycle ──────────────────────────────────────────────

	public function test_process_queue_no_pending_jobs(): void {
		$result = JobQueueManager::process_queue( 3 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 'no_pending_jobs', $result['reason'] );
	}

	public function test_process_queue_at_capacity(): void {
		global $wpdb;

		// Seed two ACTIVE rows directly (simulating in-flight jobs).
		foreach ( array( 'active-1', 'active-2' ) as $job_id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Seeding the custom table for the test.
				$wpdb->prefix . JobQueueManager::TABLE_NAME,
				array(
					'job_id'          => $job_id,
					'callable_class'  => JobQueueFixture::class,
					'callable_method' => 'run',
					'args'            => '[]',
					'priority'        => JobQueueManager::PRIORITY_NORMAL,
					'status'          => JobQueueManager::STATUS_ACTIVE,
					'timeout'         => JobQueueManager::DEFAULT_JOB_TIMEOUT,
					'enqueued_at'     => time(),
					'started_at'      => time(),
				)
			);
		}

		$result = JobQueueManager::process_queue( 2 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 2, $result['active'] );
		$this->assertSame( 'at_capacity', $result['reason'] );
	}

	public function test_process_queue_executes_and_completes(): void {
		JobQueueManager::enqueue_job(
			'job-run',
			array(
				'callable' => array( JobQueueFixture::class, 'run' ),
				'args'     => array(),
			)
		);

		$result = JobQueueManager::process_queue( 3 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'success', $result['reason'] );

		// Completed jobs are removed from the table.
		$this->assertNull( $this->raw_row( 'job-run' ) );
	}

	public function test_process_queue_claims_by_priority_order(): void {
		// Two jobs: the low-priority one is enqueued first, the high one
		// second — claiming must pick the high-priority row first.
		JobQueueManager::enqueue_job(
			'low-job',
			array(
				'callable' => array( JobQueueFixture::class, 'run' ),
				'priority' => JobQueueManager::PRIORITY_LOW,
			)
		);
		JobQueueManager::enqueue_job(
			'high-job',
			array(
				'callable' => array( JobQueueFixture::class, 'run' ),
				'priority' => JobQueueManager::PRIORITY_HIGH,
			)
		);

		$result = JobQueueManager::process_queue( 1 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertNull( $this->raw_row( 'high-job' ) );
		$this->assertNotNull( $this->raw_row( 'low-job' ) );
	}

	public function test_process_queue_retry_then_fail(): void {
		JobQueueManager::enqueue_job(
			'job-fail',
			array(
				'callable' => array( JobQueueFixture::class, 'fail' ),
				'args'     => array(),
			)
		);

		// Attempts 1–3: retry (pending, retry_count 1→3).
		JobQueueManager::process_queue( 3 );
		$row = $this->raw_row( 'job-fail' );
		$this->assertSame( JobQueueManager::STATUS_PENDING, $row['status'] );
		$this->assertSame( '1', $row['retry_count'] );

		JobQueueManager::process_queue( 3 );
		JobQueueManager::process_queue( 3 );

		// Attempt 4: max retries exceeded → failed.
		JobQueueManager::process_queue( 3 );
		$row = $this->raw_row( 'job-fail' );
		$this->assertSame( JobQueueManager::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Fixture failed.', $row['last_error'] );
	}

	// ─── Lifecycle + stats ──────────────────────────────────────────

	public function test_cleanup_stale_jobs_returns_timed_out_to_pending(): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Seeding the custom table for the test.
			$wpdb->prefix . JobQueueManager::TABLE_NAME,
			array(
				'job_id'          => 'stale-1',
				'callable_class'  => JobQueueFixture::class,
				'callable_method' => 'run',
				'args'            => '[]',
				'priority'        => JobQueueManager::PRIORITY_NORMAL,
				'status'          => JobQueueManager::STATUS_ACTIVE,
				'timeout'         => 1,
				'enqueued_at'     => time() - 600,
				'started_at'      => time() - 600,
			)
		);

		$cleaned = self::call_cleanup();

		$this->assertSame( 1, $cleaned );
		$row = $this->raw_row( 'stale-1' );
		$this->assertSame( JobQueueManager::STATUS_PENDING, $row['status'] );
		$this->assertSame( 'Job timed out', $row['last_error'] );
	}

	/**
	 * Invoke the protected cleanup through a subclass.
	 *
	 * @return int Cleaned count.
	 */
	private function call_cleanup(): int {
		$class = get_class(
			new class() extends JobQueueManager {
				public static function invoke_cleanup(): int {
					return self::cleanup_stale_jobs();
				}
			}
		);

		return $class::invoke_cleanup();
	}

	public function test_get_queue_stats_counts(): void {
		JobQueueManager::enqueue_job( 's-1', array( 'callable' => 'strlen' ) );
		JobQueueManager::enqueue_job( 's-2', array( 'callable' => 'strlen' ) );

		$stats = JobQueueManager::get_queue_stats();

		$this->assertSame( 2, $stats['total'] );
		$this->assertSame( 0, $stats['active'] );
		$this->assertSame( 2, $stats['pending'] );
		$this->assertSame( 0, $stats['failed'] );
	}

	public function test_clear_queue(): void {
		JobQueueManager::enqueue_job( 'c-1', array( 'callable' => 'strlen' ) );

		$this->assertTrue( JobQueueManager::clear_queue() );

		$stats = JobQueueManager::get_queue_stats();
		$this->assertSame( 0, $stats['total'] );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
