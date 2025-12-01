<?php
/**
 * Tests for the assistant metabox crash fix.
 *
 * This test verifies that the register_meta_boxes() method only runs
 * for assistant post types and doesn't crash when editing regular pages/posts.
 *
 * @package WP_MCP_AI
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

		// Simulate editing a regular post (not assistant).
		global $current_screen;
		$current_screen = (object) array(
			'post_type' => 'post',
		);

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
		$current_screen->post_type = 'mcp_ai_assistant';

		// Count metaboxes before calling register_meta_boxes.
		$before_count = isset( $wp_meta_boxes['mcp_ai_assistant'] ) ? count( $wp_meta_boxes['mcp_ai_assistant'], COUNT_RECURSIVE ) : 0;

		// Call register_meta_boxes - this SHOULD register metaboxes for assistant type.
		$assistant_cpt->register_meta_boxes();

		// Count metaboxes after calling register_meta_boxes.
		$after_count = isset( $wp_meta_boxes['mcp_ai_assistant'] ) ? count( $wp_meta_boxes['mcp_ai_assistant'], COUNT_RECURSIVE ) : 0;

		// Verify metaboxes were added to the assistant type.
		$this->assertGreaterThan( $before_count, $after_count, 'Metaboxes should be added for assistant posts' );

		// Clean up.
		$current_screen = null;
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
}
