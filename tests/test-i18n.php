<?php
/**
 * Tests related to internationalisation hooks.
 */
class WP_MCP_AI_I18N_Test extends WP_UnitTestCase {

	/**
	 * Ensure the textdomain loader is registered on plugins_loaded.
	 */
	public function test_textdomain_loader_is_registered() {
		$this->assertNotFalse( has_action( 'plugins_loaded', 'wp_mcp_ai_load_textdomain' ) );
	}
}
