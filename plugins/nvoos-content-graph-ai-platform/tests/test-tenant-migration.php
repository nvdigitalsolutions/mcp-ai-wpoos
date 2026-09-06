<?php
/**
 * Tenant migration port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantMigration`: the tenant
 * column/index DDL on a real scratch table, the idempotency contracts,
 * the table backfill, and the CPT post-meta migration. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantMigration;

/**
 * Tenant migration helper characterization (real DDL on a scratch table).
 */
class Test_Tenant_Migration extends \WP_UnitTestCase {

	/**
	 * Scratch table name.
	 *
	 * @var string
	 */
	private $table;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->table = $wpdb->prefix . 'tmp_tenant_migration_scratch';

		// Real-DDL contract tests: the harness rewrites CREATE TABLE into
		// CREATE TEMPORARY TABLE, and MySQL hides temporary tables from
		// the INFORMATION_SCHEMA probes this class relies on — suspend the
		// rewrite for the duration of each test.
		$this->suspend_temporary_table_rewrite();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$this->table} ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, value VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id) ) {$wpdb->get_charset_collate()}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function tearDown(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->restore_temporary_table_rewrite();
		parent::tearDown();
	}

	/**
	 * Suspend the harness's TEMPORARY-table rewrite (real-DDL contract).
	 *
	 * @return void
	 */
	private function suspend_temporary_table_rewrite(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scratch-table shadow cleanup; the name is built from the test prefix.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$this->table}" );
	}

	/**
	 * Re-arm the harness's TEMPORARY-table rewrite for subsequent tests.
	 *
	 * @return void
	 */
	private function restore_temporary_table_rewrite(): void {
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( array( 'tenant_type', 'tenant_id' ), TenantMigration::TENANT_COLUMNS );
		$this->assertSame( 'tenant_type', TenantMigration::TENANT_TYPE_COL );
		$this->assertSame( 'tenant_id', TenantMigration::TENANT_ID_COL );
	}

	public function test_add_tenant_columns_adds_both_and_is_idempotent(): void {
		$this->assertTrue( TenantMigration::add_tenant_columns( $this->table ) );
		$this->assertTrue( TenantMigration::has_tenant_columns( $this->table ) );

		// Second pass — columns already exist.
		$this->assertTrue( TenantMigration::add_tenant_columns( $this->table ) );
	}

	public function test_add_tenant_index_adds_lookup_and_is_idempotent(): void {
		TenantMigration::add_tenant_columns( $this->table );

		$this->assertTrue( TenantMigration::add_tenant_index( $this->table ) );
		$this->assertTrue( TenantMigration::add_tenant_index( $this->table ) );
	}

	public function test_add_tenant_index_requires_columns(): void {
		$this->assertFalse( TenantMigration::add_tenant_index( $this->table ) );
	}

	public function test_helpers_reject_empty_table_names(): void {
		$this->assertFalse( TenantMigration::add_tenant_columns( '' ) );
		$this->assertFalse( TenantMigration::add_tenant_index( '' ) );
		$this->assertFalse( TenantMigration::has_tenant_columns( '' ) );
	}

	public function test_backfill_table_updates_unscoped_rows(): void {
		global $wpdb;

		TenantMigration::add_tenant_columns( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "INSERT INTO {$this->table} (value) VALUES ('a'), ('b')" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$updated = TenantMigration::backfill_table( $this->table, 'school', 42 );

		$this->assertEquals( 2, $updated );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT tenant_type, tenant_id FROM {$this->table}", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			$this->assertEquals( 'school', $row['tenant_type'] );
			$this->assertEquals( 42, (int) $row['tenant_id'] );
		}
	}

	public function test_backfill_table_requires_columns_and_valid_args(): void {
		$this->assertEquals( 0, TenantMigration::backfill_table( $this->table, 'school', 42 ) );
		$this->assertEquals( 0, TenantMigration::backfill_table( '', 'school', 42 ) );
		$this->assertEquals( 0, TenantMigration::backfill_table( $this->table, '', 42 ) );
		$this->assertEquals( 0, TenantMigration::backfill_table( $this->table, 'school', 0 ) );
	}

	public function test_migrate_cpt_posts_stamps_only_unset_meta(): void {
		$post_a = self::factory()->post->create();
		$post_b = self::factory()->post->create();
		update_post_meta( $post_b, '_tenant_type', 'existing' );

		$updated = TenantMigration::migrate_cpt_posts( 'post', 'school', 42 );

		$this->assertEquals( 2, $updated );
		$this->assertEquals( 'school', get_post_meta( $post_a, '_tenant_type', true ) );
		$this->assertEquals( 42, (int) get_post_meta( $post_a, '_tenant_id', true ) );
		// Pre-existing meta is preserved.
		$this->assertEquals( 'existing', get_post_meta( $post_b, '_tenant_type', true ) );
	}

	public function test_migrate_cpt_posts_rejects_invalid_args(): void {
		$this->assertEquals( 0, TenantMigration::migrate_cpt_posts( '', 'school', 42 ) );
		$this->assertEquals( 0, TenantMigration::migrate_cpt_posts( 'post', '', 42 ) );
		$this->assertEquals( 0, TenantMigration::migrate_cpt_posts( 'post', 'school', 0 ) );
	}
}
