<?php
/**
 * JetEngine + Plugins integration screens ported-class tests
 * (Wave E-UI-3, sub-cluster 4 — final).
 *
 * Verifies the extraction ports of the base plugin's
 * `WP_MCP_AI_Admin_JetEngine_Integration` and
 * `WP_MCP_AI_Admin_Plugins_Integration` preserve the public
 * behaviour: the byte-identical page slugs and admin_post action
 * names, the per-mode menu registration under the NV Platform menu
 * (standalone), register idempotence, the per-mode settings-store and
 * MCP-server seams, the render surface (active/inactive branches per
 * the third-party probes, checkbox forms, tool tables, MCP section,
 * four-section plugins form with warning notices), the save handlers
 * (capability/nonce gates + option writes + redirect envelopes, incl.
 * the terminate filter), and the silent non-manager render. Runs in
 * both matrices with probe-conditional assertions.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Integrations\JetEngineIntegration;
use NvoosContentGraphAiPlatform\Admin\Integrations\PluginsIntegration;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixtures share this file with their test cases.

/**
 * Test-only exposer for the JetEngine integration page.
 */
class JetEngineIntegrationExposer extends JetEngineIntegration {

	public static function exposed_settings_option_name(): string {
		return self::settings_option_name();
	}

	public static function exposed_has_mcp_server(): bool {
		return self::has_mcp_server();
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
 * Test-only exposer for the Plugins integration page.
 */
class PluginsIntegrationExposer extends PluginsIntegration {

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
 * JetEngine + Plugins screens characterisation suite
 * (Wave E-UI-3, sub-cluster 4).
 */
#[\PHPUnit\Framework\Attributes\Group( 'integrations' )]
class Test_JetEngine_Plugins_Pages extends \WP_UnitTestCase {

	/**
	 * Settings option snapshot for exact restoration.
	 *
	 * @var array
	 */
	private $settings_snapshot = array();

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['updated'], $_POST['enable_jetengine_cct'], $_POST['enable_jetengine_tools'], $_POST['jetengine_mcp_enabled'], $_POST['jetengine_mcp_context_injection'], $_POST['jetengine_mcp_cache_ttl'], $_POST['enable_woocommerce_tools'], $_POST['enable_elementor_widgets'], $_POST['enable_newsletter_tools'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// The integration flags live inside the shared plugin settings
		// option — snapshot + strip our keys instead of deleting the blob.
		$this->settings_snapshot = \get_option( 'wp_mcp_ai_settings', array() );
		$settings                = $this->settings_snapshot;
		unset(
			$settings['enable_jetengine_cct'],
			$settings['enable_jetengine_tools'],
			$settings['jetengine_mcp_enabled'],
			$settings['jetengine_mcp_context_injection'],
			$settings['jetengine_mcp_cache_ttl'],
			$settings['enable_woocommerce_tools'],
			$settings['enable_elementor_widgets'],
			$settings['enable_newsletter_tools']
		);
		\update_option( 'wp_mcp_ai_settings', $settings );
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['updated'], $_POST['enable_jetengine_cct'], $_POST['enable_jetengine_tools'], $_POST['jetengine_mcp_enabled'], $_POST['jetengine_mcp_context_injection'], $_POST['jetengine_mcp_cache_ttl'], $_POST['enable_woocommerce_tools'], $_POST['enable_elementor_widgets'], $_POST['enable_newsletter_tools'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// Restore the shared settings option exactly.
		\update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		\remove_all_filters( 'wp_redirect' );
		\remove_all_filters( 'wp_mcp_ai_plugins_integration_redirect_terminate' );

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
		$this->assertSame( 'wp-mcp-ai-jetengine', JetEngineIntegration::PAGE_SLUG );
		$this->assertSame( 'wp-mcp-ai-plugins', PluginsIntegration::PAGE_SLUG );
	}

	public function test_settings_option_name_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( \WP_MCP_AI_Admin_Settings::OPTION_NAME, JetEngineIntegrationExposer::exposed_settings_option_name() );
			$this->assertSame( \WP_MCP_AI_Admin_Settings::OPTION_NAME, PluginsIntegrationExposer::exposed_settings_option_name() );
		} else {
			$this->assertSame( 'wp_mcp_ai_settings', JetEngineIntegrationExposer::exposed_settings_option_name() );
			$this->assertSame( 'wp_mcp_ai_settings', PluginsIntegrationExposer::exposed_settings_option_name() );
		}
	}

	public function test_mcp_server_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame(
				\class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ? (bool) \WP_MCP_AI_JetEngine_Compat::has_mcp_server() : false,
				JetEngineIntegrationExposer::exposed_has_mcp_server()
			);
		} else {
			$this->assertFalse( JetEngineIntegrationExposer::exposed_has_mcp_server() );
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
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-jetengine-integration.php';
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-plugins-integration.php';

			( new \WP_MCP_AI_Admin_JetEngine_Integration() )->register_page();
			( new \WP_MCP_AI_Admin_Plugins_Integration() )->register_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'wp-mcp-ai-jetengine', $slugs );
			$this->assertContains( 'wp-mcp-ai-plugins', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the pages register under the NV Platform menu.
			$jetengine = new JetEngineIntegration();
			$jetengine->register();
			$jetengine->register_page();

			$plugins = new PluginsIntegration();
			$plugins->register();
			$plugins->register_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'wp-mcp-ai-jetengine', $slugs );
			$this->assertContains( 'wp-mcp-ai-plugins', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$jetengine = new JetEngineIntegration();
		$jetengine->register();
		$jetengine->register();

		$plugins = new PluginsIntegration();
		$plugins->register();
		$plugins->register();

		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_save_jetengine_settings' ) );
		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_save_plugins_settings' ) );
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_jetengine_render_surface(): void {
		$this->admin_user();
		$output = ( new JetEngineIntegrationExposer() )->exposed_render();

		$this->assertStringContainsString( 'JetEngine Integration', $output );

		if ( \class_exists( 'Jet_Engine' ) ) {
			$this->assertStringContainsString( 'JetEngine Active', $output );
			$this->assertStringContainsString( 'Enable CCT Storage', $output );
			$this->assertStringContainsString( 'Enable AI Tools', $output );
			$this->assertStringContainsString( 'wp_mcp_ai_save_jetengine_settings', $output );
			$this->assertStringContainsString( 'Available JetEngine Tools', $output );
			$this->assertStringContainsString( 'jetengine_create_post_type', $output );
			$this->assertStringContainsString( 'jetengine_create_taxonomy', $output );
			$this->assertStringContainsString( 'jetengine_query_cct', $output );
			$this->assertStringContainsString( 'jetengine_create_cct_item', $output );
			$this->assertStringContainsString( 'jetengine_update_cct_item', $output );
			$this->assertStringContainsString( 'MCP Server Integration', $output );
			$this->assertStringContainsString( 'Enable MCP Integration', $output );
			$this->assertStringContainsString( 'AI Context Injection', $output );
			$this->assertStringContainsString( 'Cache TTL (seconds)', $output );
			$this->assertStringContainsString( 'Integration Documentation', $output );
			$this->assertStringContainsString( 'Save JetEngine Settings', $output );
		} else {
			$this->assertStringContainsString( 'JetEngine Not Active', $output );
			$this->assertStringContainsString( 'To enable JetEngine integration:', $output );
			$this->assertStringNotContainsString( 'Available JetEngine Tools', $output );
		}
	}

	public function test_jetengine_render_silently_skips_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->assertSame( '', ( new JetEngineIntegrationExposer() )->exposed_render() );
	}

	public function test_plugins_render_surface(): void {
		$this->admin_user();
		$output = ( new PluginsIntegrationExposer() )->exposed_render();

		$this->assertStringContainsString( 'Plugins Integration', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_save_plugins_settings', $output );
		$this->assertStringContainsString( 'Enable JetEngine CCT', $output );
		$this->assertStringContainsString( 'Enable JetEngine Tools', $output );
		$this->assertStringContainsString( 'Enable WooCommerce Tools', $output );
		$this->assertStringContainsString( 'Enable Elementor Widgets', $output );
		$this->assertStringContainsString( 'Enable Newsletter Tools', $output );
		$this->assertStringContainsString( 'Save Settings', $output );

		// The per-plugin warning notices follow the third-party probes.
		if ( ! \class_exists( 'Jet_Engine' ) ) {
			$this->assertStringContainsString( 'JetEngine plugin is not active.', $output );
		}
		if ( ! \class_exists( 'WooCommerce' ) ) {
			$this->assertStringContainsString( 'WooCommerce plugin is not active.', $output );
		}
		if ( ! \did_action( 'elementor/loaded' ) ) {
			$this->assertStringContainsString( 'Elementor plugin is not active.', $output );
		}
		if ( ! \class_exists( 'Newsletter' ) && ! \class_exists( 'NewsletterSubscription' ) ) {
			$this->assertStringContainsString( 'Newsletter plugin is not active.', $output );
		}
	}

	// ─── Save handlers ──────────────────────────────────────────

	public function test_jetengine_save_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new JetEngineIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'You do not have permission to access this page.', $message );
	}

