<?php
/**
 * Dead letter queue port tests (Wave E2).
 *
 * Characterization suite for the ported `DeadLetterQueue`:
 * byte-identical table schema, type/limit/retention constants, add
 * validation + row shapes, filtered reads, dismiss/remove, retention
 * purge, stats, retry dispatch (validation errors, cron reschedule,
 * job-queue re-enqueue), retry-history tracking, cron wiring, and the
 * JobQueueManager exhausted-failure forwarding. Exercises real DDL
 * against the custom table.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\DeadLetterQueue;
use NvoosContentGraphAiPlatform\Queues\JobQueueManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam subclass exposing protected members for contract testing.
 */
class DeadLetterQueueSeam extends DeadLetterQueue {

	/**
	 * Expose generate_item_id().
	 *
	 * @param string $type       Item type.
	 * @param string $identifier Item identifier.
	 * @return string Item ID.
	 */
	public static function seam_generate_item_id( $type, $identifier ) {
		return self::generate_item_id( $type, $identifier );
	}

	/**
	 * Expose get_valid_types().
	 *
	 * @return array Valid types.
	 */
	public static function seam_valid_types() {
		return self::get_valid_types();
	}
}

/**
 * Plain callable fixture for job-queue failure forwarding.
 *
 * Must NOT extend PHPUnit's TestCase: JobQueueManager::call_job()
 * instantiates the stored callable class with no constructor args, and
 * PHPUnit 11's constructor requires the test name.
 */
class DeadLetterQueueJobFixture {

