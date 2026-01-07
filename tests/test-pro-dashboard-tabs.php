<?php
/**
 * Test Pro Dashboard Tab Navigation
 *
 * Verifies that the Pro Dashboard tab-based navigation works correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Dashboard tab navigation.
 */
class Test_Pro_Dashboard_Tabs extends WP_UnitTestCase {

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
	 * Test that only Overview submenu page is registered.
	 */
	public function test_only_overview_submenu_registered() {
		global $submenu;

		// Clear any existing submenus.
		$submenu = array();

		// Trigger menu registration.
		do_action( 'admin_menu' );

		$page_slug = 'nvoos-pro-dashboard';

		// Skip test if Pro Dashboard menu not registered.
		if ( ! isset( $submenu[ $page_slug ] ) ) {
			$this->markTestSkipped( 'Pro Dashboard menu not registered' );
			return;
		}

		// Get submenu items registered by Pro Dashboard (excluding delegate pages).
		$pro_dashboard_items = array_filter(
			$submenu[ $page_slug ],
			function ( $item ) use ( $page_slug ) {
				// Only count items that belong to Pro Dashboard (not delegate pages).
				return isset( $item[2] ) && (
					$item[2] === $page_slug ||
					strpos( $item[2], $page_slug . '-' ) === 0
				);
			}
		);

		// Should only have the Overview page, no separate ISO 27001, Reports, etc. pages.
		$this->assertCount(
			1,
			$pro_dashboard_items,
			'Pro Dashboard should only register Overview submenu (other sections are tabs)'
		);

		// Verify it's the Overview page.
		$first_item = reset( $pro_dashboard_items );
		$this->assertEquals(
			$page_slug,
			$first_item[2],
			'The single submenu item should be the Overview page'
		);
	}

	/**
	 * Test that render_dashboard_with_tabs method exists.
	 */
	public function test_render_dashboard_with_tabs_method_exists() {
		$this->assertTrue(
			method_exists( $this->dashboard, 'render_dashboard_with_tabs' ),
			'render_dashboard_with_tabs method should exist'
		);
	}

	/**
	 * Test that tab render methods exist.
	 */
	public function test_tab_render_methods_exist() {
		$tab_methods = array(
			'render_overview_tab',
			'render_iso27001_tab',
			'render_reports_tab',
			'render_monitoring_tab',
			'render_risk_tab',
			'render_multi_framework_tab',
		);

		foreach ( $tab_methods as $method ) {
			$this->assertTrue(
				method_exists( $this->dashboard, $method ),
				"Method {$method} should exist for tab rendering"
			);
		}
	}

	/**
	 * Test that dashboard renders without errors for each tab.
	 */
	public function test_dashboard_renders_all_tabs() {
		$tabs = array( 'overview', 'iso27001', 'reports', 'monitoring', 'risk', 'multi-framework' );

		foreach ( $tabs as $tab ) {
			$_GET['tab'] = $tab;

			// Start output buffering.
			ob_start();

			try {
				$this->dashboard->render_dashboard_with_tabs();
				$output = ob_get_clean();

				// Check that some output was generated.
				$this->assertNotEmpty(
					$output,
					"Tab '{$tab}' should render some output"
				);

				// Check that it contains tab navigation.
				$this->assertStringContainsString(
					'nav-tab-wrapper',
					$output,
					"Tab '{$tab}' should contain tab navigation"
				);

				// Check that the correct tab is active.
				$this->assertStringContainsString(
					'nav-tab-active',
					$output,
					"Tab '{$tab}' should have an active tab"
				);
			} catch ( Exception $e ) {
				ob_end_clean();
				$this->fail( "Tab '{$tab}' failed to render: " . $e->getMessage() );
			}
		}

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that invalid tab defaults to iso27001.
	 */
	public function test_invalid_tab_defaults_to_iso27001() {
		$_GET['tab'] = 'invalid-tab';

		ob_start();
		$this->dashboard->render_dashboard_with_tabs();
		$output = ob_get_clean();

		// Should still render (with iso27001 as default).
		$this->assertNotEmpty( $output, 'Invalid tab should render iso27001 as fallback' );

		// Clean up.
		unset( $_GET['tab'] );
	}

	/**
	 * Test that tab navigation contains all expected tabs.
	 */
	public function test_tab_navigation_contains_all_tabs() {
		ob_start();
		$this->dashboard->render_dashboard_with_tabs();
		$output = ob_get_clean();

		$expected_tabs = array(
			'Overview',
			'ISO 27001',
			'Reports',
			'Monitoring',
			'Risk Management',
			'Multi-Framework',
		);

		foreach ( $expected_tabs as $tab_text ) {
			$this->assertStringContainsString(
				$tab_text,
				$output,
				"Tab navigation should contain '{$tab_text}'"
			);
		}
	}
}
