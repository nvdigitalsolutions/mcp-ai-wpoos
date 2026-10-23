<?php
/**
 * Test: SA Toolkit Manager catalogue integrity + admin capability grant.
 *
 * Verifies the toolkit list cannot drift from the canonical flag map
 * (every TOOLKIT_FLAGS slug is listed) and that the workspace capability
 * grant to administrators is idempotent.
 *
 * @package Schedule_Anything
 * @since   0.1.2
 */

/**
 * Tests for the toolkit manager catalogue.
 *
 * @group schedule-anything
 */
class Test_SA_Toolkit_Manager extends WP_UnitTestCase {

	/**
	 * Reset per-test state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'sa_tenant_meta' );
	}

	/**
	 * Every slug in TOOLKIT_FLAGS must appear in get_all_statuses() even
	 * when TOOLKIT_ORDER lags behind (the flag map is the source of truth).
	 */
	public function test_get_all_statuses_covers_full_flag_map() {
		$listed  = wp_list_pluck( SA_Toolkit_Manager::get_all_statuses(), 'slug' );
		$missing = array_diff( array_keys( SA_Toolkit_Manager::TOOLKIT_FLAGS ), $listed );

		$this->assertSame( array(), $missing );
	}

	/**
	 * Ordered slugs come first, then any order-list stragglers.
	 */
	public function test_get_all_statuses_orders_by_toolkit_order() {
		$listed = wp_list_pluck( SA_Toolkit_Manager::get_all_statuses(), 'slug' );

		$this->assertSame( 'calendar-booking', $listed[0] );

		// Every TOOLKIT_ORDER slug that exists in the flag map keeps its
		// relative position in the listing.
		$order_positions = array();
		foreach ( SA_Toolkit_Manager::TOOLKIT_ORDER as $i => $slug ) {
			if ( isset( SA_Toolkit_Manager::TOOLKIT_FLAGS[ $slug ] ) ) {
				$order_positions[ $slug ] = $i;
			}
		}

		$last_order_position = -1;
		foreach ( $listed as $slug ) {
			if ( ! isset( $order_positions[ $slug ] ) ) {
				continue;
			}
			$this->assertGreaterThan(
				$last_order_position,
				$order_positions[ $slug ],
				"Toolkit '{$slug}' is out of TOOLKIT_ORDER sequence."
			);
			$last_order_position = $order_positions[ $slug ];
		}
	}

	/**
	 * Granting the workspace capability to administrators is idempotent.
	 */
	public function test_grant_workspace_capability_is_idempotent() {
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );

		// Simulate a fresh state by removing the capability first (the
		// migration only ever ADDS, but the test must start clean).
		if ( $role->has_cap( 'sa_manage_workspace' ) ) {
			$role->remove_cap( 'sa_manage_workspace' );
		}

		SA_Plugin::grant_workspace_capability_to_admins();
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'sa_manage_workspace' ) );

		// Second run must not error or duplicate.
		SA_Plugin::grant_workspace_capability_to_admins();
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'sa_manage_workspace' ) );
	}

	/**
	 * Toggling to the CURRENT state is a successful no-op (update_option
	 * returns false for unchanged values, which must not surface as a
	 * failed toggle).
	 */
	public function test_toggle_to_current_state_is_idempotent() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_crm_toolkit' => true ) );

		$this->assertTrue( SA_Toolkit_Manager::toggle_toolkit( 'crm', true ) );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $settings['enable_crm_toolkit'] );

		// Disabling a missing flag (already effectively off) also succeeds.
		$this->assertTrue( SA_Toolkit_Manager::toggle_toolkit( 'ecommerce', false ) );
	}

	/**
	 * Unknown slugs still fail.
	 */
	public function test_toggle_unknown_slug_fails() {
		$this->assertFalse( SA_Toolkit_Manager::toggle_toolkit( 'not-a-toolkit', true ) );
	}

	/**
	 * Non-admin roles are never granted the workspace capability.
	 */
	public function test_grant_leaves_non_admin_roles_untouched() {
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->remove_cap( 'sa_manage_workspace' );
		}

		SA_Plugin::grant_workspace_capability_to_admins();

		if ( $editor ) {
			$this->assertFalse( $editor->has_cap( 'sa_manage_workspace' ) );
		}
	}
}
