<?php
/**
 * Tests for Settings Dashboard Tab Order
 *
 * Verifies that the General tab is the default/first tab in the settings dashboard.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test settings dashboard tab order and default tab.
 */
class Test_Settings_Dashboard_Tab_Order extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Test that General is the first tab in the tabs array.
	 */
	public function test_general_is_first_tab() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		// Get the first key.
		$first_key = array_key_first( $tabs );

		$this->assertEquals( 'general', $first_key, 'General should be the first tab' );
	}

	/**
	 * Test that General tab is defined with correct properties.
	 */
	public function test_general_tab_properties() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		$this->assertArrayHasKey( 'general', $tabs, 'General tab should be defined' );
		$this->assertArrayHasKey( 'title', $tabs['general'], 'General tab should have a title' );
		$this->assertArrayHasKey( 'icon', $tabs['general'], 'General tab should have an icon' );

		$this->assertEquals( 'General', $tabs['general']['title'], 'General tab title should be "General"' );
		$this->assertEquals( 'dashicons-admin-settings', $tabs['general']['icon'], 'General tab should have settings icon' );
	}

	/**
	 * Test that default active tab is 'general' when no tab parameter is provided.
	 */
	public function test_default_active_tab_is_general() {
		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Use reflection to access the private get_active_tab method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_active_tab' );
		$method->setAccessible( true );

		// Call without setting $_GET['tab'].
		unset( $_GET['tab'] );
		$active_tab = $method->invoke( $dashboard );

		$this->assertEquals( 'general', $active_tab, 'Default active tab should be "general"' );
	}

	/**
	 * Test that invalid tab parameter falls back to 'general'.
	 */
	public function test_invalid_tab_falls_back_to_general() {
		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Use reflection to access the private get_active_tab method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_active_tab' );
		$method->setAccessible( true );

		// Set an invalid tab.
		$_GET['tab'] = 'nonexistent-tab';
		$active_tab  = $method->invoke( $dashboard );

		$this->assertEquals( 'general', $active_tab, 'Invalid tab should fall back to "general"' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that valid tab parameter is respected.
	 */
	public function test_valid_tab_parameter_is_respected() {
		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Use reflection to access the private get_active_tab method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_active_tab' );
		$method->setAccessible( true );

		// Test with Overview tab.
		$_GET['tab'] = 'overview';
		$active_tab  = $method->invoke( $dashboard );
		$this->assertEquals( 'overview', $active_tab, 'Should respect valid "overview" tab parameter' );

		// Test with Providers tab.
		$_GET['tab'] = 'providers';
		$active_tab  = $method->invoke( $dashboard );
		$this->assertEquals( 'providers', $active_tab, 'Should respect valid "providers" tab parameter' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that all expected tabs are present in the correct order.
	 *
	 * The nine base tabs must lead, with General first. Addon-owned tabs
	 * (the four commerce toolkit settings tabs from Proposal 089 Phase 2b)
	 * are appended after `advanced` by the Pro module registry when
	 * WooCommerce is active, so they are asserted separately.
	 */
	public function test_tabs_are_in_expected_order() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		$base_expected_order = array(
			'general',
			'overview',
			'providers',
			'authentication',
			'tools',
			'orchestration',
			'token_manager',
			'security',
			'advanced',
		);

		$actual_order = array_keys( $tabs );

		$base_actual = array_slice( $actual_order, 0, count( $base_expected_order ) );
		$this->assertEquals(
			$base_expected_order,
			$base_actual,
			'Base tabs should lead in the expected order with General first'
		);

		// The commerce toolkit tabs register only when WooCommerce is
		// active (mirrors the gate in the Pro module registry), and form
		// the final block of the tab list in registration order.
		$commerce_expected_order = array(
			'ecommerce',
			'ezuite',
			'shopify_sync',
			'flowhub',
		);

		$commerce_position = array_search( 'ecommerce', $actual_order, true );
		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertNotFalse( $commerce_position, 'E-Commerce tab should be registered when WooCommerce is active' );
			$commerce_actual = array_slice( $actual_order, (int) $commerce_position );
			$this->assertEquals(
				$commerce_expected_order,
				$commerce_actual,
				'Commerce toolkit tabs should be appended after the base tabs in registration order'
			);
		} else {
			$this->assertFalse( $commerce_position, 'Commerce toolkit tabs should only be registered when WooCommerce is active' );
		}
	}

	/**
	 * Test that the three sync toolkit tabs declare E-Commerce as their
	 * parent (subtab_of) so the dashboard renders them as subtabs instead
	 * of top-level nav tabs, while E-Commerce itself stays a top-level tab.
	 */
	public function test_commerce_child_tabs_declare_subtab_of() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		if ( ! isset( $tabs['ecommerce'] ) ) {
			$this->markTestSkipped( 'Commerce tabs are not registered (WooCommerce inactive).' );
		}

		$this->assertArrayNotHasKey( 'subtab_of', $tabs['ecommerce'], 'E-Commerce should remain a top-level tab' );

		foreach ( array( 'ezuite', 'shopify_sync', 'flowhub' ) as $child_tab ) {
			$this->assertArrayHasKey( $child_tab, $tabs, 'Child commerce tab should stay registered: ' . $child_tab );
			$this->assertSame(
				'ecommerce',
				isset( $tabs[ $child_tab ]['subtab_of'] ) ? $tabs[ $child_tab ]['subtab_of'] : null,
				'Child commerce tab should declare E-Commerce as its parent: ' . $child_tab
			);
		}
	}

	/**
	 * Test that legacy ?tab=<child-toolkit> URLs resolve to the parent
	 * E-Commerce tab (the sections map the legacy tab to their subtab).
	 */
	public function test_child_tab_param_resolves_to_parent() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		if ( ! isset( $tabs['ezuite'] ) || empty( $tabs['ezuite']['subtab_of'] ) ) {
			$this->markTestSkipped( 'Commerce child tabs are not registered (WooCommerce inactive).' );
		}

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_active_tab' );
		$method->setAccessible( true );

		$_GET['tab'] = 'ezuite';
		$active_tab  = $method->invoke( $dashboard );
		$this->assertEquals( 'ecommerce', $active_tab, 'A child tab parameter should resolve to its parent tab' );

		// Clean up.
		unset( $_GET['tab'] );
	}
}
