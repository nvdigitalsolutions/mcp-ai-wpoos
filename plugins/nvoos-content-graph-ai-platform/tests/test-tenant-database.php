<?php
/**
 * Tenant database port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantDatabase`: the real-DDL
 * schema (both tables + columns), the version gate, the cached
 * tables_installed() probe, and the assign/get user-tenant mapping
 * lifecycle with the primary user-meta side write. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantDatabase;

/**
 * Tenant database schema + mapping characterization (real DDL).
 */
class Test_Tenant_Database extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Real-DDL contract tests: the harness rewrites CREATE TABLE into
		// CREATE TEMPORARY TABLE, which would break the SHOW TABLES
		// tables_installed() probe contract — suspend the rewrite and
		// create real tables for each test.
		$this->suspend_temporary_table_rewrite();
		TenantDatabase::create_tables();
	}

	public function tearDown(): void {
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Shadow cleanup on plugin-owned tables; names from $wpdb->prefix.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}mcp_ai_tenants" );
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}mcp_ai_tenant_user_map" );
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
		$this->assertSame( '1.0.0', TenantDatabase::DB_VERSION );
		$this->assertSame( 'wp_mcp_ai_tenant_db_version', TenantDatabase::VERSION_OPTION );
	}

	public function test_create_tables_builds_schema(): void {
		global $wpdb;

		TenantDatabase::create_tables();
		$this->assertTrue( TenantDatabase::tables_installed() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}mcp_ai_tenants" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array( 'id', 'tenant_type', 'tenant_name', 'external_id', 'settings', 'is_active', 'created_at', 'updated_at' ) as $column ) {
			$this->assertContains( $column, $columns, "Missing tenants column: {$column}" );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$map_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}mcp_ai_tenant_user_map" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array( 'id', 'user_id', 'tenant_type', 'tenant_id', 'is_primary', 'assigned_at', 'assigned_by' ) as $column ) {
			$this->assertContains( $column, $map_columns, "Missing user-map column: {$column}" );
		}
	}

	public function test_maybe_create_tables_sets_version(): void {
		delete_option( TenantDatabase::VERSION_OPTION );

		TenantDatabase::maybe_create_tables();

		$this->assertEquals( TenantDatabase::DB_VERSION, get_option( TenantDatabase::VERSION_OPTION ) );
	}

	public function test_assign_user_creates_row_and_primary_meta(): void {
		$user_id = self::factory()->user->create();

		$row_id = TenantDatabase::assign_user( $user_id, 'school', 42, true );

		$this->assertIsInt( $row_id );
		$this->assertGreaterThan( 0, $row_id );

		$this->assertEquals(
			array(
				'type' => 'school',
				'id'   => 42,
			),
			get_user_meta( $user_id, '_wp_mcp_ai_tenant', true )
		);
	}

	public function test_assign_user_upserts_existing(): void {
		$user_id = self::factory()->user->create();

		$first  = TenantDatabase::assign_user( $user_id, 'school', 42 );
		$second = TenantDatabase::assign_user( $user_id, 'school', 42 );

		$this->assertEquals( $first, $second );
	}

	public function test_get_user_tenants_orders_primary_first(): void {
		$user_id = self::factory()->user->create();

		TenantDatabase::assign_user( $user_id, 'school', 2, false );
		TenantDatabase::assign_user( $user_id, 'school', 1, true );

		$tenants = TenantDatabase::get_user_tenants( $user_id );

		$this->assertCount( 2, $tenants );
		$this->assertEquals( 1, $tenants[0]['id'] );
		$this->assertTrue( $tenants[0]['is_primary'] );
		$this->assertEquals( 2, $tenants[1]['id'] );
		$this->assertFalse( $tenants[1]['is_primary'] );
	}

	public function test_get_user_tenants_empty_without_tables(): void {
		TenantDatabase::drop_tables();

		$this->assertSame( array(), TenantDatabase::get_user_tenants( 1 ) );
		$this->assertFalse( TenantDatabase::assign_user( 1, 'school', 42 ) );

		TenantDatabase::create_tables();
	}

	public function test_drop_tables_removes_schema_and_version(): void {
		TenantDatabase::create_tables();
		update_option( TenantDatabase::VERSION_OPTION, TenantDatabase::DB_VERSION, false );

		TenantDatabase::drop_tables();

		$this->assertFalse( TenantDatabase::tables_installed() );
		$this->assertFalse( get_option( TenantDatabase::VERSION_OPTION ) );
	}
}
