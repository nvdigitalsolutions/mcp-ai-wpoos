<?php
/**
 * Elementor + WooCommerce integration screens ported-class tests
 * (Wave E-UI-3, sub-cluster 3).
 *
 * Verifies the extraction ports of the base plugin's
 * `WP_MCP_AI_Admin_Elementor_Integration` and
 * `WP_MCP_AI_Admin_WooCommerce_Integration` preserve the public
 * behaviour: the byte-identical page slugs and admin_post action
 * names, the per-mode menu registration under the NV Platform menu
 * (standalone), register idempotence, the per-mode settings-store
 * seam, the active-banner + checkbox-form + tool-table render surface
 * (third-party active in both matrices), the settings save handlers
 * (capability/nonce gates + option writes + redirect envelopes), and
 * the silent non-manager render. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Integrations\ElementorIntegration;
use NvoosContentGraphAiPlatform\Admin\Integrations\WooCommerceIntegration;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixtures share this file with their test cases.

/**
 * Test-only exposer for the Elementor integration page.
 */
class ElementorIntegrationExposer extends ElementorIntegration {

	public static function exposed_settings_option_name(): string {
		return self::settings_option_name();
	}

	public function exposed_render(): string {
		\ob_start();
		try {
			$this->render_page();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}
}

/**
 * Test-only exposer for the WooCommerce integration page.
 */
class WooCommerceIntegrationExposer extends WooCommerceIntegration {

	public static function exposed_settings_option_name(): string {
		return self::settings_option_name();
	}

	public function exposed_render(): string {
		\ob_start();
		try {
			$this->render_page();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}
}

/**
 * Elementor + WooCommerce screens characterisation suite
 * (Wave E-UI-3, sub-cluster 3).
 */
#[\PHPUnit\Framework\Attributes\Group( 'integrations' )]
class Test_Elementor_WooCommerce_Pages extends \WP_UnitTestCase {

	/**
	 * Settings option snapshot for exact restoration.
	 *
	 * @var array
	 */
	private $settings_snapshot = array();

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['updated'], $_POST['enable_elementor_widgets'], $_POST['enable_woocommerce_tools'], $_POST['enable_woo_analytics'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// The integration flags live inside the shared plugin settings
		// option — snapshot + strip our keys instead of deleting the blob.
		$this->settings_snapshot = \get_option( 'wp_mcp_ai_settings', array() );
		$settings                = $this->settings_snapshot;
		unset( $settings['enable_elementor_widgets'], $settings['enable_woocommerce_tools'], $settings['enable_woo_analytics'] );
		\update_option( 'wp_mcp_ai_settings', $settings );
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['updated'], $_POST['enable_elementor_widgets'], $_POST['enable_woocommerce_tools'], $_POST['enable_woo_analytics'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// Restore the shared settings option exactly.
		\update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		\remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Count the registered callbacks on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function count_callbacks( string $hook ): int {
		global $wp_filter;
		return isset( $wp_filter[ $hook ] ) ? \count( $wp_filter[ $hook ]->callbacks ) : 0;
	}

	/**
	 * Invoke a handler and capture the WPDieException message instead of
	 * the echo buffer (the throwing test die-handler echoes nothing).
	 *
	 * @param callable $callback Handler invocation.
	 * @return string The die message, or the echoed output if no die fired.
	 */
	private function capture_die_message( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			\ob_end_clean();
			return $e->getMessage();
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Intercept wp_redirect() and capture the location, rethrowing so the
	 * handler's bare `exit` cannot terminate the process.
	 *
	 * @param callable $handler Handler invocation.
	 * @return string|null The redirect location, or null if none fired.
	 */
	private function capture_redirect( callable $handler ) {
		$redirected = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new \RuntimeException( 'stop' );
			}
		);

		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Expected — the redirect filter short-circuits exit.
		}

		\remove_all_filters( 'wp_redirect' );
		return $redirected;
	}

	// ─── Public surface + seams ─────────────────────────────────

