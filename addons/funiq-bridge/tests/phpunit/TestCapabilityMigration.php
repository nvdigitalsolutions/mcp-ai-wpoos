<?php
/**
 * PHPUnit tests for the manage-capability migration (brand cutover).
 *
 * Pins the one-shot `manage_funiq` -> `manage_astro` grant copy: legacy
 * holders gain the new capability, the legacy capability is removed from
 * every role, and the recorded version makes the migration idempotent.
 *
 * @package FuniqBridge\Tests
 */

use FuniqBridge\Plugin;
use FuniqBridge\Schema;

/**
 * Capability migration tests.
 *
 * @covers \FuniqBridge\Plugin::maybe_migrate_caps
 * @group funiq-bridge
 */
class TestCapabilityMigration extends WP_UnitTestCase {

	/**
	 * Reset migration state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( Schema::OPTION_CAP_VERSION );
	}

	/**
	 * Restore role baseline (bootstrap grants admin + editor the new cap).
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( Schema::OPTION_CAP_VERSION );

		foreach ( array( 'administrator', 'editor' ) as $role_key ) {
			$role = get_role( $role_key );
			if ( $role ) {
				$role->add_cap( Schema::CAP_MANAGE );
				$role->remove_cap( Schema::CAP_MANAGE_LEGACY );
			}
		}

		parent::tearDown();
	}

	/**
	 * Legacy holders gain manage_astro; the legacy cap is removed everywhere;
	 * the migration version is recorded.
	 */
	public function test_migration_copies_legacy_grants_and_removes_legacy_cap(): void {
		$editor = get_role( 'editor' );
		$editor->add_cap( Schema::CAP_MANAGE_LEGACY );
		$editor->remove_cap( Schema::CAP_MANAGE );

		$admin = get_role( 'administrator' );
		$admin->add_cap( Schema::CAP_MANAGE_LEGACY );
		$admin->remove_cap( Schema::CAP_MANAGE );

		Plugin::instance()->maybe_migrate_caps();

		$this->assertTrue( $editor->has_cap( Schema::CAP_MANAGE ) );
		$this->assertTrue( $admin->has_cap( Schema::CAP_MANAGE ) );
		$this->assertFalse( $editor->has_cap( Schema::CAP_MANAGE_LEGACY ) );
		$this->assertFalse( $admin->has_cap( Schema::CAP_MANAGE_LEGACY ) );
		$this->assertSame( '2', get_option( Schema::OPTION_CAP_VERSION ) );
	}

	/**
	 * A recorded version short-circuits the migration (one-shot semantics).
	 */
	public function test_migration_is_idempotent_when_version_recorded(): void {
		update_option( Schema::OPTION_CAP_VERSION, '2', false );

		$editor = get_role( 'editor' );
		$editor->add_cap( Schema::CAP_MANAGE_LEGACY );
		$editor->remove_cap( Schema::CAP_MANAGE );

		Plugin::instance()->maybe_migrate_caps();

		// The legacy grant is NOT copied once the migration is recorded.
		$this->assertFalse( $editor->has_cap( Schema::CAP_MANAGE ) );
		$this->assertTrue( $editor->has_cap( Schema::CAP_MANAGE_LEGACY ) );
	}

	/**
	 * Roles without a legacy grant gain nothing from the migration.
	 */
	public function test_migration_grants_nothing_without_legacy_cap(): void {
		$editor = get_role( 'editor' );
		$editor->remove_cap( Schema::CAP_MANAGE_LEGACY );
		$editor->remove_cap( Schema::CAP_MANAGE );

		Plugin::instance()->maybe_migrate_caps();

		$this->assertFalse( $editor->has_cap( Schema::CAP_MANAGE ) );
		$this->assertSame( '2', get_option( Schema::OPTION_CAP_VERSION ) );
	}
}
