<?php
/**
 * Tenant feature flags port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantFeatureFlags`: the
 * global/settings/constant resolution chain, the per-toolkit opt-in /
 * opt-out resolution, `require_isolation()`, and the enabled-toolkit
 * LIKE scan. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantFeatureFlags;

/**
 * Tenant feature flags characterization.
 */
class Test_Tenant_Feature_Flags extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( TenantFeatureFlags::OPTION_KEY );
		delete_option( 'wp_mcp_ai_settings' );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_tenant_isolation_toolkit_%'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function tearDown(): void {
		delete_option( TenantFeatureFlags::OPTION_KEY );
		delete_option( 'wp_mcp_ai_settings' );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_tenant_isolation_toolkit_%'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		parent::tearDown();
	}

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_tenant_isolation_enabled', TenantFeatureFlags::OPTION_KEY );
		$this->assertSame( 'wp_mcp_ai_tenant_isolation_toolkit_', TenantFeatureFlags::TOOLKIT_OPTION_PREFIX );
	}

	public function test_is_enabled_defaults_false(): void {
		$this->assertFalse( TenantFeatureFlags::is_enabled() );
	}

	public function test_is_enabled_reads_settings_toggle(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_tenant_isolation' => true ) );

		$this->assertTrue( TenantFeatureFlags::is_enabled() );
	}

	public function test_enable_disable_roundtrip(): void {
		TenantFeatureFlags::enable();
		$this->assertTrue( TenantFeatureFlags::is_enabled() );

		TenantFeatureFlags::disable();
		$this->assertFalse( TenantFeatureFlags::is_enabled() );
	}

	public function test_toolkit_resolution_opt_in_when_global_off(): void {
		TenantFeatureFlags::disable();
		$this->assertFalse( TenantFeatureFlags::is_toolkit_enabled( 'crm' ) );

		TenantFeatureFlags::enable_toolkit( 'crm' );
		$this->assertTrue( TenantFeatureFlags::is_toolkit_enabled( 'crm' ) );
	}

	public function test_toolkit_resolution_opt_out_when_global_on(): void {
		TenantFeatureFlags::enable();
		$this->assertTrue( TenantFeatureFlags::is_toolkit_enabled( 'crm' ) );

		TenantFeatureFlags::opt_out_toolkit( 'crm' );
		$this->assertFalse( TenantFeatureFlags::is_toolkit_enabled( 'crm' ) );
	}

	public function test_require_isolation_throws(): void {
		TenantFeatureFlags::disable();

		$this->expectException( \RuntimeException::class );
		TenantFeatureFlags::require_isolation( 'crm' );
	}

	public function test_require_isolation_passes_when_enabled(): void {
		TenantFeatureFlags::enable_toolkit( 'crm' );

		TenantFeatureFlags::require_isolation( 'crm' );
		$this->assertTrue( true );
	}

	public function test_get_enabled_toolkits_scans_and_skips_opt_outs(): void {
		TenantFeatureFlags::enable_toolkit( 'crm' );
		TenantFeatureFlags::enable_toolkit( 'eca-management' );
		TenantFeatureFlags::opt_out_toolkit( 'calendar' );

		$toolkits = TenantFeatureFlags::get_enabled_toolkits();

		$this->assertContains( 'crm', $toolkits );
		$this->assertContains( 'eca-management', $toolkits );
		$this->assertNotContains( 'calendar_opt_out', $toolkits );
	}

	public function test_is_enabled_honors_constant_override(): void {
		if ( ! defined( 'WP_MCP_AI_TENANT_ISOLATION' ) ) {
			define( 'WP_MCP_AI_TENANT_ISOLATION', true );
		}

		$this->assertTrue( TenantFeatureFlags::is_enabled() );
	}
}
