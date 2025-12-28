<?php
/**
 * Sample test case for NV oOS plugin.
 *
 * @package WP_MCP_AI
 */


	/**
	 * Ensure the plugin bootstrap has been registered.
	 */
public function test_plugin_bootstrap_exists() {
	$this->assertTrue( function_exists( 'wp_mcp_ai_bootstrap' ) );
}
}
