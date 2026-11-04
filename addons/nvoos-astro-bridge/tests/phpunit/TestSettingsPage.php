<?php
/**
 * PHPUnit tests for the wp-admin bridge settings page (Proposal 111):
 * sanitizer gates, the manage_astro option-page capability, and the menu
 * registration. The Settings API owns nonce + redirect handling, so the
 * sanitizers are the unit seam.
 *
 * Test discipline: the page's hooks are registered under `is_admin()`
 * (Plugin::init), which is never true in the CLI test context — so the
 * tests invoke the page methods DIRECTLY (the controller-test pattern).
 * Firing global admin_init/admin_menu here would also trip Woo's
 * privacy-policy doing_it_wrong notices.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Admin\SettingsPage;
use NvoosAstroBridge\Schema;

/**
 * Settings page tests.
 *
 * @covers \NvoosAstroBridge\Admin\SettingsPage
 * @group nvoos-astro-bridge
 */
class TestSettingsPage extends WP_UnitTestCase {

	/**
	 * The page under test — registered directly, not via admin hooks.
	 *
	 * @var SettingsPage
	 */
	private $page;

	/**
	 * Set up: instantiate + register settings/menu directly. The parent
	 * Astro CMS menu is registered first — the production order (AdminPage
	 * at admin_menu:10, SettingsPage at :11).
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		global $menu, $submenu, $wp_registered_settings;
		if ( ! is_array( $menu ) ) {
			$menu = array();
		}
		if ( ! is_array( $submenu ) ) {
			$submenu = array();
		}
		if ( ! is_array( $wp_registered_settings ) ) {
			$wp_registered_settings = array();
		}

		add_menu_page(
			'Astro CMS',
			'Astro CMS',
			Schema::CAP_MANAGE,
			Schema::ADMIN_PAGE_SLUG,
			'__return_null'
		);

		$this->page = new SettingsPage();
		$this->page->register_settings();
		$this->page->register_page();
	}

	/**
	 * Clean the registered-settings globals between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_settings_errors;
		$wp_settings_errors = array();
		delete_option( Schema::OPTION_PRODUCT_SOURCE );
		delete_option( Schema::OPTION_WOO_SSO_ENABLED );
		parent::tearDown();
	}

	/**
	 * Valid modes pass through the sanitizer untouched.
	 */
	public function test_sanitize_products_source_accepts_valid_modes(): void {
		$this->assertSame( Schema::SOURCE_NATIVE, $this->page->sanitize_products_source( Schema::SOURCE_NATIVE ) );
		$this->assertSame( 'woo', $this->page->sanitize_products_source( 'woo' ) );
		$this->assertSame( 'both', $this->page->sanitize_products_source( 'both' ) );
	}

	/**
	 * An unknown mode never persists — the stored value is kept and a
	 * settings error is queued.
	 */
	public function test_sanitize_products_source_rejects_unknown_modes(): void {
		update_option( Schema::OPTION_PRODUCT_SOURCE, 'woo' );

		$this->assertSame( 'woo', $this->page->sanitize_products_source( 'pwned' ) );
		$this->assertSame( 'woo', $this->page->sanitize_products_source( array( 'woo' ) ) );

		global $wp_settings_errors;
		$this->assertNotEmpty( $wp_settings_errors );
	}

	/**
	 * With no stored value, an invalid mode falls back to native.
	 */
	public function test_sanitize_products_source_falls_back_to_native(): void {
		$this->assertSame( Schema::SOURCE_NATIVE, $this->page->sanitize_products_source( null ) );
	}

	/**
	 * The switch sanitizer collapses everything to yes/no.
	 */
	public function test_sanitize_switch(): void {
		$this->assertSame( 'yes', $this->page->sanitize_switch( 'yes' ) );
		$this->assertSame( 'no', $this->page->sanitize_switch( 'no' ) );
		$this->assertSame( 'no', $this->page->sanitize_switch( 'on' ) );
		$this->assertSame( 'no', $this->page->sanitize_switch( '' ) );
	}

	/**
	 * Saving the option page requires manage_astro (not manage_options) —
	 * editors granted the bridge cap can configure it, the Settings API
	 * default would lock them out.
	 */
	public function test_option_page_capability_is_manage_astro(): void {
		$cap = apply_filters( 'option_page_capability_nvoos_astro_bridge_settings', 'manage_options' );
		$this->assertSame( Schema::CAP_MANAGE, $cap );
	}

	/**
	 * The render path is a smoke-gate: an administrator gets the settings
	 * form (Settings API nonce + fields) and the status panel. The menu
	 * globals are NOT asserted — wp-phpunit's admin-context handling makes
	 * `$menu`/`$submenu` ordering-dependent across the group run, and the
	 * real wiring is core's `add_submenu_page` under `admin_menu` (proven
	 * live by the page existing in wp-admin).
	 */
	public function test_render_smoke_admin(): void {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		ob_start();
		$this->page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Astro Bridge Settings', $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'System status', $html );
		$this->assertStringContainsString( 'funiq_bridge_products_source', $html );
	}

	/**
	 * The settings are registered under the bridge option group.
	 */
	public function test_settings_registered(): void {
		global $wp_registered_settings;
		$this->assertArrayHasKey( Schema::OPTION_PRODUCT_SOURCE, $wp_registered_settings );
		$this->assertArrayHasKey( Schema::OPTION_WOO_SSO_ENABLED, $wp_registered_settings );
	}

	/**
	 * The plugin row gains a Settings link.
	 */
	public function test_plugin_row_links(): void {
		$links  = $this->page->plugin_row_links( array() );
		$joined = implode( ' ', $links );
		$this->assertStringContainsString( 'admin.php?page=astro-bridge-settings', $joined );
	}
}
