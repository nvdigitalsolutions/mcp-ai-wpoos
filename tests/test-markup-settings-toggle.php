<?php
/**
 * Tests for the markup-subsystem Settings UI toggle (PR 5).
 *
 * Verifies:
 *  - `markup_enabled` is materialized as a default in
 *    {@see WP_MCP_AI_Admin_Settings_Base::get_default_settings()} so
 *    the existing pattern-based sanitizer treats it as a boolean
 *    checkbox and round-trips correctly.
 *  - `WP_MCP_AI_Section_Tools::get_fields()` exposes a
 *    `markup_enabled` checkbox field that defaults to `true`.
 *  - The `WP_MCP_AI_Markup_Loop_Interceptor::is_enabled()` kill-switch
 *    honours the persisted value (`true` -> enabled, `false` ->
 *    disabled, absent -> filter default).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-loop-interceptor.php';

/**
 * Test_Markup_Settings_Toggle test case.
 *
 * @group markup
 * @group settings
 */
class Test_Markup_Settings_Toggle extends WP_UnitTestCase {

	/**
	 * Reset the option after every test so cases stay isolated.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Defaults include markup_enabled = true.
	 */
	public function test_defaults_include_markup_enabled_true() {
		$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();
		$this->assertArrayHasKey( 'markup_enabled', $defaults );
		$this->assertTrue( $defaults['markup_enabled'] );
	}

	/**
	 * Tools section exposes the markup_enabled checkbox.
	 */
	public function test_tools_section_exposes_markup_field() {
		$section = new WP_MCP_AI_Section_Tools();
		$fields  = $section->get_fields();
		$this->assertArrayHasKey( 'markup_enabled', $fields );
		$this->assertSame( 'checkbox', $fields['markup_enabled']['type'] );
		$this->assertTrue( $fields['markup_enabled']['default'] );
		$this->assertNotEmpty( $fields['markup_enabled']['label'] );
		$this->assertNotEmpty( $fields['markup_enabled']['description'] );
	}

	/**
	 * Kill-switch: persisted false disables interception.
	 */
	public function test_is_enabled_returns_false_when_setting_false() {
		update_option( 'wp_mcp_ai_settings', array( 'markup_enabled' => false ) );
		$this->assertFalse( WP_MCP_AI_Markup_Loop_Interceptor::is_enabled() );
	}

	/**
	 * Persisted true enables interception.
	 */
	public function test_is_enabled_returns_true_when_setting_true() {
		update_option( 'wp_mcp_ai_settings', array( 'markup_enabled' => true ) );
		$this->assertTrue( WP_MCP_AI_Markup_Loop_Interceptor::is_enabled() );
	}

	/**
	 * Absent key falls through to the filter default (true).
	 */
	public function test_is_enabled_falls_back_to_filter_default_when_absent() {
		update_option( 'wp_mcp_ai_settings', array( 'unrelated' => 'value' ) );
		$this->assertTrue( WP_MCP_AI_Markup_Loop_Interceptor::is_enabled() );
	}

	/**
	 * Absent key + filter override flips the default.
	 */
	public function test_is_enabled_filter_can_override_default_when_absent() {
		delete_option( 'wp_mcp_ai_settings' );
		add_filter( 'wp_mcp_ai_markup_enabled', '__return_false' );
		$this->assertFalse( WP_MCP_AI_Markup_Loop_Interceptor::is_enabled() );
		remove_filter( 'wp_mcp_ai_markup_enabled', '__return_false' );
	}
}