	public function test_page_slugs_byte_identical(): void {
		$this->assertSame( 'wp-mcp-ai-elementor', ElementorIntegration::PAGE_SLUG );
		$this->assertSame( 'wp-mcp-ai-woocommerce', WooCommerceIntegration::PAGE_SLUG );
	}

	public function test_settings_option_name_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( \WP_MCP_AI_Admin_Settings::OPTION_NAME, ElementorIntegrationExposer::exposed_settings_option_name() );
			$this->assertSame( \WP_MCP_AI_Admin_Settings::OPTION_NAME, WooCommerceIntegrationExposer::exposed_settings_option_name() );
		} else {
			$this->assertSame( 'wp_mcp_ai_settings', ElementorIntegrationExposer::exposed_settings_option_name() );
			$this->assertSame( 'wp_mcp_ai_settings', WooCommerceIntegrationExposer::exposed_settings_option_name() );
		}
	}

	// ─── Menu + hook registration ────────────────────────────────

	public function test_menu_registration_resolves_per_install_mode(): void {
		global $submenu;
		$submenu = array();

		$this->admin_user();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base classes own the same pages (the base
			// loader never wires them — require + register directly).
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-elementor-integration.php';
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-woocommerce-integration.php';

			( new \WP_MCP_AI_Admin_Elementor_Integration() )->register_page();
			( new \WP_MCP_AI_Admin_WooCommerce_Integration() )->register_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'wp-mcp-ai-elementor', $slugs );
			$this->assertContains( 'wp-mcp-ai-woocommerce', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the pages register under the NV Platform menu.
			$elementor = new ElementorIntegration();
			$elementor->register();
			$elementor->register_page();

			$woo = new WooCommerceIntegration();
			$woo->register();
			$woo->register_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'wp-mcp-ai-elementor', $slugs );
			$this->assertContains( 'wp-mcp-ai-woocommerce', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$elementor = new ElementorIntegration();
		$elementor->register();
		$elementor->register();

		$woo = new WooCommerceIntegration();
		$woo->register();
		$woo->register();

		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_save_elementor_settings' ) );
		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_save_woocommerce_settings' ) );
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_elementor_render_active_surface(): void {
		$this->admin_user();
		$output = ( new ElementorIntegrationExposer() )->exposed_render();

		$this->assertStringContainsString( 'Elementor Integration', $output );

		// The third-party plugin may or may not be loaded by the test
		// environment — assert the branch that matches the probe.
		if ( \defined( 'ELEMENTOR_VERSION' ) ) {
			$this->assertStringContainsString( 'Elementor Active', $output );
			$this->assertStringContainsString( 'Enable Widgets', $output );
			$this->assertStringContainsString( 'wp_mcp_ai_save_elementor_settings', $output );
			$this->assertStringContainsString( 'Available Elementor Widgets', $output );
			$this->assertStringContainsString( 'NV oOS Chat', $output );
			$this->assertStringContainsString( 'Assistant Selector', $output );
			$this->assertStringContainsString( 'Chat History', $output );
			$this->assertStringContainsString( 'Part of Base Plugin', $output );
			$this->assertStringContainsString( 'Using Widgets in Elementor', $output );

			// Default checkbox state: enabled.
			$this->assertStringContainsString( "checked='checked'", $output );
		} else {
			$this->assertStringContainsString( 'Elementor Not Active', $output );
			$this->assertStringContainsString( 'To enable Elementor integration:', $output );
			$this->assertStringNotContainsString( 'Available Elementor Widgets', $output );
		}
	}

	public function test_elementor_render_respects_saved_flag(): void {
		$this->admin_user();

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_elementor_widgets'] = false;
		\update_option( 'wp_mcp_ai_settings', $settings );

		$output = ( new ElementorIntegrationExposer() )->exposed_render();

		$this->assertStringNotContainsString( "checked='checked'", $output );
	}

	public function test_elementor_render_silently_skips_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->assertSame( '', ( new ElementorIntegrationExposer() )->exposed_render() );
	}

	public function test_woocommerce_render_active_surface(): void {
		$this->admin_user();
		$output = ( new WooCommerceIntegrationExposer() )->exposed_render();

		$this->assertStringContainsString( 'WooCommerce Integration', $output );

		// The third-party plugin may or may not be loaded by the test
		// environment — assert the branch that matches the probe.
		if ( \class_exists( 'WooCommerce' ) ) {
			$this->assertStringContainsString( 'WooCommerce Active', $output );
			$this->assertStringContainsString( 'Enable AI Tools', $output );
			$this->assertStringContainsString( 'Enable Analytics', $output );
			$this->assertStringContainsString( 'wp_mcp_ai_save_woocommerce_settings', $output );
			$this->assertStringContainsString( 'Available WooCommerce Tools', $output );
			$this->assertStringContainsString( 'woo_create_product', $output );
			$this->assertStringContainsString( 'woo_update_product', $output );
			$this->assertStringContainsString( 'woo_query_orders', $output );
			$this->assertStringContainsString( 'woo_get_analytics', $output );
			$this->assertStringContainsString( 'woo_manage_inventory', $output );
			$this->assertStringContainsString( 'Save WooCommerce Settings', $output );

			// Default checkbox states: both enabled.
			$this->assertSame( 2, \substr_count( $output, "checked='checked'" ) );
		} else {
			$this->assertStringContainsString( 'WooCommerce Not Active', $output );
			$this->assertStringContainsString( 'To enable WooCommerce integration:', $output );
			$this->assertStringNotContainsString( 'Available WooCommerce Tools', $output );
		}
	}

	public function test_woocommerce_render_silently_skips_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->assertSame( '', ( new WooCommerceIntegrationExposer() )->exposed_render() );
	}

