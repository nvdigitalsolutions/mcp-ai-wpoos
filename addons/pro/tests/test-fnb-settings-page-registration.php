<?php
/**
 * Tests that the Food & Beverage toolkit settings page registers correctly
 * under the NV oOS admin menu, and that the toolkit init gate loads it when
 * `enable_fnb_toolkit` is on.
 *
 * Regression for the "F&B side admin menu not showing up when enabled" bug:
 * the toolkit shipped tools + presets but no admin page, and its init.php was
 * never loaded (the Pro module registry had no fnb module entry).
 *
 * Mirrors test-pro-schedule-toolkit-settings-registration.php.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Class Test_Fnb_Settings_Page_Registration
 */
class Test_Fnb_Settings_Page_Registration extends WP_UnitTestCase {

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
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_fnb_settings' );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Helper: ensure the settings page class is loaded.
	 *
	 * @return string|null Class name when available, null otherwise.
	 */
	protected function load_page_class() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return null;
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-settings-page.php';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		require_once $file;

		return class_exists( 'WP_MCP_AI_Fnb_Settings_Page' )
			? 'WP_MCP_AI_Fnb_Settings_Page'
			: null;
	}

	/**
	 * The settings page is registered as a submenu of the NV oOS Pro menu.
	 *
	 * The first build attached it to the base "NV oOS" menu
	 * (`wp-mcp-ai-dashboard`); the toolkit belongs in the Pro section, so the
	 * parent is `nvoos-pro-dashboard`.
	 */
	public function test_submenu_registered_under_nvoos_pro_menu() {
		global $submenu;

		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		// Register a stub parent menu so the submenu has somewhere to attach.
		$submenu = array();
		add_menu_page( 'NV oOS Pro', 'NV oOS Pro', 'manage_options', 'nvoos-pro-dashboard', '__return_null' );

		call_user_func( array( $class, 'add_menu' ) );

		$this->assertArrayHasKey( 'nvoos-pro-dashboard', $submenu, 'NV oOS Pro submenu group exists' );

		$slugs = wp_list_pluck( $submenu['nvoos-pro-dashboard'], 2 );
		$this->assertContains( 'wp-mcp-ai-fnb', $slugs, 'F&B settings page registered under NV oOS Pro' );

		// Regression: the base NV oOS menu must NOT carry the F&B page.
		$this->assertArrayNotHasKey( 'wp-mcp-ai-dashboard', $submenu, 'F&B settings page must not attach to the base NV oOS menu' );
	}

	/**
	 * The F&B settings option is registered with the Settings API.
	 */
	public function test_settings_are_registered() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		call_user_func( array( $class, 'register_settings' ) );

		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_mcp_ai_fnb_settings', $registered, 'F&B settings option registered' );
	}

	/**
	 * The sanitizer whitelists the adapter and validates the as-of date.
	 */
	public function test_sanitize_settings() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		$ref = new ReflectionMethod( $class, 'sanitize_settings' );
		$ref->setAccessible( true );

		$out = $ref->invoke(
			null,
			array(
				'adapter'                   => 'csv',
				'connection_id'             => 'conn-1',
				'data_folder_id'            => 'folder-data',
				'setup_folder_id'           => 'folder-setup',
				'drafts_folder_id'          => 'folder-drafts',
				'csv_data_dir'              => '/srv/fnb',
				'as_of_date'                => '2026-10-08',
				'demo_mode'                 => '1',
				'enable_fnb_native_storage' => '1',
			)
		);

		$this->assertSame( 'csv', $out['adapter'] );
		$this->assertSame( 'conn-1', $out['connection_id'] );
		$this->assertSame( 'folder-data', $out['data_folder_id'] );
		$this->assertSame( 'folder-setup', $out['setup_folder_id'] );
		$this->assertSame( 'folder-drafts', $out['drafts_folder_id'] );
		$this->assertSame( '/srv/fnb', $out['csv_data_dir'] );
		$this->assertSame( '2026-10-08', $out['as_of_date'] );
		$this->assertTrue( $out['demo_mode'] );
		$this->assertTrue( $out['enable_fnb_native_storage'] );

		// Invalid adapter falls back to drive; invalid date is dropped.
		$out_invalid = $ref->invoke(
			null,
			array(
				'adapter'    => 'ftp',
				'as_of_date' => 'yesterday',
			)
		);
		$this->assertSame( 'drive', $out_invalid['adapter'] );
		$this->assertSame( '', $out_invalid['as_of_date'] );
		$this->assertFalse( $out_invalid['enable_fnb_native_storage'], 'Native storage must reset when the checkbox is omitted.' );
	}

	/**
	 * With the toolkit toggle on, the init gate loads the settings page and
	 * registers the admin menu hook.
	 */
	public function test_init_gate_loads_settings_page_when_enabled() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active.' );
		}

		update_option( 'wp_mcp_ai_settings', array( 'enable_fnb_toolkit' => true ) );

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/init.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Settings_Page' ), 'F&B settings page must load in admin context.' );
		$this->assertNotFalse(
			has_action( 'admin_menu', array( 'WP_MCP_AI_Fnb_Settings_Page', 'add_menu' ) ),
			'F&B admin menu hook must be registered.'
		);

		// Phase 9: the F&B Management top-level menu must load too.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Admin_Menu' ), 'F&B admin menu class must load in admin context.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Dashboard_Page' ), 'F&B dashboard page class must load in admin context.' );
		$this->assertNotFalse(
			has_action( 'admin_menu', array( 'WP_MCP_AI_Fnb_Admin_Menu', 'register_parent_menu' ) ),
			'F&B top-level menu hook must be registered.'
		);
	}
}
