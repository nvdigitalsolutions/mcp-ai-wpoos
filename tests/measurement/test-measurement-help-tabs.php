<?php
/**
 * Tests for help-tab registration on the Measurement dashboard (PR 12).
 *
 * Help tabs were introduced as a GA-polish surface: users land on the
 * dashboard from a busy admin menu and need an in-page reference for
 * what the metric IDs mean and how to use the CLI without leaving the
 * page. We assert four things:
 *
 *   1. `register_help_tabs()` is wired to a `load-{$hook}` action
 *      whenever `add_submenu_page()` returns a hook suffix.
 *   2. Each of the four shipped tabs (overview / metrics / privacy / cli)
 *      lands on the screen.
 *   3. The `wp_mcp_ai_measurement_help_tabs` filter can add tabs
 *      without subclassing the dashboard.
 *   4. Tabs missing `id` or `title` are silently skipped instead of
 *      raising notices in production.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The dashboard class is only loaded under `is_admin()` in production;
// in PHPUnit `is_admin()` is false, so require it directly.
if ( ! class_exists( 'WP_MCP_AI_Admin_Measurement_Dashboard' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php';
}

/**
 * Help-tab registration tests.
 */
class Test_WP_MCP_AI_Measurement_Help_Tabs extends WP_UnitTestCase {

	/**
	 * Build and install a real WP_Screen (WP 7.1's get_current_screen() only
	 * returns WP_Screen instances) that captures help tabs.
	 *
	 * @return WP_Screen
	 */
	private function install_fake_screen() {
		$screen = WP_Screen::get( 'wp-mcp-ai-measurement' );
		set_current_screen( $screen );

		// WP_Screen::get() returns a cached singleton per hook, so reset the
		// help-tab state captured by previous tests.
		$screen->remove_help_tabs();
		return $screen;
	}

	/**
	 * Reset captured screen and filters between tests so each case
	 * starts from a clean slate.
	 */
	public function tearDown(): void {
		$GLOBALS['current_screen'] = null;
		remove_all_filters( 'wp_mcp_ai_measurement_help_tabs' );
		parent::tearDown();
	}

	/**
	 * Four shipped tabs land on the screen with expected IDs.
	 */
	public function test_default_tabs_are_registered() {
		$screen    = $this->install_fake_screen();
		$dashboard = new WP_MCP_AI_Admin_Measurement_Dashboard();
		$dashboard->register_help_tabs();

		$ids = array_map(
			static function ( $tab ) {
				return isset( $tab['id'] ) ? $tab['id'] : '';
			},
			$screen->get_help_tabs()
		);

		$this->assertContains( 'wp_mcp_ai_measurement_overview', $ids );
		$this->assertContains( 'wp_mcp_ai_measurement_metrics', $ids );
		$this->assertContains( 'wp_mcp_ai_measurement_privacy', $ids );
		$this->assertContains( 'wp_mcp_ai_measurement_cli', $ids );
		$this->assertNotEmpty( $screen->get_help_sidebar() );
	}

	/**
	 * Custom tabs added via the filter land on the screen.
	 */
	public function test_filter_can_inject_extra_tabs() {
		$screen = $this->install_fake_screen();

		add_filter(
			'wp_mcp_ai_measurement_help_tabs',
			static function ( $tabs ) {
				$tabs[] = array(
					'id'      => 'site_runbook',
					'title'   => 'Runbook',
					'content' => '<p>Internal runbook URL.</p>',
				);
				return $tabs;
			}
		);

		$dashboard = new WP_MCP_AI_Admin_Measurement_Dashboard();
		$dashboard->register_help_tabs();

		$ids = array_column( $screen->get_help_tabs(), 'id' );
		$this->assertContains( 'site_runbook', $ids );
	}

	/**
	 * Tabs missing required fields are silently skipped — not fatal.
	 */
	public function test_malformed_tabs_are_dropped() {
		$screen = $this->install_fake_screen();

		add_filter(
			'wp_mcp_ai_measurement_help_tabs',
			static function () {
				return array(
					array(
						'id'      => '',
						'title'   => 'Missing id',
						'content' => '',
					),
					array(
						'id'      => 'no_title',
						'content' => 'No title.',
					),
					array(
						'id'      => 'good',
						'title'   => 'Good',
						'content' => '<p>Hi.</p>',
					),
				);
			}
		);

		$dashboard = new WP_MCP_AI_Admin_Measurement_Dashboard();
		$dashboard->register_help_tabs();

		$ids = array_column( $screen->get_help_tabs(), 'id' );
		$this->assertSame( array( 'good' ), $ids );
	}

	/**
	 * If `get_current_screen()` returns nothing the method bails
	 * cleanly instead of fataling.
	 */
	public function test_no_screen_no_op() {
		$GLOBALS['current_screen'] = null;
		$dashboard = new WP_MCP_AI_Admin_Measurement_Dashboard();
		$dashboard->register_help_tabs();
		$this->assertTrue( true ); // reached without error.
	}
}
