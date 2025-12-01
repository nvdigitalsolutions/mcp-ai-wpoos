<?php
/**
 * Tests for the dashboard diagnostic global variables check.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Dashboard_Diagnostic_Globals_Test extends WP_UnitTestCase {

	/**
	 * Test that diagnostic correctly identifies new settings dashboard mode.
	 */
	public function test_diagnostic_identifies_new_settings_mode() {
		// Mock the new settings dashboard global.
		$GLOBALS['wp_mcp_ai_settings_dashboard'] = new stdClass();

		// Create diagnostic instance.
		$diagnostic = new WP_MCP_AI_Dashboard_Diagnostic();

		// Capture the output.
		ob_start();
		$diagnostic->render_diagnostic_page();
		$output = ob_get_clean();

		// Verify the output mentions new settings dashboard.
		$this->assertStringContainsString( 'New Dashboard', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_settings_dashboard', $output );

		// Clean up.
		unset( $GLOBALS['wp_mcp_ai_settings_dashboard'] );
	}

	/**
	 * Test that diagnostic shows appropriate status for settings dashboard global.
	 */
	public function test_diagnostic_shows_dashboard_global_status() {
		// Test with global set.
		$GLOBALS['wp_mcp_ai_settings_dashboard'] = new stdClass();

		$diagnostic = new WP_MCP_AI_Dashboard_Diagnostic();
		ob_start();
		$diagnostic->render_diagnostic_page();
		$output = ob_get_clean();

		// Should show as set with green checkmark.
		$this->assertStringContainsString( '✓ Set', $output );
		$this->assertStringContainsString( 'New settings dashboard instance', $output );

		// Clean up.
		unset( $GLOBALS['wp_mcp_ai_settings_dashboard'] );
	}

	/**
	 * Test that diagnostic shows appropriate note for admin settings in new mode.
	 */
	public function test_diagnostic_shows_admin_settings_not_needed_in_new_mode() {
		// Don't set the admin settings global (expected in new mode).
		unset( $GLOBALS['wp_mcp_ai_admin_settings'] );

		$diagnostic = new WP_MCP_AI_Dashboard_Diagnostic();
		ob_start();
		$diagnostic->render_diagnostic_page();
		$output = ob_get_clean();

		// Should show as not set but with gray color and note that it's not needed.
		$this->assertStringContainsString( 'wp_mcp_ai_admin_settings', $output );
		$this->assertStringContainsString( 'Not needed (new dashboard mode)', $output );
	}

	/**
	 * Test that diagnostic has notes column in global variables table.
	 */
	public function test_diagnostic_has_notes_column() {
		$diagnostic = new WP_MCP_AI_Dashboard_Diagnostic();
		ob_start();
		$diagnostic->render_diagnostic_page();
		$output = ob_get_clean();

		// Verify table has three columns including Notes.
		$this->assertMatchesRegularExpression( '/<th[^>]*>.*Variable.*<\/th>/s', $output );
		$this->assertMatchesRegularExpression( '/<th[^>]*>.*Status.*<\/th>/s', $output );
		$this->assertMatchesRegularExpression( '/<th[^>]*>.*Notes.*<\/th>/s', $output );
	}
}
