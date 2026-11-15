<?php
/**
 * Tests the Chat Channels admin menu gate (090-p W5).
 *
 * The page calls `mcp-ai-pro/v1/chat-channels`, but no controller registers
 * that route. The menu is therefore off by default and can be turned on with a
 * filter once the communications toolkit ships.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     1.2.22
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */
class WP_MCP_AI_Chat_Channels_Menu_Gate_Test extends WP_UnitTestCase {

	/**
	 * Load the admin class (it is normally required only in wp-admin).
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'The Pro addon is not loaded in this suite.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php';
	}

	/**
	 * Off by default: the route behind it does not exist yet.
	 */
	public function test_the_surface_is_disabled_by_default() {
		$this->assertFalse( WP_MCP_AI_Chat_Channels_Menu::is_enabled() );
	}

	/**
	 * The filter is the documented rollback/enable switch.
	 */
	public function test_the_filter_enables_the_surface() {
		add_filter( 'wp_mcp_ai_chat_channels_enabled', '__return_true' );

		$this->assertTrue( WP_MCP_AI_Chat_Channels_Menu::is_enabled() );

		remove_filter( 'wp_mcp_ai_chat_channels_enabled', '__return_true' );
	}

	/**
	 * Registering the menu while disabled changes nothing.
	 */
	public function test_register_menus_is_a_noop_when_disabled() {
		global $menu, $submenu;

		$menu_before    = is_array( $menu ) ? $menu : array();
		$submenu_before = is_array( $submenu ) ? $submenu : array();

		( new WP_MCP_AI_Chat_Channels_Menu() )->register_menus();

		$this->assertSame( $menu_before, is_array( $menu ) ? $menu : array() );
		$this->assertSame( $submenu_before, is_array( $submenu ) ? $submenu : array() );
	}
}
