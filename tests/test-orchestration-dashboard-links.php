<?php
/**
 * Tests for Orchestration Dashboard Links
 *
 * Verifies that links in the orchestration dashboard point to correct pages.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that orchestration dashboard uses correct page slugs in its links.
 */
class Test_Orchestration_Dashboard_Links extends WP_UnitTestCase {

	/**
	 * Test that orchestration dashboard uses correct tools page link.
	 */
	public function test_orchestration_dashboard_uses_correct_tools_link() {
		$dashboard_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
		$this->assertFileExists( $dashboard_file );

		$content = file_get_contents( $dashboard_file );

		// Should use wp-mcp-ai-dashboard&tab=tools (correct dashboard tools page).
		$this->assertStringContainsString( 'page=wp-mcp-ai-dashboard&tab=tools', $content );

		// Should NOT use mcp-ai-settings#tools (old/incorrect settings page).
		$this->assertStringNotContainsString( 'page=mcp-ai-settings#tools', $content );
	}

	/**
	 * Test that Configure Tools link is properly escaped.
	 */
	public function test_configure_tools_link_is_escaped() {
		$dashboard_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
		$content        = file_get_contents( $dashboard_file );

		// Link should be properly escaped with esc_url and admin_url.
		$this->assertStringContainsString( 'esc_url( admin_url(', $content );
	}

	/**
	 * Test that Configure Tools button exists with correct text.
	 */
	public function test_configure_tools_button_exists() {
		$dashboard_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
		$content        = file_get_contents( $dashboard_file );

		// Should have Configure Tools text for translation.
		$this->assertStringContainsString( "esc_html_e( 'Configure Tools', 'mcp-ai-wpoos' )", $content );
	}
}
