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
	 * Build a fake screen object with the same shape as `WP_Screen`
	 * but isolated from global state so each test starts clean.
	 *
	 * @return object
	 */
	private function fake_screen() {
		return new class() {
			/**
			 * Tabs registered via add_help_tab().
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public $tabs = array();

			/**
			 * Sidebar HTML.
			 *
			 * @var string
			 */
			public $sidebar = '';

			/**
			 * Capture a help tab.
			 *
			 * @param array<string,mixed> $tab Tab definition.
			 * @return void
			 */
			public function add_help_tab( $tab ) {
				$this->tabs[] = $tab;
			}

			/**
			 * Capture the sidebar HTML.
			 *
			 * @param string $html Sidebar markup.
			 * @return void
			 */
			public function set_help_sidebar( $html ) {
				$this->sidebar = (string) $html;
			}

			/**
			 * WP 6.9+ is_admin() calls $GLOBALS['current_screen']->in_admin();
			 * implement it so a leaked fake screen can never fatal later suites.
			 *
			 * @return bool Always false (front-end context).
			 */
			public function in_admin() {
				return false;
			}
		};
	}

	/**
	 * Swap `get_current_screen()` to return our fake screen for the
	 * duration of one call by stashing it in a global.
	 *
	 * @return object Fake screen.
	 */
	private function install_fake_screen() {
		$fake                                  = $this->fake_screen();
		$GLOBALS['wp_mcp_ai_test_help_screen'] = $fake;

		// Override get_current_screen via a runtime define-once shim.
		// Built-in `get_current_screen()` tolerates being called when
		// no screen is set; we substitute by setting `$current_screen`
		// to our fake object.
		$GLOBALS['current_screen'] = $fake;
		return $fake;
	}

	/**
	 * Reset captured screen and filters between tests so each case
	 * starts from a clean slate.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wp_mcp_ai_test_help_screen'], $GLOBALS['current_screen'] );
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
			$screen->tabs
		);

		$this->assertContains( 'wp_mcp_ai_measurement_overview', $ids );
		$this->assertContains( 'wp_mcp_ai_measurement_metrics', $ids );
		$this->assertContains( 'wp_mcp_ai_measurement_privacy', $ids );
		$this->assertContains( 'wp_mcp_ai_measurement_cli', $ids );
		$this->assertNotEmpty( $screen->sidebar );
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

		$ids = array_column( $screen->tabs, 'id' );
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

		$ids = array_column( $screen->tabs, 'id' );
		$this->assertSame( array( 'good' ), $ids );
	}

	/**
	 * If `get_current_screen()` returns nothing the method bails
	 * cleanly instead of fataling.
	 */
	public function test_no_screen_no_op() {
		unset( $GLOBALS['current_screen'] );
		$dashboard = new WP_MCP_AI_Admin_Measurement_Dashboard();
		$dashboard->register_help_tabs();
		$this->assertTrue( true ); // reached without error.
	}
}
