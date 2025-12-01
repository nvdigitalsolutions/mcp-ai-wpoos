<?php
/**
 * Tests for Dashboard Widget Links
 *
 * @package WP_MCP_AI
 */

/**
 * Test that dashboard widgets use correct page slugs in their links.
 */
class Test_Dashboard_Widget_Links extends WP_UnitTestCase {

	/**
	 * Test that usage-forecast widget uses correct page slug.
	 */
	public function test_usage_forecast_widget_uses_correct_page_slug() {
		$widget_file = WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php';
		$this->assertFileExists( $widget_file );

		$content = file_get_contents( $widget_file );

		// Should use wp-mcp-ai-dashboard (new dashboard page).
		$this->assertStringContainsString( 'page=wp-mcp-ai-dashboard', $content );

		// Should NOT use wp-mcp-ai-settings (old settings page).
		$this->assertStringNotContainsString( 'page=wp-mcp-ai-settings', $content );
	}

	/**
	 * Test that token-usage-overview widget uses correct page slug.
	 */
	public function test_token_usage_overview_widget_uses_correct_page_slug() {
		$widget_file = WP_MCP_AI_PATH . 'includes/admin/widgets/token-usage-overview.php';
		$this->assertFileExists( $widget_file );

		$content = file_get_contents( $widget_file );

		// Should use wp-mcp-ai-dashboard (new dashboard page).
		$this->assertStringContainsString( 'page=wp-mcp-ai-dashboard', $content );

		// Should NOT use wp-mcp-ai-settings (old settings page).
		$this->assertStringNotContainsString( 'page=wp-mcp-ai-settings', $content );
	}

	/**
	 * Test that cost-breakdown widget uses correct page slug.
	 */
	public function test_cost_breakdown_widget_uses_correct_page_slug() {
		$widget_file = WP_MCP_AI_PATH . 'includes/admin/widgets/cost-breakdown.php';
		$this->assertFileExists( $widget_file );

		$content = file_get_contents( $widget_file );

		// Should use wp-mcp-ai-dashboard (new dashboard page).
		$this->assertStringContainsString( 'page=wp-mcp-ai-dashboard', $content );

		// Should NOT use wp-mcp-ai-settings (old settings page).
		$this->assertStringNotContainsString( 'page=wp-mcp-ai-settings', $content );
	}

	/**
	 * Test that all dashboard widget links point to token_manager tab.
	 */
	public function test_all_dashboard_widgets_link_to_token_manager_tab() {
		$widget_files = array(
			WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php',
			WP_MCP_AI_PATH . 'includes/admin/widgets/token-usage-overview.php',
			WP_MCP_AI_PATH . 'includes/admin/widgets/cost-breakdown.php',
		);

		foreach ( $widget_files as $widget_file ) {
			$this->assertFileExists( $widget_file );
			$content = file_get_contents( $widget_file );

			// All widgets should link to token_manager tab.
			$this->assertStringContainsString( 'tab=token_manager', $content );
		}
	}

	/**
	 * Test that usage-forecast widget link is properly escaped.
	 */
	public function test_usage_forecast_widget_link_is_escaped() {
		$widget_file = WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php';
		$content     = file_get_contents( $widget_file );

		// Link should be properly escaped with esc_url and admin_url.
		$this->assertStringContainsString( 'esc_url( admin_url(', $content );
	}

	/**
	 * Test that token-usage-overview widget link is properly escaped.
	 */
	public function test_token_usage_overview_widget_link_is_escaped() {
		$widget_file = WP_MCP_AI_PATH . 'includes/admin/widgets/token-usage-overview.php';
		$content     = file_get_contents( $widget_file );

		// Link should be properly escaped with esc_url and admin_url.
		$this->assertStringContainsString( 'esc_url( admin_url(', $content );
	}

	/**
	 * Test that cost-breakdown widget link is properly escaped.
	 */
	public function test_cost_breakdown_widget_link_is_escaped() {
		$widget_file = WP_MCP_AI_PATH . 'includes/admin/widgets/cost-breakdown.php';
		$content     = file_get_contents( $widget_file );

		// Link should be properly escaped with esc_url and admin_url.
		$this->assertStringContainsString( 'esc_url( admin_url(', $content );
	}
}