	public function test_jetengine_save_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_REQUEST['_wpnonce'] = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new JetEngineIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_jetengine_save_flow(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['enable_jetengine_cct']            = '1';
		$_POST['enable_jetengine_tools']          = '1';
		$_POST['jetengine_mcp_enabled']           = '1';
		$_POST['jetengine_mcp_context_injection'] = '1';
		$_POST['jetengine_mcp_cache_ttl']         = '600';
		$_POST['_wpnonce']                        = \wp_create_nonce( 'wp_mcp_ai_save_jetengine_settings' );
		$_REQUEST['_wpnonce']                     = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new JetEngineIntegration() )->handle_save_settings();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-jetengine', $redirected );
		$this->assertStringContainsString( 'updated=true', $redirected );

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $settings['enable_jetengine_cct'] );
		$this->assertTrue( $settings['enable_jetengine_tools'] );
		$this->assertTrue( $settings['jetengine_mcp_enabled'] );
		$this->assertTrue( $settings['jetengine_mcp_context_injection'] );
		$this->assertSame( 600, $settings['jetengine_mcp_cache_ttl'] );

		// Unchecked → false; missing TTL → the 300 default.
		unset( $_POST['enable_jetengine_cct'], $_POST['jetengine_mcp_cache_ttl'] );

		$this->capture_redirect(
			static function (): void {
				( new JetEngineIntegration() )->handle_save_settings();
			}
		);

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( $settings['enable_jetengine_cct'] );
		$this->assertSame( 300, $settings['jetengine_mcp_cache_ttl'] );
	}

	public function test_plugins_save_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new PluginsIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'You do not have permission to access this page.', $message );
	}

	public function test_plugins_save_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_REQUEST['_wpnonce'] = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new PluginsIntegration() )->handle_save_settings();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_plugins_save_flow(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['enable_jetengine_cct']     = '1';
		$_POST['enable_jetengine_tools']   = '1';
		$_POST['enable_woocommerce_tools'] = '1';
		$_POST['enable_elementor_widgets'] = '1';
		$_POST['enable_newsletter_tools']  = '1';
		$_POST['_wpnonce']                 = \wp_create_nonce( 'wp_mcp_ai_save_plugins_settings' );
		$_REQUEST['_wpnonce']              = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new PluginsIntegration() )->handle_save_settings();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-plugins', $redirected );
		$this->assertStringContainsString( 'updated=true', $redirected );

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $settings['enable_jetengine_cct'] );
		$this->assertTrue( $settings['enable_jetengine_tools'] );
		$this->assertTrue( $settings['enable_woocommerce_tools'] );
		$this->assertTrue( $settings['enable_elementor_widgets'] );
		$this->assertTrue( $settings['enable_newsletter_tools'] );

		// Unchecked → false.
		unset( $_POST['enable_jetengine_cct'], $_POST['enable_newsletter_tools'] );

		$this->capture_redirect(
			static function (): void {
				( new PluginsIntegration() )->handle_save_settings();
			}
		);

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( $settings['enable_jetengine_cct'] );
		$this->assertFalse( $settings['enable_newsletter_tools'] );
	}

	public function test_plugins_save_respects_terminate_filter(): void {
		$this->admin_user();

		// The byte-identical terminate filter lets suites run the handler
		// without the killing exit — with it false, no exception escapes.
		\add_filter( 'wp_mcp_ai_plugins_integration_redirect_terminate', '__return_false' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['_wpnonce']    = \wp_create_nonce( 'wp_mcp_ai_save_plugins_settings' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				return false;
			}
		);

		( new PluginsIntegration() )->handle_save_settings();

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-plugins', $redirected );
	}
}
