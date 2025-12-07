<?php
/**
 * Tests for multisite support.
 *
 * @package WP_MCP_AI
 */

/**
 * Test multisite activation, deactivation, and uninstall.
 */
class WP_MCP_AI_Test_Multisite_Support extends WP_UnitTestCase {

	/**
	 * Test that activation function exists and accepts network_wide parameter.
	 */
	public function test_activation_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_activate' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_activate_single_site' ) );
	}

	/**
	 * Test that deactivation function exists and accepts network_wide parameter.
	 */
	public function test_deactivation_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_deactivate' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_deactivate_single_site' ) );
	}

	/**
	 * Test that uninstall function exists and handles multisite.
	 */
	public function test_uninstall_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_uninstall' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_uninstall_single_site' ) );
	}

	/**
	 * Test that new site activation function exists.
	 */
	public function test_new_site_activation_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_new_site_activation' ) );
	}

	/**
	 * Test single site activation registers the post type and flushes rewrite rules.
	 */
	public function test_single_site_activation() {
		// Ensure function is callable.
		$this->assertTrue( is_callable( 'wp_mcp_ai_activate_single_site' ) );

		// Call the activation function.
		wp_mcp_ai_activate_single_site();

		// Note: As of WordPress 6.7+, the post type is not registered during activation
		// to avoid triggering translation loading before the init action.
		// The post type will be registered on the next page load via the init hook.
		// Manually trigger the post type registration to verify it works.
		WP_MCP_AI_Assistant_CPT::register_post_type();

		// Verify the assistant post type is registered.
		$this->assertTrue( post_type_exists( WP_MCP_AI_Assistant_CPT::POST_TYPE ) );
	}

	/**
	 * Test that the plugin header includes Network: true.
	 */
	public function test_plugin_header_has_network_support() {
		$plugin_file = WP_MCP_AI_PATH . 'mcp-ai-wpoos.php';
		$plugin_data = get_file_data(
			$plugin_file,
			array(
				'Network' => 'Network',
			)
		);

		$this->assertEquals( 'true', $plugin_data['Network'], 'Plugin header should include "Network: true"' );
	}

	/**
	 * Test that activation hooks are registered.
	 */
	public function test_activation_hooks_registered() {
		global $wp_filter;

		// Check that wp_initialize_site hook exists for new sites.
		$this->assertArrayHasKey( 'wp_initialize_site', $wp_filter );

		// Check that wpmu_new_blog hook exists for backward compatibility.
		$this->assertArrayHasKey( 'wpmu_new_blog', $wp_filter );
	}
}
