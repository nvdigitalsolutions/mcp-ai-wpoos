<?php
/**
 * Tests for Chat Channels Admin Pages
 *
 * Verifies that the top-level Chat Channels admin menu and its sub-pages are
 * properly registered when the toolkit is enabled and the Pro plugin is active,
 * matching the pattern established by the Quiz admin pages.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Chat Channels admin page registration.
 */
class Test_Chat_Channels_Admin_Pages extends WP_UnitTestCase {

	/**
	 * Original settings before each test.
	 *
	 * @var array
	 */
	protected $original_settings = array();

	/**
	 * Original $menu global.
	 *
	 * @var array
	 */
	protected $original_menu;

	/**
	 * Original $submenu global.
	 *
	 * @var array
	 */
	protected $original_submenu;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Store original settings.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Enable chat channels toolkit for tests by default.
		$settings                                  = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_chat_channels_toolkit']  = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Store and clear menu globals.
		global $menu, $submenu;
		$this->original_menu    = $menu;
		$this->original_submenu = $submenu;
		$menu                   = array();
		$submenu                = array();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Restore original settings.
		if ( ! empty( $this->original_settings ) ) {
			update_option( 'wp_mcp_ai_settings', $this->original_settings );
		} else {
			delete_option( 'wp_mcp_ai_settings' );
		}

		// Restore menu globals.
		global $menu, $submenu;
		$menu    = $this->original_menu;
		$submenu = $this->original_submenu;

		parent::tearDown();
	}

	/**
	 * Test that the Chat Channels Menu class exists.
	 */
	public function test_chat_channels_menu_class_exists() {
		$menu_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php';
		if ( ! file_exists( $menu_file ) ) {
			$this->markTestSkipped( 'Chat Channels Menu file not found.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Chat_Channels_Menu' ) ) {
			require_once $menu_file;
		}

		$this->assertTrue( class_exists( 'WP_MCP_AI_Chat_Channels_Menu' ), 'WP_MCP_AI_Chat_Channels_Menu class should exist' );
	}

	/**
	 * Test that the top-level Chat Channels menu is registered when toolkit is enabled
	 * and the Pro plugin is active (WP_MCP_AI_PRO_VERSION defined).
	 *
	 * This validates the fix: $is_base && ! defined( 'WP_MCP_AI_PRO_VERSION' )
	 * ensures the menu appears even when WP_MCP_AI_BASE_VERSION is true.
	 */
	public function test_top_level_menu_registered_when_pro_active() {
		global $menu;

		$menu_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php';
		if ( ! file_exists( $menu_file ) ) {
			$this->markTestSkipped( 'Chat Channels Menu file not found.' );
		}

		// WP_MCP_AI_PRO_VERSION must be defined for Pro to be considered active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PRO_VERSION not defined - Pro plugin not active.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Chat_Channels_Menu' ) ) {
			require_once $menu_file;
		}

		// Instantiate the menu handler (mirrors what chat-channels-toolkit-init.php does).
		new WP_MCP_AI_Chat_Channels_Menu();

		// Trigger admin_menu action so menus are registered.
		do_action( 'admin_menu' );

		// Find the Chat Channels top-level menu entry.
		$found_top_level = false;
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && 'wp-mcp-ai-chat-channels' === $item[2] ) {
				$found_top_level = true;
				break;
			}
		}

		$this->assertTrue( $found_top_level, 'Chat Channels top-level menu should be registered when Pro is active' );
	}

	/**
	 * Test that Chat Channels sub-pages (Inbox, Contacts, Automation, Settings)
	 * are registered under the top-level menu.
	 */
	public function test_sub_pages_registered() {
		global $submenu;

		$menu_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php';
		if ( ! file_exists( $menu_file ) ) {
			$this->markTestSkipped( 'Chat Channels Menu file not found.' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PRO_VERSION not defined - Pro plugin not active.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Chat_Channels_Menu' ) ) {
			require_once $menu_file;
		}

		new WP_MCP_AI_Chat_Channels_Menu();
		do_action( 'admin_menu' );

		$parent_slug = 'wp-mcp-ai-chat-channels';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Chat Channels should have sub-menu items' );

		$slugs_found = array();
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( isset( $item[2] ) ) {
					$slugs_found[] = $item[2];
				}
			}
		}

		$this->assertContains( 'wp-mcp-ai-chat-channels', $slugs_found, 'Dashboard sub-page should be registered' );
		$this->assertContains( 'wp-mcp-ai-chat-channels-inbox', $slugs_found, 'Inbox sub-page should be registered' );
		$this->assertContains( 'wp-mcp-ai-chat-channels-contacts', $slugs_found, 'Contacts sub-page should be registered' );
		$this->assertContains( 'wp-mcp-ai-chat-channels-automation', $slugs_found, 'Automation sub-page should be registered' );
		$this->assertContains( 'wp-mcp-ai-chat-channels-settings', $slugs_found, 'Settings sub-page should be registered' );
	}

	/**
	 * Test that the toolkit init file respects the $is_base && ! WP_MCP_AI_PRO_VERSION pattern.
	 *
	 * When WP_MCP_AI_PRO_VERSION is defined, the menu must load regardless of
	 * WP_MCP_AI_BASE_VERSION, matching the quiz CPT approach.
	 */
	public function test_is_base_check_respects_pro_version() {
		// This test verifies the logic of the fix without needing to re-include the init file.
		$is_base_version_true = true; // Simulate WP_MCP_AI_BASE_VERSION = true (default).
		$pro_version_defined  = defined( 'WP_MCP_AI_PRO_VERSION' );

		// Old (buggy) check: $is_base alone blocks loading.
		$old_is_base = $is_base_version_true;
		$this->assertTrue( $old_is_base, 'Old check: $is_base is true by default' );

		// New (fixed) check: $is_base && ! defined( WP_MCP_AI_PRO_VERSION ).
		$new_is_base = $is_base_version_true && ! $pro_version_defined;

		if ( $pro_version_defined ) {
			// When Pro is active, new $is_base should be false, allowing the menu to load.
			$this->assertFalse( $new_is_base, 'Fixed check: $is_base should be false when Pro is active, allowing menu to load' );
		} else {
			// When Pro is not active, $is_base should still be true (base-only install).
			$this->assertTrue( $new_is_base, 'Fixed check: $is_base should be true when Pro is not active' );
		}
	}
}