	/**
	 * Failing callable (WP_Error flows into the retry/fail path).
	 *
	 * Static so the `array( Class::class, 'fail' )` callable form passes
	 * `is_callable()` on PHP 8+.
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
class Test_Dead_Letter_Queue extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		$this->suspend_temporary_table_rewrite();
		DeadLetterQueue::create_table();
		$this->truncate_table();
	}

	public function tearDown(): void {
		// Leave the REAL table in place (created by the standalone wiring at
		// bootstrap) and truncate it instead of dropping: the merged
		// JobQueueManager forwarding path depends on it in later test files,
		// and its static table cache would otherwise point at a dropped
		// table. Recreate if a mid-file test removed it.
		DeadLetterQueue::create_table();
		$this->truncate_table();

		wp_clear_scheduled_hook( 'wp_mcp_ai_dlq_cleanup' );
		remove_all_filters( 'wp_mcp_ai_dlq_retention_days' );

		$this->restore_temporary_table_rewrite();

		parent::tearDown();
	}

	/**
	 * Suspend the WP test framework's CREATE/DROP TABLE → TEMPORARY rewrite.
	 * Drop the concurrent-jobs table (test fixture DDL).
	 *
	 * @return void
	 */
	private function drop_concurrent_jobs_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL on a plugin-owned table; name from a class constant.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . JobQueueManager::TABLE_NAME );

		// The drop happens behind the class's back — reset the memoized
		// table-existence probe so later probes re-check instead of trusting
		// the now-stale cache.
		JobQueueManager::reset_table_exists_cache();
	}

	/**
	 * Suspend the WP test framework's CREATE/DROP TABLE → TEMPORARY rewrite.
	 *
	 * WP_UnitTestCase::start_transaction() rewrites every `CREATE TABLE` and
	 * `DROP TABLE` issued through $wpdb->query() to operate on TEMPORARY
	 * tables so tests cannot touch the real database. Temporary tables are
	 * invisible to `SHOW TABLES`, so DeadLetterQueue::use_custom_table() can
	 * never observe them. The DLQ tests in this file exercise the real
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
		$table_name = $wpdb->prefix . DeadLetterQueue::TABLE_NAME;
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
	 * Truncate the DLQ table for deterministic counts.
	 *
	 * @return void
	 */
	private function truncate_table(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . DeadLetterQueue::TABLE_NAME ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Static plugin-controlled table name; test isolation on the custom table.
	}

	/**
	 * Add a webhook item and return its ID.
	 *
	 * @param string $identifier Item identifier.
	 * @param string $reason     Failure reason.
	 * @return string Item ID.
	 */
	private function add_webhook( string $identifier, string $reason = 'Failed' ): string {
		DeadLetterQueue::add( DeadLetterQueue::TYPE_WEBHOOK, $identifier, array( 'url' => 'https://example.com/hook' ), $reason );

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		return $item_id;
	}

	// ─── Constants + schema ─────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp_ai_dead_letters', DeadLetterQueue::TABLE_NAME );
		$this->assertSame( 'wp_mcp_ai_dead_letter_queue', DeadLetterQueue::OPTION_NAME );
		$this->assertSame( 1000, DeadLetterQueue::MAX_ITEMS );
		$this->assertSame( 30, DeadLetterQueue::DEFAULT_RETENTION_DAYS );

		$this->assertSame( 'cron_job', DeadLetterQueue::TYPE_CRON_JOB );
		$this->assertSame( 'webhook', DeadLetterQueue::TYPE_WEBHOOK );
		$this->assertSame( 'async_tool', DeadLetterQueue::TYPE_ASYNC_TOOL );
		$this->assertSame( 'job_queue', DeadLetterQueue::TYPE_JOB_QUEUE );
		$this->assertSame( 'mesh_query', DeadLetterQueue::TYPE_MESH_QUERY );
	}

	public function test_create_table_schema(): void {
		global $wpdb;

		$table   = $wpdb->prefix . DeadLetterQueue::TABLE_NAME;
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static test table name.

		$expected = array(
			'id',
			'item_id',
			'type',
			'identifier',
			'data',
			'failure_reason',
			'retry_history',
			'retry_count',
			'dismissed',
			'added_at',
			'added_timestamp',
			'dismissed_at',
		);

		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	// ─── Add / read ─────────────────────────────────────────────────

	public function test_add_stores_row_with_shapes(): void {
		$retry_history = array(
			array(
				'timestamp' => time() - 300,
				'result'    => 'failed',
				'error'     => 'Connection timeout',
			),
			array(
				'timestamp' => time(),
				'result'    => 'failed',
				'error'     => 'Network error',
			),
		);

		$added = DeadLetterQueue::add(
			DeadLetterQueue::TYPE_WEBHOOK,
			'webhook-1',
			array(
				'url'     => 'https://example.com/hook',
				'payload' => array( 'a' => 1 ),
			),
			'Connection timeout',
			$retry_history
		);

		$this->assertTrue( $added );

		$items = DeadLetterQueue::get_all();
		$this->assertCount( 1, $items );

		$item = reset( $items );
		$this->assertSame( DeadLetterQueue::TYPE_WEBHOOK, $item['type'] );
		$this->assertSame( 'webhook-1', $item['identifier'] );
		$this->assertSame( 'Connection timeout', $item['failure_reason'] );
		$this->assertSame(
			array(
				'url'     => 'https://example.com/hook',
				'payload' => array( 'a' => 1 ),
			),
			$item['data']
		);
		$this->assertSame( 2, $item['retry_count'] );
		$this->assertCount( 2, $item['retry_history'] );
		$this->assertSame( 'Connection timeout', $item['retry_history'][0]['error'] );
		$this->assertFalse( $item['dismissed'] );
		$this->assertNotEmpty( $item['added_at'] );
		$this->assertGreaterThan( 0, $item['added_timestamp'] );
		$this->assertArrayNotHasKey( 'dismissed_at', $item );
	}

	public function test_add_rejects_invalid_type(): void {
		$result = DeadLetterQueue::add( 'invalid_type', 'test-id', array(), 'Test failure' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_type', $result->get_error_code() );

		// Base quirk preserved: TYPE_MESH_QUERY exists but is not accepted.
		$mesh = DeadLetterQueue::add( DeadLetterQueue::TYPE_MESH_QUERY, 'test-id', array(), 'Test failure' );
		$this->assertWPError( $mesh );
		$this->assertSame( 'invalid_type', $mesh->get_error_code() );
	}

	public function test_add_rejects_empty_identifier(): void {
		$result = DeadLetterQueue::add( DeadLetterQueue::TYPE_WEBHOOK, '', array(), 'Test failure' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_identifier', $result->get_error_code() );
	}

	public function test_add_returns_false_when_table_missing(): void {
		global $wpdb;

		// Drop the real table (rewrite suspended in setUp) and verify the
		// documented degradation: no option-fallback, no fatal.
		$wpdb->query( 'DROP TABLE ' . $wpdb->prefix . DeadLetterQueue::TABLE_NAME ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL on a plugin-owned table; name from a class constant.

		$wpdb->suppress_errors( true );
		$result = DeadLetterQueue::add( DeadLetterQueue::TYPE_WEBHOOK, 'no-table', array(), 'Failed' );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $result );
	}

	public function test_get_and_get_by_type(): void {
		$this->add_webhook( 'webhook-1' );
		$this->add_webhook( 'webhook-2' );
		DeadLetterQueue::add( DeadLetterQueue::TYPE_CRON_JOB, 'cron-1', array(), 'Cron failed' );

		$webhooks = DeadLetterQueue::get_by_type( DeadLetterQueue::TYPE_WEBHOOK );
		$this->assertCount( 2, $webhooks );

		$cron_jobs = DeadLetterQueue::get_by_type( DeadLetterQueue::TYPE_CRON_JOB );
		$this->assertCount( 1, $cron_jobs );

		$item_id = (string) key( $webhooks );
		$single  = DeadLetterQueue::get( $item_id );
		$this->assertNotNull( $single );
		$this->assertSame( 'webhook', $single['type'] );

		$this->assertNull( DeadLetterQueue::get( 'missing-item' ) );
	}

	public function test_get_all_filters(): void {
		global $wpdb;

		$this->add_webhook( 'filter-1' );
		$this->add_webhook( 'filter-2' );
		$this->add_webhook( 'filter-3' );

		// Backdate one row by a day and another by an hour.
		$items = DeadLetterQueue::get_all();
		$ids   = array_keys( $items );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Seeding the custom table for the test; static table name.
		$wpdb->update(
			$wpdb->prefix . DeadLetterQueue::TABLE_NAME,
			array( 'added_timestamp' => time() - DAY_IN_SECONDS ),
			array( 'item_id' => $ids[0] )
		);
		$wpdb->update(
			$wpdb->prefix . DeadLetterQueue::TABLE_NAME,
			array( 'added_timestamp' => time() - HOUR_IN_SECONDS ),
			array( 'item_id' => $ids[1] )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$from  = gmdate( 'Y-m-d H:i:s', time() - ( 12 * HOUR_IN_SECONDS ) );
		$to    = gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) );
		$range = DeadLetterQueue::get_all(
			array(
				'date_from' => $from,
				'date_to'   => $to,
			)
		);
		$this->assertCount( 1, $range );
		$this->assertSame( $ids[1], key( $range ) );

		// Dismissed filter (no items dismissed yet → empty).
		$this->assertCount( 0, DeadLetterQueue::get_all( array( 'dismissed' => true ) ) );
		$this->assertCount( 3, DeadLetterQueue::get_all( array( 'dismissed' => false ) ) );
	}

	// ─── Lifecycle ──────────────────────────────────────────────────

	public function test_dismiss_and_remove(): void {
		$item_id = $this->add_webhook( 'lifecycle-1' );

		$this->assertTrue( DeadLetterQueue::dismiss( $item_id ) );

		$dismissed = DeadLetterQueue::get_all( array( 'dismissed' => true ) );
		$this->assertCount( 1, $dismissed );
		$this->assertNotEmpty( $dismissed[ $item_id ]['dismissed_at'] );

		$this->assertTrue( DeadLetterQueue::remove( $item_id ) );
		$this->assertCount( 0, DeadLetterQueue::get_all() );
	}

	public function test_purge_old_backdated(): void {
		global $wpdb;

		$this->add_webhook( 'old-1' );
		$this->add_webhook( 'recent-1' );

		$old_id = null;
		foreach ( DeadLetterQueue::get_all() as $id => $item ) {
			if ( 'old-1' === $item['identifier'] ) {
				$old_id = $id;
			}
		}
		$this->assertNotNull( $old_id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding the custom table for the test.
			$wpdb->prefix . DeadLetterQueue::TABLE_NAME,
			array( 'added_timestamp' => time() - ( 31 * DAY_IN_SECONDS ) ),
			array( 'item_id' => $old_id )
		);

		$this->assertSame( 1, DeadLetterQueue::purge_old( 30 ) );

		$remaining = DeadLetterQueue::get_all();
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'recent-1', reset( $remaining )['identifier'] );
	}

	public function test_get_stats_shape(): void {
		$this->add_webhook( 'stats-1' );
		$this->add_webhook( 'stats-2' );
		DeadLetterQueue::add( DeadLetterQueue::TYPE_CRON_JOB, 'stats-cron', array(), 'Failed' );

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );
		DeadLetterQueue::dismiss( $item_id );

		$stats = DeadLetterQueue::get_stats();

		$this->assertSame( 3, $stats['total'] );
		$this->assertSame( 2, $stats['active'] );
		$this->assertSame( 1, $stats['dismissed'] );
		$this->assertSame( 2, $stats['by_type'][ DeadLetterQueue::TYPE_WEBHOOK ] );
		$this->assertSame( 1, $stats['by_type'][ DeadLetterQueue::TYPE_CRON_JOB ] );
		$this->assertNotEmpty( $stats['oldest_date'] );
		$this->assertNotEmpty( $stats['newest_date'] );
	}

	// ─── Retry ──────────────────────────────────────────────────────

	public function test_retry_item_not_found(): void {
		$result = DeadLetterQueue::retry( 'missing-item' );

		$this->assertWPError( $result );
		$this->assertSame( 'item_not_found', $result->get_error_code() );
	}

	public function test_retry_invalid_webhook_data_records_history(): void {
		DeadLetterQueue::add( DeadLetterQueue::TYPE_WEBHOOK, 'bad-webhook', array( 'payload' => array() ), 'Failed' );

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_webhook_data', $result->get_error_code() );

		// Retry history records the failed attempt.
		$item = DeadLetterQueue::get( $item_id );
		$this->assertCount( 1, $item['retry_history'] );
		$this->assertSame( 'failed', $item['retry_history'][0]['result'] );
		$this->assertSame( 'Webhook data is incomplete.', $item['retry_history'][0]['error_message'] );
	}

	public function test_retry_webhook_sender_unavailable_standalone(): void {
		if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base notifier owns webhook delivery.' );
		}

		DeadLetterQueue::add(
			DeadLetterQueue::TYPE_WEBHOOK,
			'webhook-retry',
			array(
				'url'     => 'https://example.com/hook',
				'payload' => array( 'a' => 1 ),
			),
			'Failed'
		);

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'webhook_sender_unavailable', $result->get_error_code() );
	}

	public function test_retry_invalid_async_tool_data(): void {
		DeadLetterQueue::add( DeadLetterQueue::TYPE_ASYNC_TOOL, 'bad-tool', array(), 'Failed' );

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_async_tool_data', $result->get_error_code() );
	}

	public function test_retry_async_tool_unavailable_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base executor owns async-tool retry.' );
		}

		DeadLetterQueue::add(
			DeadLetterQueue::TYPE_ASYNC_TOOL,
			'async-retry',
			array( 'job_id' => 'job-1' ),
			'Failed'
		);

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'async_executor_unavailable', $result->get_error_code() );
	}

	public function test_retry_invalid_cron_data(): void {
		DeadLetterQueue::add( DeadLetterQueue::TYPE_CRON_JOB, 'bad-cron', array( 'hook' => 'hook' ), 'Failed' );

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_cron_data', $result->get_error_code() );
	}

	public function test_retry_cron_job_reschedules_and_removes(): void {
		DeadLetterQueue::add(
			DeadLetterQueue::TYPE_CRON_JOB,
			'cron-retry',
			array(
				'hook'      => 'wp_mcp_ai_dlq_test_hook',
				'args'      => array( 'arg-1' ),
				'timestamp' => time() + HOUR_IN_SECONDS,
			),
			'Failed'
		);

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$this->assertTrue( DeadLetterQueue::retry( $item_id ) );

		// Item removed on success and the single event is scheduled.
		$this->assertNull( DeadLetterQueue::get( $item_id ) );
		$this->assertNotFalse( wp_next_scheduled( 'wp_mcp_ai_dlq_test_hook', array( 'arg-1' ) ) );

		wp_clear_scheduled_hook( 'wp_mcp_ai_dlq_test_hook', array( 'arg-1' ) );
	}

	public function test_retry_job_queue_reenqueues(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			// The platform queue manager is custom-table only — its table must
			// exist for the re-enqueue to land (the DLQ rewrite is suspended).
			JobQueueManager::create_table();
			JobQueueManager::clear_queue();
		}

		DeadLetterQueue::add(
			DeadLetterQueue::TYPE_JOB_QUEUE,
			'dlq-job-1',
			array(
				'job_id'   => 'dlq-job-1',
				'job_data' => array( 'callable' => 'strlen' ),
			),
			'Failed after retries'
		);

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertTrue( $result );
		$this->assertNull( DeadLetterQueue::get( $item_id ) );

		// Clean up the re-enqueued job.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$stats = JobQueueManager::get_queue_stats();
			$this->assertSame( 1, $stats['total'] );

			JobQueueManager::clear_queue();

			$this->drop_concurrent_jobs_table();
		} else {
			delete_option( 'wp_mcp_ai_job_queue_state' );
			delete_option( 'wp_mcp_ai_active_jobs' );
		}
	}

	public function test_retry_job_queue_invalid_data(): void {
		DeadLetterQueue::add( DeadLetterQueue::TYPE_JOB_QUEUE, 'bad-job', array( 'job_id' => 'x' ), 'Failed' );

		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );

		$result = DeadLetterQueue::retry( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_job_queue_data', $result->get_error_code() );
	}

	// ─── Cron + seams ───────────────────────────────────────────────

	public function test_schedule_cleanup_and_cleanup(): void {
		DeadLetterQueue::schedule_cleanup();

		$next = wp_next_scheduled( 'wp_mcp_ai_dlq_cleanup' );
		$this->assertNotFalse( $next );

		// cleanup() purges items older than the filtered retention.
		$this->add_webhook( 'cleanup-old' );

		global $wpdb;
		$items   = DeadLetterQueue::get_all();
		$item_id = (string) key( $items );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding the custom table for the test.
			$wpdb->prefix . DeadLetterQueue::TABLE_NAME,
			array( 'added_timestamp' => time() - ( 31 * DAY_IN_SECONDS ) ),
			array( 'item_id' => $item_id )
		);

		$filtered = null;
		add_filter(
			'wp_mcp_ai_dlq_retention_days',
			static function () use ( &$filtered ) {
				$filtered = true;
				return 30;
			}
		);

		DeadLetterQueue::cleanup();

		$this->assertTrue( $filtered );
		$this->assertCount( 0, DeadLetterQueue::get_all() );
	}

	public function test_item_id_format_and_valid_types(): void {
		$item_id = DeadLetterQueueSeam::seam_generate_item_id( 'webhook', 'id-1' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $item_id );

		$this->assertSame(
			array(
				DeadLetterQueue::TYPE_CRON_JOB,
				DeadLetterQueue::TYPE_WEBHOOK,
				DeadLetterQueue::TYPE_ASYNC_TOOL,
				DeadLetterQueue::TYPE_JOB_QUEUE,
			),
			DeadLetterQueueSeam::seam_valid_types()
		);
	}

	// ─── JobQueueManager forwarding ─────────────────────────────────

	public function test_job_queue_manager_forwards_exhausted_failures_to_dlq(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns the forwarding path.' );
		}

		// Both tables are real under the suspended rewrite. clear_queue()
		// neutralises rows left over from earlier runs of the shared test DB.
		JobQueueManager::create_table();
		JobQueueManager::clear_queue();

		$enqueued = JobQueueManager::enqueue_job(
			'dlq-forward-1',
			array(
				'callable' => array( DeadLetterQueueJobFixture::class, 'fail' ),
				'args'     => array(),
			)
		);
		$this->assertTrue( $enqueued );

		// Exhaust the retry budget (max_retries = 3).
		JobQueueManager::process_queue( 3 );
		JobQueueManager::process_queue( 3 );
		JobQueueManager::process_queue( 3 );
		JobQueueManager::process_queue( 3 );

		$items = DeadLetterQueue::get_all( array( 'type' => DeadLetterQueue::TYPE_JOB_QUEUE ) );
		$this->assertCount( 1, $items );

		$item = reset( $items );
		$this->assertSame( 'dlq-forward-1', $item['identifier'] );
		$this->assertSame( 'Fixture failed.', $item['failure_reason'] );
		$this->assertArrayHasKey( 'job_id', $item['data'] );
		$this->assertArrayHasKey( 'job_data', $item['data'] );

		// Clean up the concurrent-jobs table (the DLQ table is dropped in
		// tearDown).
		$this->drop_concurrent_jobs_table();
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
