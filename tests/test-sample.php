<?php
/**
 * Sample test case for WP oOS plugin.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Sample_Test extends WP_UnitTestCase {

	/**
	 * Ensure the plugin bootstrap has been registered.
	 */
	public function test_plugin_bootstrap_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_bootstrap' ) );
	}
}
