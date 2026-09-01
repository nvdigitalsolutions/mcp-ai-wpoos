<?php
/**
 * Transcript Retention port tests (Wave D1f).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\TranscriptRetention`. Assertions pin behaviour
 * against the base plugin's `WP_MCP_AI_Transcript_Retention` (ecosystem
 * port plan, principle: behaviour-preserving). The settings and table
 * seams are overridden by a seamed subclass so sweeps run against a real
 * test table in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\TranscriptRetention;

/**
 * Seamed subclass: test-controlled settings + real test table.
 */
class Test_Retention_Seamed extends TranscriptRetention {
	public static $settings = array();
	public static $table_base = 'mcp_ai_chat_transcripts_test';

	protected static function get_setting( $key, $default ) {
		return array_key_exists( $key, self::$settings ) ? self::$settings[ $key ] : $default;
	}

	protected static function get_transcript_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::$table_base;
	}
}

/**
 * Seamed subclass with no storage — deterministic in BOTH matrices (in
 * monolith the unseamed port would delegate to the base plugin's live
 * transcript repository, which must not be swept by tests).
 */
class Test_Retention_No_Storage extends TranscriptRetention {
	protected static function get_transcript_table(): string {
		return '';
	}
}

/**
 * @group chat
 */
class Test_Transcript_Retention extends \WP_UnitTestCase {

	private $table;

	public function setUp(): void {
		parent::setUp();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$this->table = $wpdb->prefix . Test_Retention_Seamed::$table_base;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL on a plugin-owned table; name from a class constant.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$this->table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL on a plugin-owned table; name from a class constant.
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL on a plugin-owned table; name from a class constant.
		$wpdb->query(
			"CREATE TABLE {$this->table} (
				_ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				cct_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				cct_created DATETIME NOT NULL,
				PRIMARY KEY (_ID)
			) {$wpdb->get_charset_collate()}"
		);

		Test_Retention_Seamed::$settings = array(
			'transcript_retention_enabled'    => true,
			'transcript_retention_days'       => 90,
			'transcript_guest_retention_days' => 7,
			'transcript_per_user_max'         => 500,
		);
	}

	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture cleanup on a plugin-owned table; name from a class constant.
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table}" );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\wp_clear_scheduled_hook( TranscriptRetention::CRON_HOOK );
		parent::tearDown();
	}

	private function seed( $author_id, $created ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture insert on a plugin-owned table; name from a class constant.
		$wpdb->insert(
			$this->table,
			array(
				'cct_author_id' => $author_id,
				'cct_created'   => gmdate( 'Y-m-d H:i:s', $created ),
			),
			array( '%d', '%s' )
		);
	}

	private function total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion on a plugin-owned table; name from a class constant.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	public function test_no_storage_is_graceful_noop(): void {
		// Forced-empty seam (see Test_Retention_No_Storage): deterministic in
		// both matrices and never touches the base plugin's live repository.
		Test_Retention_No_Storage::run_retention_sweep();
		$stats = Test_Retention_No_Storage::get_retention_stats();

		$this->assertFalse( $stats['available'] );
	}

	public function test_sweep_prunes_by_age_for_users_and_guests(): void {
		$old = time() - ( 200 * DAY_IN_SECONDS );
		$recent = time() - DAY_IN_SECONDS;

		// Old user rows → pruned; old guest rows → pruned (guest window);
		// recent rows → kept.
		$this->seed( 11, $old );
		$this->seed( 11, $old );
		$this->seed( 11, $recent );
		$this->seed( 0, $old );
		$this->seed( 0, $recent );

		Test_Retention_Seamed::run_retention_sweep();

		$this->assertSame( 2, $this->total() );
	}

	public function test_sweep_skips_when_disabled(): void {
		Test_Retention_Seamed::$settings['transcript_retention_enabled'] = false;

		$this->seed( 11, time() - ( 200 * DAY_IN_SECONDS ) );
		Test_Retention_Seamed::run_retention_sweep();

		$this->assertSame( 1, $this->total() );
	}

	public function test_sweep_enforces_per_user_cap_oldest_first(): void {
		Test_Retention_Seamed::$settings['transcript_per_user_max'] = 2;

		// 4 recent rows for one user → the 2 oldest are pruned.
		$base = time() - ( 10 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->seed( 22, $base + $i );
		}

		Test_Retention_Seamed::run_retention_sweep();

		$this->assertSame( 2, $this->total() );
	}

	public function test_get_retention_stats_shape(): void {
		$this->seed( 11, time() - DAY_IN_SECONDS );
		$this->seed( 0, time() - DAY_IN_SECONDS );

		$stats = Test_Retention_Seamed::get_retention_stats();

		$this->assertTrue( $stats['available'] );
		$this->assertSame( 2, $stats['total'] );
		$this->assertSame( 1, $stats['guest_total'] );
		$this->assertSame( 1, $stats['user_total'] );
		$this->assertSame( 1, $stats['unique_users'] );
		$this->assertSame( 90, $stats['retention_days'] );
		$this->assertTrue( $stats['retention_enabled'] );
		$this->assertSame( 500, $stats['per_user_max'] );
	}

	public function test_delete_endpoint_rejects_without_storage(): void {
		$request = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/chat-transcripts/1' );
		$request->set_param( 'transcript_id', 1 );

		$result = Test_Retention_No_Storage::handle_delete_transcript( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'no_storage', $result->get_error_code() );
	}

	public function test_delete_endpoint_enforces_ownership(): void {
		$user_id = self::factory()->user->create();
		\wp_set_current_user( $user_id );

		$this->seed( $user_id, time() - DAY_IN_SECONDS );
		global $wpdb;
		$transcript_id = (int) $wpdb->insert_id;

		// Owner deletes their own transcript.
		$request = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/chat-transcripts/' . $transcript_id );
		$request->set_param( 'transcript_id', $transcript_id );
		$result = Test_Retention_Seamed::handle_delete_transcript( $request );
		$this->assertNotWPError( $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 0, $this->total() );

		// A different (non-admin) user cannot delete someone else's.
		$this->seed( 999, time() - DAY_IN_SECONDS );
		$transcript_id = (int) $wpdb->insert_id;

		$other_user = self::factory()->user->create();
		\wp_set_current_user( $other_user );

		$request = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/chat-transcripts/' . $transcript_id );
		$request->set_param( 'transcript_id', $transcript_id );
		$result = Test_Retention_Seamed::handle_delete_transcript( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_init_registers_cron_sweep_and_scheduling(): void {
		TranscriptRetention::init();

		$this->assertNotFalse( has_action( TranscriptRetention::CRON_HOOK, array( TranscriptRetention::class, 'run_retention_sweep' ) ) );

		TranscriptRetention::maybe_schedule();
		$this->assertNotFalse( \wp_next_scheduled( TranscriptRetention::CRON_HOOK ) );
	}
}
