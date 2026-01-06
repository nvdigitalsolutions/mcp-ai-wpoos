<?php
/**
 * Test Pro Dashboard Menu Order
 *
 * Verifies that the Pro Dashboard Overview page appears as the first
 * submenu item, ensuring proper default page behavior.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Dashboard menu ordering.
 */
class Test_Pro_Dashboard_Menu_Order extends WP_UnitTestCase {

	/**
	 * Pro Dashboard instance.
	 *
	 * @var WP_MCP_AI_Pro_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';
		}

		// Get singleton instance.
		$this->dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		// Set up admin user for menu registration.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Test that Overview submenu is registered with parent slug.
	 */
	public function test_overview_uses_parent_slug() {
		global $submenu;

		// Clear any existing submenus.
		$submenu = array();

		// Trigger menu registration.
		do_action( 'admin_menu' );

		// Check if Pro Dashboard submenu exists.
		$page_slug = 'nvoos-pro-dashboard';
		$this->assertArrayHasKey(
			$page_slug,
			$submenu,
			'Pro Dashboard submenu should be registered'
		);

		// Check if Overview page (with same slug as parent) exists in submenu.
		$has_overview = false;
		foreach ( $submenu[ $page_slug ] as $item ) {
			if ( isset( $item[2] ) && $item[2] === $page_slug ) {
				$has_overview = true;
				break;
			}
		}

		$this->assertTrue(
			$has_overview,
			'Overview page should be registered with parent slug'
		);
	}

	/**
	 * Test that Overview appears as first submenu item.
	 */
	public function test_overview_is_first_submenu_item() {
		global $submenu;

		// Clear any existing submenus.
		$submenu = array();

		// Trigger menu registration.
		do_action( 'admin_menu' );

		$page_slug = 'nvoos-pro-dashboard';

		// Skip test if Pro Dashboard menu not registered (Pro not active).
		if ( ! isset( $submenu[ $page_slug ] ) ) {
			$this->markTestSkipped( 'Pro Dashboard menu not registered' );
			return;
		}

		// Get the submenu array.
		$pro_submenu = $submenu[ $page_slug ];

		$this->assertNotEmpty( $pro_submenu, 'Pro Dashboard should have submenu items' );

		// Get the first submenu item.
		$first_item = reset( $pro_submenu );

		$this->assertIsArray( $first_item, 'First submenu item should be an array' );
		$this->assertArrayHasKey( 2, $first_item, 'First submenu item should have slug at index 2' );

		// Verify the first item is the Overview page (has same slug as parent).
		$this->assertEquals(
			$page_slug,
			$first_item[2],
			'First submenu item should be the Overview page'
		);
	}

	/**
	 * Test reorder function handles empty submenu gracefully.
	 */
	public function test_reorder_handles_empty_submenu() {
		global $submenu;

		// Set up empty submenu.
		$submenu = array();

		// Should not throw error when called with no submenu.
		try {
			$this->dashboard->reorder_pro_dashboard_menu();
			$this->assertTrue( true, 'Reorder should handle empty submenu gracefully' );
		} catch ( Exception $e ) {
			$this->fail( 'Reorder should not throw exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Test reorder function moves Overview to first position.
	 */
	public function test_reorder_moves_overview_to_first() {
		global $submenu;

		$page_slug = 'nvoos-pro-dashboard';

		// Simulate a submenu where Overview is not first.
		$submenu[ $page_slug ] = array(
			array( 'Some Other Page', 'manage_options', 'other-page' ),
			array( 'ISO 27001', 'manage_options', $page_slug . '-iso27001' ),
			array( 'Overview', 'manage_options', $page_slug ), // Overview is third.
		);

		// Call reorder function.
		$this->dashboard->reorder_pro_dashboard_menu();

		// Verify Overview is now first.
		$first_item = reset( $submenu[ $page_slug ] );
		$this->assertEquals(
			$page_slug,
			$first_item[2],
			'Overview should be moved to first position'
		);
	}

	/**
	 * Test reorder function does nothing when Overview is already first.
	 */
	public function test_reorder_preserves_order_when_overview_first() {
		global $submenu;

		$page_slug = 'nvoos-pro-dashboard';

		// Simulate a submenu where Overview is already first.
		$original_submenu = array(
			array( 'Overview', 'manage_options', $page_slug ),
			array( 'ISO 27001', 'manage_options', $page_slug . '-iso27001' ),
			array( 'Reports', 'manage_options', $page_slug . '-reports' ),
		);

		$submenu[ $page_slug ] = $original_submenu;

		// Call reorder function.
		$this->dashboard->reorder_pro_dashboard_menu();

		// Verify order is preserved.
		$this->assertEquals(
			$original_submenu,
			$submenu[ $page_slug ],
			'Submenu order should be preserved when Overview is already first'
		);
	}

	/**
	 * Test reorder function handles malformed submenu items.
	 */
	public function test_reorder_handles_malformed_items() {
		global $submenu;

		$page_slug = 'nvoos-pro-dashboard';

		// Simulate submenu with malformed items (missing slug at index 2).
		$submenu[ $page_slug ] = array(
			array( 'Malformed Item' ), // Missing slug.
			array( 'Overview', 'manage_options', $page_slug ),
		);

		// Should not throw error.
		try {
			$this->dashboard->reorder_pro_dashboard_menu();
			$this->assertTrue( true, 'Reorder should handle malformed items gracefully' );
		} catch ( Exception $e ) {
			$this->fail( 'Reorder should not throw exception: ' . $e->getMessage() );
		}

		// Verify Overview is still moved to first (if found).
		$first_item = reset( $submenu[ $page_slug ] );
		if ( isset( $first_item[2] ) ) {
			$this->assertEquals(
				$page_slug,
				$first_item[2],
				'Overview should be first when found'
			);
		}
	}
}
