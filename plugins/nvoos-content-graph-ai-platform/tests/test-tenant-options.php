<?php
/**
 * Tenant options port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantOptions`: the scoped +
 * type-level key formats, per-tenant and per-type isolation, the
 * autoload pass-through, and the `from_context()` factory. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantContext;
use NvoosContentGraphAiPlatform\Tenant\TenantOptions;

/**
 * Tenant options characterization.
 */
class Test_Tenant_Options extends \WP_UnitTestCase {

	public function tearDown(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_%'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		TenantContext::reset();
		parent::tearDown();
	}

	public function test_scoped_key_is_prefixed(): void {
		$opts = new TenantOptions( 'school', 42 );

		$opts->update( 'test_setting', 'hello', false );

		$this->assertEquals( 'hello', get_option( 'wp_mcp_ai_school_42_test_setting' ) );
	}

	public function test_get_scoped_option(): void {
		$opts = new TenantOptions( 'school', 1 );
		$opts->update( 'test_get', 'value_a', false );

		$this->assertEquals( 'value_a', $opts->get( 'test_get' ) );
	}

	public function test_get_returns_default(): void {
		$opts = new TenantOptions( 'school', 99 );
		$this->assertEquals( 'fallback', $opts->get( 'nonexistent', 'fallback' ) );
	}

	public function test_tenant_isolation(): void {
		$opts_a = new TenantOptions( 'school', 1 );
		$opts_b = new TenantOptions( 'school', 2 );

		$opts_a->update( 'test_isolation', 'tenant_a_value', false );
		$opts_b->update( 'test_isolation', 'tenant_b_value', false );

		$this->assertEquals( 'tenant_a_value', $opts_a->get( 'test_isolation' ) );
		$this->assertEquals( 'tenant_b_value', $opts_b->get( 'test_isolation' ) );
	}

	public function test_delete_scoped_option(): void {
		$opts = new TenantOptions( 'school', 1 );
		$opts->update( 'test_delete', 'value', false );

		$opts->delete( 'test_delete' );
		$this->assertFalse( get_option( 'wp_mcp_ai_school_1_test_delete' ) );
	}

	public function test_type_level_options(): void {
		$opts = new TenantOptions( 'school', 1 );
		$opts->update_type_option( 'global_setting', 'shared', false );

		$opts2 = new TenantOptions( 'school', 999 );
		$this->assertEquals( 'shared', $opts2->get_type_option( 'global_setting' ) );
	}

	public function test_from_context_returns_null_without_tenant(): void {
		wp_set_current_user( 0 );
		$this->assertNull( TenantOptions::from_context() );
	}

	public function test_from_context_returns_instance_with_tenant(): void {
		TenantContext::instance()->set( 'school', 42 );
		$opts = TenantOptions::from_context();

		$this->assertInstanceOf( TenantOptions::class, $opts );
	}

	public function test_different_types_same_id_dont_collide(): void {
		$opts_school = new TenantOptions( 'school', 1 );
		$opts_org    = new TenantOptions( 'org', 1 );

		$opts_school->update( 'name', 'School Alpha', false );
		$opts_org->update( 'name', 'Org Alpha', false );

		$this->assertEquals( 'School Alpha', $opts_school->get( 'name' ) );
		$this->assertEquals( 'Org Alpha', $opts_org->get( 'name' ) );
	}

	public function test_delete_type_option(): void {
		$opts = new TenantOptions( 'school', 1 );
		$opts->update_type_option( 'test_type_delete', 'value', false );

		$opts->delete_type_option( 'test_type_delete' );
		$this->assertFalse( get_option( 'wp_mcp_ai_school_test_type_delete' ) );
	}
}
