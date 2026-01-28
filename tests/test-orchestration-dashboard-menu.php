<?php
/**
 * Tests for Orchestration Dashboard Menu Registration
 *
 * Verifies that both base and Pro orchestration dashboards are properly
 * registered in the WordPress admin menu.
 *
 * @package WP_MCP_AI
 */

/**
 * Test orchestration dashboard menu registration.
 */
class Test_Orchestration_Dashboard_Menu extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test that base orchestration dashboard menu is registered.
	 */
	public function test_base_orchestration_dashboard_registered() {
		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check if the main NV oOS menu exists.
		$this->assertArrayHasKey(
			'wp-mcp-ai-dashboard',
			$submenu,
			'Main NV oOS menu should be registered'
		);

		// Find the orchestration dashboard in the submenu.
		$orchestration_found = false;
		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( isset( $item[2] ) && 'mcp-ai-orchestration-dashboard' === $item[2] ) {
				$orchestration_found = true;
				$this->assertEquals( 'Orchestration', $item[0], 'Menu title should be "Orchestration"' );
				break;
			}
		}

		$this->assertTrue(
			$orchestration_found,
			'Base orchestration dashboard should be registered in submenu'
		);
	}

	/**
	 * Test that Pro orchestration dashboard menu is registered (if Pro is active).
	 */
	public function test_pro_orchestration_dashboard_registered() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Find the Pro orchestration dashboard in the submenu.
		$pro_orchestration_found = false;
		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( isset( $item[2] ) && 'mcp-ai-orchestration' === $item[2] ) {
				$pro_orchestration_found = true;
				$this->assertStringContainsString(
					'(Pro)',
					$item[0],
					'Pro orchestration menu title should contain "(Pro)"'
				);
				break;
			}
		}

		$this->assertTrue(
			$pro_orchestration_found,
			'Pro orchestration dashboard should be registered in submenu'
		);
	}

	/**
	 * Test that both orchestration pages use different slugs.
	 */
	public function test_orchestration_pages_use_different_slugs() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		$slugs = array();
		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( isset( $item[2] ) && false !== strpos( $item[2], 'orchestration' ) ) {
				$slugs[] = $item[2];
			}
		}

		// Should have exactly 2 orchestration-related pages.
		$this->assertCount(
			2,
			$slugs,
			'Should have exactly 2 orchestration pages (base and Pro)'
		);

		// Slugs should be different.
		$this->assertNotEquals(
			$slugs[0],
			$slugs[1],
			'Base and Pro orchestration pages should use different slugs'
		);
	}

	/**
	 * Test that base orchestration dashboard has proper page title.
	 */
	public function test_base_orchestration_page_title() {
		global $submenu;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
			if ( isset( $item[2] ) && 'mcp-ai-orchestration-dashboard' === $item[2] ) {
				// Page title is in $item[3].
				$this->assertEquals(
					'Orchestration Dashboard',
					$item[3],
					'Base orchestration page title should be "Orchestration Dashboard"'
				);
				break;
			}
		}
	}
}
