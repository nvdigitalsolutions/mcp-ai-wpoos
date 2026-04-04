<?php
/**
 * Test Webhook Status Admin Page.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the Webhook Status admin page.
 */
class Test_Webhook_Status_Page extends WP_UnitTestCase {

	/**
	 * Clean up connections before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Clean up connections after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	/**
	 * Helper: Load the webhook status page class.
	 *
	 * @return bool True if class loaded successfully.
	 */
	private function load_webhook_status_page() {
		if ( class_exists( 'WP_MCP_AI_Pro_Webhook_Status_Page' ) ) {
			return true;
		}

		$file = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php'
			: dirname( __DIR__ ) . '/addons/pro/includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php';

		if ( file_exists( $file ) ) {
			require_once $file;
			return class_exists( 'WP_MCP_AI_Pro_Webhook_Status_Page' );
		}

		return false;
	}

	/**
	 * Helper: Load remote site manager.
	 *
	 * @return bool True if class loaded.
	 */
	private function load_remote_site_manager() {
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return true;
		}

		$file = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php'
			: dirname( __DIR__ ) . '/addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';

		if ( file_exists( $file ) ) {
			require_once $file;
			return class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
		}

		return false;
	}

	// ------------------------------------------------------------------
	// Class existence and structure tests
	// ------------------------------------------------------------------

	/**
	 * Test that the webhook status page class file exists.
	 */
	public function test_class_file_exists() {
		$file = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php'
			: dirname( __DIR__ ) . '/addons/pro/includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php';

		$this->assertFileExists( $file, 'Webhook status page class file should exist' );
	}

	/**
	 * Test that the class can be loaded.
	 */
	public function test_class_loads() {
		$loaded = $this->load_webhook_status_page();
		$this->assertTrue( $loaded, 'WP_MCP_AI_Pro_Webhook_Status_Page class should load' );
	}

	/**
	 * Test that the class has the expected PAGE_SLUG constant.
	 */
	public function test_page_slug_constant() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$this->assertEquals(
			'nvoos-pro-webhook-status',
			WP_MCP_AI_Pro_Webhook_Status_Page::PAGE_SLUG,
			'PAGE_SLUG should be nvoos-pro-webhook-status'
		);
	}

	/**
	 * Test that the class has the expected NONCE_ACTION constant.
	 */
	public function test_nonce_action_constant() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$this->assertEquals(
			'wp_mcp_ai_webhook_status',
			WP_MCP_AI_Pro_Webhook_Status_Page::NONCE_ACTION,
			'NONCE_ACTION should be wp_mcp_ai_webhook_status'
		);
	}

	/**
	 * Test that the class has the required methods.
	 */
	public function test_class_has_required_methods() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$methods = array(
			'register_page',
			'enqueue_assets',
			'render_page',
			'get_webhook_connections',
			'get_expected_webhook_url',
			'get_type_label',
			'get_type_color',
			'check_telegram_webhook',
			'check_generic_webhook',
			'ajax_check_webhook_status',
			'ajax_check_all_webhooks',
			'ajax_set_webhook',
			'ajax_delete_webhook',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WP_MCP_AI_Pro_Webhook_Status_Page', $method ),
				"WP_MCP_AI_Pro_Webhook_Status_Page should have method: $method"
			);
		}
	}

	// ------------------------------------------------------------------
	// get_webhook_connections tests
	// ------------------------------------------------------------------

	/**
	 * Test that get_webhook_connections returns empty when no connections exist.
	 */
	public function test_get_webhook_connections_empty() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$connections = WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections();
		$this->assertIsArray( $connections );
		$this->assertEmpty( $connections );
	}

	/**
	 * Test that get_webhook_connections filters only webhook-capable types.
	 */
	public function test_get_webhook_connections_filters_types() {
		if ( ! $this->load_webhook_status_page() || ! $this->load_remote_site_manager() ) {
			$this->markTestSkipped( 'Required classes not available' );
			return;
		}

		// Save a Telegram connection.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Test Telegram Bot',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz_testtoken',
			)
		);

		// Save a non-webhook connection (WordPress).
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Test WP Site',
				'url'             => 'https://example.com',
				'connection_type' => 'wordpress',
				'auth_type'       => 'bearer',
				'enabled'         => true,
			)
		);

		$connections = WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections();

		$this->assertCount( 1, $connections, 'Only webhook-capable connections should be returned' );

		$first = reset( $connections );
		$this->assertEquals( 'telegram', $first['connection_type'] );
		$this->assertEquals( 'Test Telegram Bot', $first['name'] );
	}

	/**
	 * Test that get_webhook_connections returns multiple Telegram bots.
	 */
	public function test_get_webhook_connections_multiple_telegram() {
		if ( ! $this->load_webhook_status_page() || ! $this->load_remote_site_manager() ) {
			$this->markTestSkipped( 'Required classes not available' );
			return;
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Bot A',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '1111111111:BotATokenABCDEFGHIJKLMNOPQRSTUVWX',
			)
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Bot B',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '2222222222:BotBTokenABCDEFGHIJKLMNOPQRSTUVWX',
			)
		);

		$connections = WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections();

		$this->assertCount( 2, $connections, 'Both Telegram bots should be returned' );

		$names = array_map(
			function ( $c ) {
				return $c['name'];
			},
			$connections
		);
		$this->assertContains( 'Bot A', $names );
		$this->assertContains( 'Bot B', $names );
	}

	// ------------------------------------------------------------------
	// get_expected_webhook_url tests
	// ------------------------------------------------------------------

	/**
	 * Test expected webhook URL generation for Telegram.
	 */
	public function test_get_expected_webhook_url_telegram() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'abc123', 'telegram' );
		$this->assertStringContainsString( '/wp-json/mcp-ai/v1/webhooks/telegram/abc123', $url );
	}

	/**
	 * Test expected webhook URL generation for WhatsApp.
	 */
	public function test_get_expected_webhook_url_whatsapp() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'wa-conn', 'whatsapp' );
		$this->assertStringContainsString( '/wp-json/mcp-ai/v1/webhooks/whatsapp/wa-conn', $url );
	}

	/**
	 * Test expected webhook URL generation for Google Chat.
	 */
	public function test_get_expected_webhook_url_google_chat() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'gc1', 'google_chat' );
		$this->assertStringContainsString( '/wp-json/mcp-ai/v1/webhooks/google-chat/gc1', $url );
	}

	/**
	 * Test expected webhook URL generation for Slack.
	 */
	public function test_get_expected_webhook_url_slack() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'slack-ws', 'slack' );
		$this->assertStringContainsString( '/wp-json/mcp-ai/v1/webhooks/slack/slack-ws', $url );
	}

	/**
	 * Test expected webhook URL for unknown type returns empty.
	 */
	public function test_get_expected_webhook_url_unknown_type() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( 'x', 'WordPress' );
		$this->assertEmpty( $url );
	}

	/**
	 * Test expected webhook URL without connection_id uses base URL.
	 */
	public function test_get_expected_webhook_url_no_connection_id() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$url = WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( '', 'telegram' );
		$this->assertStringContainsString( '/wp-json/mcp-ai/v1/webhooks/telegram', $url );
		$this->assertStringNotContainsString( '/webhooks/telegram/', $url );
	}

	// ------------------------------------------------------------------
	// Type helpers tests
	// ------------------------------------------------------------------

	/**
	 * Test get_type_label returns correct labels.
	 */
	public function test_get_type_label() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$this->assertEquals( 'Telegram', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'telegram' ) );
		$this->assertEquals( 'WhatsApp', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'whatsapp' ) );
		$this->assertEquals( 'Google Chat', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'google_chat' ) );
		$this->assertEquals( 'MS Teams', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'microsoft_teams' ) );
	}

	/**
	 * Test get_type_label returns formatted fallback for unknown types.
	 */
	public function test_get_type_label_unknown_type() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$this->assertEquals( 'Some unknown', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_label( 'some_unknown' ) );
	}

	/**
	 * Test get_type_color returns hex colours.
	 */
	public function test_get_type_color() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$this->assertEquals( '#0088cc', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_color( 'telegram' ) );
		$this->assertEquals( '#25d366', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_color( 'whatsapp' ) );
		$this->assertEquals( '#50575e', WP_MCP_AI_Pro_Webhook_Status_Page::get_type_color( 'unknown' ) );
	}

	// ------------------------------------------------------------------
	// check_generic_webhook tests
	// ------------------------------------------------------------------

	/**
	 * Test check_generic_webhook for an enabled connection with credentials.
	 */
	public function test_check_generic_webhook_configured() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$connection = array(
			'id'              => 'wa-test',
			'connection_type' => 'whatsapp',
			'enabled'         => true,
			'api_key'         => 'some-key',
		);

		$result = WP_MCP_AI_Pro_Webhook_Status_Page::check_generic_webhook( $connection );

		$this->assertEquals( 'configured', $result['status'] );
		$this->assertTrue( $result['has_credentials'] );
		$this->assertNotEmpty( $result['webhook_url'] );
	}

	/**
	 * Test check_generic_webhook for a disabled connection.
	 */
	public function test_check_generic_webhook_disabled() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$connection = array(
			'id'              => 'slack-test',
			'connection_type' => 'slack',
			'enabled'         => false,
			'api_key'         => 'some-key',
		);

		$result = WP_MCP_AI_Pro_Webhook_Status_Page::check_generic_webhook( $connection );

		$this->assertEquals( 'disabled', $result['status'] );
	}

	/**
	 * Test check_generic_webhook for a connection without credentials.
	 */
	public function test_check_generic_webhook_no_credentials() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$connection = array(
			'id'              => 'discord-test',
			'connection_type' => 'discord',
			'enabled'         => true,
			'api_key'         => '',
		);

		$result = WP_MCP_AI_Pro_Webhook_Status_Page::check_generic_webhook( $connection );

		$this->assertEquals( 'no_credentials', $result['status'] );
		$this->assertFalse( $result['has_credentials'] );
	}

	/**
	 * Test check_generic_webhook for Google Chat with refresh_token.
	 */
	public function test_check_generic_webhook_google_chat_oauth() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$connection = array(
			'id'              => 'gc-test',
			'connection_type' => 'google_chat',
			'enabled'         => true,
			'api_key'         => '',
			'refresh_token'   => 'some-refresh-token',
		);

		$result = WP_MCP_AI_Pro_Webhook_Status_Page::check_generic_webhook( $connection );

		$this->assertEquals( 'configured', $result['status'] );
		$this->assertTrue( $result['has_credentials'] );
	}

	// ------------------------------------------------------------------
	// check_telegram_webhook tests
	// ------------------------------------------------------------------

	/**
	 * Test check_telegram_webhook with no bot token.
	 */
	public function test_check_telegram_webhook_no_token() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$connection = array(
			'id'              => 'tg-test',
			'connection_type' => 'telegram',
			'enabled'         => true,
			'api_key'         => '',
		);

		$result = WP_MCP_AI_Pro_Webhook_Status_Page::check_telegram_webhook( $connection );

		$this->assertEquals( 'error', $result['status'] );
		$this->assertStringContainsString( 'No bot token', $result['message'] );
	}

	// ------------------------------------------------------------------
	// Menu registration tests
	// ------------------------------------------------------------------

	/**
	 * Test that the webhook status page registers a submenu page.
	 */
	public function test_register_page_creates_submenu() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$submenu = array();

		$page = new WP_MCP_AI_Pro_Webhook_Status_Page();
		$page->register_page();

		$found = false;
		if ( isset( $submenu['nvoos-pro-dashboard'] ) ) {
			foreach ( $submenu['nvoos-pro-dashboard'] as $item ) {
				if ( isset( $item[2] ) && 'nvoos-pro-webhook-status' === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Webhook Status should be registered as a submenu of nvoos-pro-dashboard' );

		wp_set_current_user( 0 );
	}

	// ------------------------------------------------------------------
	// AJAX handler registration tests
	// ------------------------------------------------------------------

	/**
	 * Test that the AJAX actions are registered when class is instantiated.
	 */
	public function test_ajax_actions_registered() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$page = new WP_MCP_AI_Pro_Webhook_Status_Page();

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_webhook_status_check', array( $page, 'ajax_check_webhook_status' ) ),
			'wp_ajax_wp_mcp_ai_webhook_status_check should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_webhook_status_check_all', array( $page, 'ajax_check_all_webhooks' ) ),
			'wp_ajax_wp_mcp_ai_webhook_status_check_all should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_webhook_status_set', array( $page, 'ajax_set_webhook' ) ),
			'wp_ajax_wp_mcp_ai_webhook_status_set should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_webhook_status_delete', array( $page, 'ajax_delete_webhook' ) ),
			'wp_ajax_wp_mcp_ai_webhook_status_delete should be registered'
		);
	}

	// ------------------------------------------------------------------
	// render_page output test
	// ------------------------------------------------------------------

	/**
	 * Test that render_page produces expected HTML.
	 */
	public function test_render_page_output() {
		if ( ! $this->load_webhook_status_page() ) {
			$this->markTestSkipped( 'Webhook status page class not available' );
			return;
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$page = new WP_MCP_AI_Pro_Webhook_Status_Page();

		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Webhook Status', $output );
		$this->assertStringContainsString( 'summary-card', $output );
		$this->assertStringContainsString( 'No webhook-capable connections configured', $output );

		wp_set_current_user( 0 );
	}

	/**
	 * Test that render_page shows connections when they exist.
	 */
	public function test_render_page_with_connections() {
		if ( ! $this->load_webhook_status_page() || ! $this->load_remote_site_manager() ) {
			$this->markTestSkipped( 'Required classes not available' );
			return;
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'My Test Bot',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz_testtoken',
			)
		);

		$page = new WP_MCP_AI_Pro_Webhook_Status_Page();

		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'My Test Bot', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-webhook-table', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-check-btn', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-set-btn', $output );
		$this->assertStringContainsString( 'Check All Webhooks', $output );
		$this->assertStringContainsString( 'webhooks/telegram/', $output );

		wp_set_current_user( 0 );
	}

	// ------------------------------------------------------------------
	// Pro addon loading test
	// ------------------------------------------------------------------

	/**
	 * Test that the main pro file references the webhook status page loader.
	 */
	public function test_pro_addon_loads_webhook_status_page() {
		$pro_file = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'mcp-ai-wpoos-pro.php'
			: dirname( __DIR__ ) . '/addons/pro/mcp-ai-wpoos-pro.php';

		if ( ! file_exists( $pro_file ) ) {
			$this->markTestSkipped( 'Pro addon main file not available' );
			return;
		}

		$contents = file_get_contents( $pro_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString(
			'class-wp-mcp-ai-pro-webhook-status-page.php',
			$contents,
			'Pro addon main file should load the webhook status page class'
		);
	}
}
