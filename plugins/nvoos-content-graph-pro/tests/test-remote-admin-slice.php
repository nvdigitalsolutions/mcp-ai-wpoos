<?php
/**
 * Characterization tests for the Wave F6 remote-sites admin slice — the
 * remote-sites admin UI, the assistant remote-connections metabox, and the
 * webhook-status page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the registry module and
 *   the per-mode seams.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Remote-sites admin slice tests.
 */
class Test_Remote_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Remote_Sites_Admin'         => 'admin/class-wp-mcp-ai-pro-remote-sites-admin.php',
			'WP_MCP_AI_Pro_Metabox_Remote_Connections' => 'admin/class-wp-mcp-ai-pro-metabox-remote-connections.php',
			'WP_MCP_AI_Pro_Webhook_Status_Page'        => 'admin/class-wp-mcp-ai-pro-webhook-status-page.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The class constants and static contracts must be byte-identical.
	 */
	public function test_class_contracts(): void {
		$this->assertSame( '_wp_mcp_ai_pro_remote_connections', WP_MCP_AI_Pro_Metabox_Remote_Connections::META_KEY );
		$this->assertSame( 'nvoos-pro-webhook-status', WP_MCP_AI_Pro_Webhook_Status_Page::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_webhook_status', WP_MCP_AI_Pro_Webhook_Status_Page::NONCE_ACTION );

		$this->assertSame( '#0088cc', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_color( 'telegram' ) );
		$this->assertSame( 'Slack', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'slack' ) );
		// Unknown types fall back to ucfirst( str_replace( '_', ' ', $type ) ).
		$this->assertSame( 'Not-a-webhook-type', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'not-a-webhook-type' ) );

		$expected_url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'conn_abc', 'telegram' );
		$this->assertStringContainsString( 'webhooks/telegram/conn_abc', $expected_url );
		$this->assertSame( '', WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'conn_abc', 'not-a-webhook-type' ) );

		// The webhook-connection listing degrades gracefully when no
		// connections are configured (external HTTP is not exercised).
		$this->assertIsArray( WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections() );
	}

	/**
	 * The admin UI constructor must register the admin menu and AJAX hooks.
	 */
	public function test_admin_constructor_hooks(): void {
		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$this->assertNotFalse( has_action( 'admin_menu', array( $admin, 'add_admin_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $admin, 'handle_actions' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_test_remote_connection', array( $admin, 'ajax_test_connection' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_test_telegram_live', array( $admin, 'ajax_test_telegram_live' ) ) );
		$this->assertNotFalse( has_filter( 'allowed_redirect_hosts', array( $admin, 'allow_google_oauth_host' ) ) );

		// The metabox and the webhook page also self-register their hooks.
		$metabox = new WP_MCP_AI_Pro_Metabox_Remote_Connections();
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $metabox, 'add_meta_box' ) ) );
		$this->assertNotFalse( has_action( 'save_post_mcp_ai_assistant', array( $metabox, 'save_meta_box' ) ) );

		$webhook = new WP_MCP_AI_Pro_Webhook_Status_Page();
		$this->assertNotFalse( has_action( 'admin_menu', array( $webhook, 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_webhook_status_check', array( $webhook, 'ajax_check_webhook_status' ) ) );
	}

	/**
	 * Standalone only: the per-mode seams must be pinned in the ported admin
	 * file — the base-owned Google OAuth/Calendar requires are
	 * defined-guarded and the not-yet-ported mesh-peer require is
	 * file_exists-guarded (the base file carries no such seams).
	 */
	public function test_per_mode_seams(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base admin file carries no standalone seams.' );
		}

		$file = (string) ( new ReflectionClass( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) )->getFileName();
		$src  = (string) file_get_contents( $file );

		$this->assertStringContainsString( "if ( defined( 'WP_MCP_AI_PATH' ) ) {", $src );
		$this->assertStringContainsString( 'src/class-wp-mcp-ai-pro-mesh-peer-bidirectional-sync.php', $src );
		$this->assertStringContainsString( 'if ( file_exists( $nvoos_content_graph_pro_mesh_peer_sync ) ) {', $src );

		// Every base-owned Google require must sit inside a defined-guard: the
		// five require sites are paired one-to-one with guard openers (three
		// block-syntax view guards, one alt-syntax view guard, one method).
		$requires      = substr_count( $src, 'require_once WP_MCP_AI_PATH' );
		$guard_openers = substr_count( $src, "if ( defined( 'WP_MCP_AI_PATH' ) ) {" )
			+ substr_count( $src, "if ( defined( 'WP_MCP_AI_PATH' ) ) :" );
		$this->assertGreaterThan( 0, $requires );
		$this->assertSame( $requires, $guard_openers );
	}

	/**
	 * Standalone only: the registry must define the `admin_remote_sites`
	 * module (mirrors the base registry module) and its ported files must
	 * exist.
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the admin_remote_sites module.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-module-registry.php';

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertArrayHasKey( 'admin_remote_sites', $registry->get_modules() );

		$module = $registry->get_modules()['admin_remote_sites'];
		$this->assertSame( 'admin', $module['context'] );

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pro-remote-sites-admin.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pro-metabox-remote-connections.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pro-webhook-status-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		// The admin context gate boots the module whenever the matrix runs in
		// an admin context (CLI matrices may or may not).
		if ( is_admin() ) {
			$this->assertTrue( $registry->is_loaded( 'admin_remote_sites' ) );
		}
	}
}
