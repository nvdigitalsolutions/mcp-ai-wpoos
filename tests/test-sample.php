<?php
/**
 * Sample test case for NV oOS plugin.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Sample_Test extends WP_UnitTestCase {

	/**
	 * Ensure the plugin bootstrap has been registered.
	 */
	public function test_plugin_bootstrap_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_bootstrap' ) );
	}
}
