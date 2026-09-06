<?php
/**
 * Tests for the assistant metabox crash fix.
 *
 * This test verifies that the register_meta_boxes() method only runs
 * for assistant post types and doesn't crash when editing regular pages/posts.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Assistant_Metabox_Crash_Fix_Test extends WP_UnitTestCase {

	/**
	 * Test that register_meta_boxes only runs for assistant post type.
	 */
	public function test_register_meta_boxes_only_for_assistant_post_type() {
		// Create a mock registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create assistant CPT instance.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Simulate editing a regular post (not assistant). Use a real
		// WP_Screen: WP 7.1's get_current_screen() only returns WP_Screen
		// instances.
		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		set_current_screen( $screen );

		// Count metaboxes before calling register_meta_boxes.
		global $wp_meta_boxes;
		$before_count = isset( $wp_meta_boxes['post'] ) ? count( $wp_meta_boxes['post'], COUNT_RECURSIVE ) : 0;

		// Call register_meta_boxes - this should NOT register any metaboxes for 'post' type.
		$assistant_cpt->register_meta_boxes();

		// Count metaboxes after calling register_meta_boxes.
		$after_count = isset( $wp_meta_boxes['post'] ) ? count( $wp_meta_boxes['post'], COUNT_RECURSIVE ) : 0;

		// Verify no metaboxes were added to the 'post' type.
		$this->assertEquals( $before_count, $after_count, 'Metaboxes should not be added for regular posts' );

		// Now test with assistant post type.
		get_current_screen()->post_type = 'mcp_ai_assistant';

		// Count metaboxes before calling register_meta_boxes.
		$before_count = isset( $wp_meta_boxes['mcp_ai_assistant'] ) ? count( $wp_meta_boxes['mcp_ai_assistant'], COUNT_RECURSIVE ) : 0;

		// Call register_meta_boxes - this SHOULD register metaboxes for assistant type.
		$assistant_cpt->register_meta_boxes();

		// Count metaboxes after calling register_meta_boxes.
		$after_count = isset( $wp_meta_boxes['mcp_ai_assistant'] ) ? count( $wp_meta_boxes['mcp_ai_assistant'], COUNT_RECURSIVE ) : 0;

		// Verify metaboxes were added to the assistant type.
		$this->assertGreaterThan( $before_count, $after_count, 'Metaboxes should be added for assistant posts' );

		// Clean up.
		$GLOBALS['current_screen'] = null;
	}

	/**
	 * Test that WP_MCP_AI_Admin_Settings::get_settings() is accessible.
	 *
	 * This verifies that the static get_settings method works even without
	 * instantiating the old admin settings class.
	 */
	public function test_admin_settings_get_settings_accessible() {
		// This should not throw an error.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Verify it returns an array.
		$this->assertIsArray( $settings, 'get_settings() should return an array' );
	}

	/**
	 * Test that new dashboard is loaded by default.
	 */
	public function test_new_dashboard_loaded() {
		// Verify the new dashboard classes exist.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Settings_Dashboard' ), 'Settings dashboard class should exist' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Settings_Registry' ), 'Settings registry class should exist' );
	}

	/**
	 * The SiteKit tools must return string-flag arrays from
	 * get_capability_flags(). They previously referenced a nonexistent
	 * interface constant (CAPABILITY_CAN_USE_IF_ADMIN), which threw an
	 * uncaught Error while the assistant tools metabox rendered the grid and
	 * killed the whole page on nugl.com (the render died exactly at
	 * sitekit_get_adsense).
	 */
	public function test_sitekit_tools_capability_flags_return_arrays() {
		$tools = array(
			'WP_MCP_AI_Tool_SiteKit_AdSense',
			'WP_MCP_AI_Tool_SiteKit_Analytics',
			'WP_MCP_AI_Tool_SiteKit_PageSpeed',
			'WP_MCP_AI_Tool_SiteKit_Search_Console',
		);

		foreach ( $tools as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				$class_name . ' should exist.'
			);

			$tool = new $class_name();

			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Capability_Flags_Interface',
				$tool,
				$class_name . ' should implement the capability flags interface.'
			);

			$flags = $tool->get_capability_flags();

			$this->assertIsArray(
				$flags,
				$class_name . '::get_capability_flags() should return an array.'
			);

			foreach ( $flags as $flag ) {
				$this->assertIsString(
					$flag,
					$class_name . ' capability flags should be strings.'
				);
			}
		}
	}
}