	// ─── Save handlers ──────────────────────────────────────────

	public function test_elementor_save_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new ElementorIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'You do not have permission to access this page.', $message );
	}

	public function test_elementor_save_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_REQUEST['_wpnonce'] = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new ElementorIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_elementor_save_flow(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['enable_elementor_widgets'] = '1';
		$_POST['_wpnonce']                 = \wp_create_nonce( 'wp_mcp_ai_save_elementor_settings' );
		$_REQUEST['_wpnonce']              = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new ElementorIntegration() )->handle_save_settings();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-elementor', $redirected );
		$this->assertStringContainsString( 'updated=true', $redirected );

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $settings['enable_elementor_widgets'] );

		// Unchecked → false.
		unset( $_POST['enable_elementor_widgets'] );

		$this->capture_redirect(
			static function (): void {
				( new ElementorIntegration() )->handle_save_settings();
			}
		);

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( $settings['enable_elementor_widgets'] );
	}

	public function test_woocommerce_save_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new WooCommerceIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'You do not have permission to access this page.', $message );
	}

	public function test_woocommerce_save_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_REQUEST['_wpnonce'] = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new WooCommerceIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_woocommerce_save_flow(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['enable_woocommerce_tools'] = '1';
		$_POST['enable_woo_analytics']     = '1';
		$_POST['_wpnonce']                 = \wp_create_nonce( 'wp_mcp_ai_save_woocommerce_settings' );
		$_REQUEST['_wpnonce']              = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new WooCommerceIntegration() )->handle_save_settings();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-woocommerce', $redirected );
		$this->assertStringContainsString( 'updated=true', $redirected );

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $settings['enable_woocommerce_tools'] );
		$this->assertTrue( $settings['enable_woo_analytics'] );

		// Only analytics checked → tools false, analytics true.
		unset( $_POST['enable_woocommerce_tools'] );

		$this->capture_redirect(
			static function (): void {
				( new WooCommerceIntegration() )->handle_save_settings();
			}
		);

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( $settings['enable_woocommerce_tools'] );
		$this->assertTrue( $settings['enable_woo_analytics'] );
	}
}
