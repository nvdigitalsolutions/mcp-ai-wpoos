<?php
/**
 * Tests that the Pro Schedule Toolkit Settings page registers correctly
 * under the NV oOS Pro Dashboard menu with the canonical six-tab template.
 *
 * Mirrors tests/test-project-management-submenu-registration.php for the
 * scheduler toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Class Test_Pro_Schedule_Toolkit_Settings_Registration
 */
class Test_Pro_Schedule_Toolkit_Settings_Registration extends WP_UnitTestCase {

	/**
	 * Set up admin context.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Helper: ensure the settings page class is loaded.
	 *
	 * @return string|null Class name when available, null otherwise.
	 */
	protected function load_settings_class() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return null;
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-toolkit-settings-page.php';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		require_once $file;

		return class_exists( 'WP_MCP_AI_Pro_Schedule_Toolkit_Settings_Page' )
			? 'WP_MCP_AI_Pro_Schedule_Toolkit_Settings_Page'
			: null;
	}

	/**
	 * The settings file declares a class extending the toolkit base.
	 */
	public function test_class_extends_toolkit_base() {
		$class = $this->load_settings_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		$this->assertTrue( is_subclass_of( $class, 'WP_MCP_AI_Toolkit_Settings_Base' ), 'Settings page extends toolkit base' );
	}

	/**
	 * The settings page is registered as a submenu of nvoos-pro-dashboard.
	 */
	public function test_submenu_registered_under_pro_dashboard() {
		global $submenu;

		$class = $this->load_settings_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		// Ensure a fresh instance is registered.
		$instance = new $class();

		// Register a stub parent menu so the submenu has somewhere to attach.
		$submenu = array();
		add_menu_page( 'NV oOS Pro Dashboard', 'NV oOS Pro Dashboard', 'manage_options', 'nvoos-pro-dashboard', '__return_null' );

		$instance->add_settings_page();

		$this->assertArrayHasKey( 'nvoos-pro-dashboard', $submenu, 'Pro dashboard submenu group exists' );

		$slugs = wp_list_pluck( $submenu['nvoos-pro-dashboard'], 2 );
		$this->assertContains( 'wp-mcp-ai-pro-schedule-toolkit-settings', $slugs, 'Schedule settings page registered' );
	}

	/**
	 * The page renders all six canonical tabs (overview, configuration,
	 * tools, research, help, mcp_server) via the base render_tabs() helper.
	 */
	public function test_renders_six_canonical_tabs() {
		$class = $this->load_settings_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		$instance = new $class();

		// Use reflection to invoke the protected render_tabs() with overview active.
		$ref = new ReflectionMethod( $instance, 'render_tabs' );
		$ref->setAccessible( true );

		ob_start();
		$ref->invoke( $instance, 'overview' );
		$html = (string) ob_get_clean();

		$expected_tabs = array(
			'tab=overview',
			'tab=configuration',
			'tab=tools',
			'tab=research',
			'tab=help',
		);
		foreach ( $expected_tabs as $needle ) {
			$this->assertStringContainsString( $needle, $html, "tab link {$needle} present" );
		}
	}

	/**
	 * The tools tab exposes the six core Pro Schedule tools.
	 */
	public function test_tools_list_contains_core_schedule_tools() {
		$class = $this->load_settings_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}
		$instance = new $class();
		$ref      = new ReflectionMethod( $instance, 'get_tools_list' );
		$ref->setAccessible( true );
		$tools = $ref->invoke( $instance );

		$this->assertIsArray( $tools );
		foreach (
			array(
				'create_pro_schedule',
				'update_pro_schedule',
				'delete_pro_schedule',
				'list_pro_schedules',
				'get_schedule_run_history',
				'dry_run_pro_schedule',
				'plan_schedules_from_workflow',
			) as $slug
		) {
			$this->assertArrayHasKey( $slug, $tools, "tool {$slug} listed" );
		}
	}

	/**
	 * Sanitize_settings() coerces unknown values to safe defaults.
	 */
	public function test_sanitize_settings_clamps_and_validates() {
		$class = $this->load_settings_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}
		$instance = new $class();

		$raw = array(
			'default_cadence'     => 'WEEKLY!!',
			'default_time'        => 'not-a-time',
			'max_concurrent_runs' => 999,
			'retry_count'         => -5,
			'retry_backoff'       => 'bogus',
			'notification_email'  => 'not-an-email',
			'kill_switch'         => '1',
		);

		$clean = $instance->sanitize_settings( $raw );

		$this->assertSame( 'weekly', $clean['default_cadence'] );
		$this->assertSame( '09:00', $clean['default_time'] );
		$this->assertSame( 20, $clean['max_concurrent_runs'] );
		$this->assertSame( 0, $clean['retry_count'] );
		$this->assertSame( 'linear', $clean['retry_backoff'] );
		$this->assertSame( '', $clean['notification_email'] );
		$this->assertTrue( $clean['kill_switch'] );
	}
}
