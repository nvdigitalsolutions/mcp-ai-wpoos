<?php
/**
 * Tests for WP_MCP_AI_Async_Job_Queue table resilience.
 *
 * Covers the missing-table regression where the Load Guard queried the
 * async job queue on every REST dispatch before the table existed (the
 * class was never wired into the boot loader, so its plugins_loaded
 * init hook never fired), flooding the error log with
 * "Table 'wp_mcp_ai_job_queue' doesn't exist" database errors.
 *
 * The wp-phpunit harness rewrites CREATE/DROP TABLE into their TEMPORARY
 * variants while a test transaction is open, so the DDL helpers here lift
 * those query filters (same pattern as
 * test-get-elementor-form-submissions.php) to operate on the real table.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests that the async job queue fails soft when its table is missing
 * and that maybe_create_table() creates it on demand.
 *
 * @covers WP_MCP_AI_Async_Job_Queue::get_queue_stats
 * @covers WP_MCP_AI_Async_Job_Queue::maybe_create_table
 * @covers WP_MCP_AI_Async_Job_Queue::use_custom_table
 */
class Test_Async_Job_Queue_Table_Resilience extends WP_UnitTestCase {

	/**
	 * Full table name (with prefix).
	 *
	 * @var string
	 */
	private $table_name = '';

	/**
	 * Start each test with the queue table and version option absent.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Async_Job_Queue unavailable.' );
		}

		global $wpdb;
		$this->table_name = $wpdb->prefix . WP_MCP_AI_Async_Job_Queue::TABLE_NAME;

		$this->drop_real_table();
		delete_option( 'wp_mcp_ai_async_job_queue_db_version' );
		$wpdb->last_error = '';
	}

	/**
	 * Restore the real table so later suites see a consistent database.
	 */
	public function tear_down() {
		if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->create_real_table();
		}
		parent::tear_down();
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
	 * Drop the real queue table (and any temporary shadow), bypassing the
	 * harness's DROP TABLE → DROP TEMPORARY TABLE rewrite.
	 */
	private function drop_real_table() {
		global $wpdb;

		// A temporary shadow may exist on this connection from earlier
		// suites; drop it through the normal (rewriting) filters.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only DDL on a custom plugin table.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $this->table_name );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only DDL on a custom plugin table.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->table_name );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Get_queue_stats() must return zeroed stats instead of querying a
	 * missing table.
	 */
	public function test_get_queue_stats_fails_soft_when_table_missing() {
		$stats = WP_MCP_AI_Async_Job_Queue::get_queue_stats();

		$this->assertSame(
			array(
				'total'     => 0,
				'queued'    => 0,
				'running'   => 0,
				'completed' => 0,
				'failed'    => 0,
			),
			$stats
		);

		global $wpdb;
		$this->assertSame( '', $wpdb->last_error, 'Missing table must not raise a database error.' );
	}

	/**
	 * Maybe_create_table() must create the table and record the schema
	 * version when both are missing.
	 */
	public function test_maybe_create_table_creates_and_records_version() {
		// Run with the harness rewrite lifted so the table is created for
		// real, mirroring production (no rewrite exists there).
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		WP_MCP_AI_Async_Job_Queue::maybe_create_table();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );

		$this->assertSame( WP_MCP_AI_Async_Job_Queue::DB_VERSION, get_option( 'wp_mcp_ai_async_job_queue_db_version' ) );
		$this->assertSame( '', $wpdb->last_error, 'Table creation must not leave a database error behind.' );

		// The table must be usable: a queued job round-trips.
		$job_id = WP_MCP_AI_Async_Job_Queue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'slug' => 'test_tool' ),
			)
		);
		$this->assertGreaterThan( 0, $job_id );

		$job = WP_MCP_AI_Async_Job_Queue::get_job( $job_id );
		$this->assertSame( 'tool', $job['job_type'] );
		$this->assertSame( 'queued', $job['status'] );
	}

	/**
	 * Maybe_create_table() must be a no-op when the table and version
	 * are already present (no repeated dbDelta on every page load).
	 */
	public function test_maybe_create_table_skips_when_installed() {
		if ( defined( 'SAVEQUERIES' ) && ! SAVEQUERIES ) {
			$this->markTestSkipped( 'SAVEQUERIES is disabled; cannot assert that dbDelta is skipped.' );
		}
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		// First call: create the real table + persist the version.
		$this->create_real_table();
		WP_MCP_AI_Async_Job_Queue::maybe_create_table();
		$this->assertSame( WP_MCP_AI_Async_Job_Queue::DB_VERSION, get_option( 'wp_mcp_ai_async_job_queue_db_version' ) );

		global $wpdb;
		$before = $wpdb->queries;

		// Second call: must short-circuit without touching the schema.
		WP_MCP_AI_Async_Job_Queue::maybe_create_table();

		$new_queries = array_slice( $wpdb->queries, count( $before ) );
		foreach ( $new_queries as $query ) {
			$sql = isset( $query[0] ) ? (string) $query[0] : '';
			$this->assertStringNotContainsString( 'CREATE TABLE', $sql, 'dbDelta must not re-run on every page load.' );
		}
	}

	/**
	 * The Load Guard must not raise database errors when the queue table
	 * is missing (regression: every REST dispatch spammed the log).
	 */
	public function test_load_guard_survives_missing_queue_table() {
		if ( ! class_exists( 'WP_MCP_AI_Load_Guard' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Load_Guard unavailable.' );
		}

		delete_transient( WP_MCP_AI_Load_Guard::RUNNING_COUNT_KEY );

		global $wpdb;
		$wpdb->last_error = '';

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$result  = WP_MCP_AI_Load_Guard::check_load( null, null, $request );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( '', $wpdb->last_error, 'Load Guard must not query a missing table.' );
	}
}
