<?php
/**
 * Test Enhanced Token Tracking Database functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Token_Tracking_Database
 */
class Test_Token_Tracking_Database extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the database table.
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Never leak the creation-retry backoff into other tests.
		delete_transient( WP_MCP_AI_Token_Tracking_Database::RETRY_TRANSIENT );

		// Restore the REAL table so later suites see a consistent database
		// (the harness rewrites CREATE TABLE into a TEMPORARY variant).
		$this->create_real_table();

		// Clean up test data (the temp shadow on this connection).
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only DDL on a plugin-owned table; name from a class constant.
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		parent::tearDown();
	}

	/**
	 * Create the REAL token tracking table, bypassing the harness's
	 * CREATE TABLE → CREATE TEMPORARY TABLE rewrite.
	 */
	private function create_real_table() {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only DDL on a custom plugin table.
		$wpdb->query( 'TRUNCATE TABLE ' . WP_MCP_AI_Token_Tracking_Database::get_table_name() );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Drop the REAL token tracking table (and any temporary shadow),
	 * bypassing the harness's DROP TABLE → DROP TEMPORARY TABLE rewrite.
	 */
	private function drop_real_table() {
		global $wpdb;

		$table = WP_MCP_AI_Token_Tracking_Database::get_table_name();

		// A temporary shadow may exist on this connection from earlier
		// suites; drop it through the normal (rewriting) filters.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only DDL on a custom plugin table.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only DDL on a custom plugin table.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Test table creation.
	 */
	public function test_table_creation() {
		global $wpdb;

		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();

		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertEquals( $table_name, $table_exists, 'Token tracking table should be created' );
	}

	/**
	 * Test recording token usage.
	 */
	public function test_record_usage() {
		$user_id       = $this->factory->user->create();
		$tool          = 'test_tool';
		$provider      = 'openai';
		$model         = 'gpt-4o-mini';
		$input_tokens  = 1000;
		$output_tokens = 500;
		$cost_usd      = 0.15;

		$insert_id = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			$tool,
			$provider,
			$model,
			$input_tokens,
			$output_tokens,
			$cost_usd,
			false
		);

		$this->assertNotFalse( $insert_id, 'Record should be inserted successfully' );
		$this->assertGreaterThan( 0, $insert_id, 'Insert ID should be positive' );
	}

	/**
	 * Test recording usage with automatic cost calculation.
	 */
	public function test_record_usage_auto_cost() {
		$user_id       = $this->factory->user->create();
		$tool          = 'chat';
		$provider      = 'openai';
		$model         = 'gpt-4o-mini';
		$input_tokens  = 1000;
		$output_tokens = 500;

		// Don't provide cost - should be calculated automatically.
		$insert_id = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			$tool,
			$provider,
			$model,
			$input_tokens,
			$output_tokens
		);

		$this->assertNotFalse( $insert_id, 'Record should be inserted with auto-calculated cost' );

		// Verify cost was calculated.
		global $wpdb;
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only read on a plugin-owned table; name from a class constant.
		$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $insert_id ), ARRAY_A );

		$this->assertNotNull( $record, 'Record should exist' );
		$this->assertGreaterThan( 0, floatval( $record['cost_usd'] ), 'Cost should be calculated and > 0' );
	}

	/**
	 * Test getting user usage.
	 */
	public function test_get_user_usage() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		// Record some usage.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool2',
			'gemini',
			'gemini-1.5-flash',
			2000,
			1000,
			0.25,
			false
		);

		$usage = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertIsArray( $usage, 'Usage should be an array' );
		$this->assertCount( 2, $usage, 'Should have 2 usage records' );
	}

	/**
	 * Test getting user usage with tool filter.
	 */
	public function test_get_user_usage_with_tool_filter() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		// Record usage for multiple tools.
		WP_MCP_AI_Token_Tracking_Database::record_usage( $user_id, 'tool1', 'openai', 'gpt-4o', 1000, 500 );
		WP_MCP_AI_Token_Tracking_Database::record_usage( $user_id, 'tool2', 'openai', 'gpt-4o', 1000, 500 );
		WP_MCP_AI_Token_Tracking_Database::record_usage( $user_id, 'tool1', 'openai', 'gpt-4o', 1000, 500 );

		$usage = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date, 'tool1' );

		$this->assertIsArray( $usage, 'Usage should be an array' );
		$this->assertCount( 2, $usage, 'Should have 2 records for tool1' );
	}

	/**
	 * Test getting cost summary.
	 */
	public function test_get_user_cost_summary() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		// Record some actual costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false // actual cost.
		);

		// Record some estimated costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool2',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			true // estimated cost.
		);

		$summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $start_date, $end_date );

		$this->assertIsArray( $summary, 'Summary should be an array' );
		$this->assertArrayHasKey( 'total_cost', $summary );
		$this->assertArrayHasKey( 'total_tokens', $summary );
		$this->assertArrayHasKey( 'estimated_cost', $summary );
		$this->assertArrayHasKey( 'actual_cost', $summary );

		$this->assertEquals( 0.30, $summary['total_cost'], 'Total cost should be 0.30' );
		$this->assertEquals( 0.15, $summary['actual_cost'], 'Actual cost should be 0.15' );
		$this->assertEquals( 0.15, $summary['estimated_cost'], 'Estimated cost should be 0.15' );
		$this->assertEquals( 3000, $summary['total_tokens'], 'Total tokens should be 3000' );
	}

	/**
	 * Test cleanup of old records.
	 */
	public function test_cleanup_old_records() {
		$user_id = $this->factory->user->create();

		// Record usage 100 days ago (should be cleaned up with 90-day retention).
		$old_timestamp = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false,
			$old_timestamp
		);

		// Record recent usage (should NOT be cleaned up).
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool2',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false
		);

		// Run cleanup with 90-day retention.
		$deleted = WP_MCP_AI_Token_Tracking_Database::cleanup_old_records( 90 );

		$this->assertEquals( 1, $deleted, 'Should delete 1 old record' );

		// Verify recent record still exists.
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$usage      = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $usage, 'Should have 1 recent record remaining' );
	}

	/**
	 * Test validation of required fields.
	 */
	public function test_record_usage_validation() {
		// Try to record without user ID.
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			0, // invalid user ID.
			'tool',
			'openai',
			'gpt-4o',
			1000,
			500
		);

		$this->assertFalse( $result, 'Should fail without valid user ID' );

		// Try to record without provider.
		$user_id = $this->factory->user->create();
		$result  = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'', // invalid provider.
			'gpt-4o',
			1000,
			500
		);

		$this->assertFalse( $result, 'Should fail without valid provider' );

		// Try to record without model.
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'openai',
			'', // invalid model.
			1000,
			500
		);

		$this->assertFalse( $result, 'Should fail without valid model' );

		// Try to record with zero tokens.
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'openai',
			'gpt-4o',
			0, // zero tokens.
			0
		);

		$this->assertFalse( $result, 'Should fail with zero tokens' );
	}

	/**
	 * Test empty result for non-existent user.
	 */
	public function test_get_usage_for_nonexistent_user() {
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		$usage = WP_MCP_AI_Token_Tracking_Database::get_user_usage( 99999, $start_date, $end_date );

		$this->assertIsArray( $usage, 'Usage should be an array' );
		$this->assertEmpty( $usage, 'Usage should be empty for non-existent user' );
	}

	/**
	 * Test cost summary for user with no usage.
	 */
	public function test_cost_summary_no_usage() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		$summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $start_date, $end_date );

		$this->assertIsArray( $summary, 'Summary should be an array' );
		$this->assertEquals( 0.0, $summary['total_cost'], 'Total cost should be 0' );
		$this->assertEquals( 0, $summary['total_tokens'], 'Total tokens should be 0' );
	}

	/**
	 * Test action hook is fired after recording.
	 */
	public function test_action_hook_fired() {
		$user_id = $this->factory->user->create();
		$fired   = false;

		$callback = function () use ( &$fired ) {
			$fired = true;
		};

		add_action( 'wp_mcp_ai_token_usage_recorded', $callback );

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'openai',
			'gpt-4o',
			1000,
			500
		);

		$this->assertTrue( $fired, 'Action hook should be fired after recording usage' );

		remove_action( 'wp_mcp_ai_token_usage_recorded', $callback );
	}

	/**
	 * Test the fast path returns true when table and version are current.
	 */
	public function test_maybe_create_fast_path_when_current() {
		$this->assertTrue( WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table() );
		$this->assertSame( WP_MCP_AI_Token_Tracking_Database::DB_VERSION, get_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION ) );
	}

	/**
	 * Test a failed creation never advances the version option and is gated
	 * by the retry backoff transient (broken database layers are not retried
	 * on every request).
	 */
	public function test_maybe_create_backoff_when_creation_fails() {
		global $wpdb;

		$this->drop_real_table();
		delete_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION );

		// Backoff active: no creation attempt, no version bump, no error output.
		set_transient( WP_MCP_AI_Token_Tracking_Database::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );

		ob_start();
		$result = WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
		$output = ob_get_clean();

		$this->assertFalse( $result );
		$this->assertStringNotContainsString( 'WordPress database error', $output );
		$this->assertSame( false, get_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION, false ) );
		$this->assertNotFalse( get_transient( WP_MCP_AI_Token_Tracking_Database::RETRY_TRANSIENT ) );
	}

	/**
	 * Test maybe_create recreates the table and records the version when
	 * the real table is missing (mirrors production, where no harness
	 * rewrite exists).
	 */
	public function test_maybe_create_recovers_missing_table() {
		global $wpdb;

		$this->drop_real_table();
		delete_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION );
		delete_transient( WP_MCP_AI_Token_Tracking_Database::RETRY_TRANSIENT );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		$result = WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );

		$this->assertTrue( $result );
		$this->assertSame( WP_MCP_AI_Token_Tracking_Database::DB_VERSION, get_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION ) );
		$this->assertSame( '', $wpdb->last_error, 'Table creation must not leave a database error behind.' );
	}

	/**
	 * Test record_usage fails silently (no DB error output) when the table
	 * is missing and the backoff gate is active.
	 */
	public function test_record_usage_silent_when_table_missing() {
		$this->drop_real_table();
		delete_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION );
		set_transient( WP_MCP_AI_Token_Tracking_Database::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );

		$user_id = $this->factory->user->create();

		ob_start();
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'chat',
			'openai',
			'gpt-4o',
			1000,
			500
		);
		$output = ob_get_clean();

		$this->assertFalse( $result );
		$this->assertStringNotContainsString( 'WordPress database error', $output );
	}

	/**
	 * Test the read paths degrade gracefully while the table is missing.
	 */
	public function test_read_paths_graceful_when_table_missing() {
		$this->drop_real_table();
		delete_option( WP_MCP_AI_Token_Tracking_Database::DB_VERSION_OPTION );
		set_transient( WP_MCP_AI_Token_Tracking_Database::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );

		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		ob_start();
		$usage      = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );
		$summary    = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $start_date, $end_date );
		$aggregates = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_provider( $start_date, $end_date );
		$cleaned    = WP_MCP_AI_Token_Tracking_Database::cleanup_old_records( 90 );
		$output     = ob_get_clean();

		$this->assertSame( array(), $usage );
		$this->assertSame(
			array(
				'total_cost'     => 0.0,
				'total_tokens'   => 0,
				'estimated_cost' => 0.0,
				'actual_cost'    => 0.0,
			),
			$summary
		);
		$this->assertSame( array(), $aggregates );
		$this->assertSame( 0, $cleaned );
		$this->assertStringNotContainsString( 'WordPress database error', $output );
	}
}
