<?php
/**
 * F&B Analytics Dashboard Test — renders KPIs + recent rows without a hard fatal.
 *
 * The dashboard is server-rendered; this test loads the class in an admin
 * context and calls render_page() under an edit_posts user to confirm the new
 * analytics path does not fatal (metric-engine + native-read guards hold).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B analytics dashboard.
 */
class Test_Fnb_Dashboard extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';
		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-fixture-reader.php',
				'class-wp-mcp-ai-fnb-metrics.php',
				'trait-wp-mcp-ai-fnb-native-cpt-storage.php',
				'class-wp-mcp-ai-fnb-cpt-reader.php',
			) as $file
		) {
			$path = $toolkit_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	/**
	 * Loading the dashboard class and calling render_page() must not fatal.
	 */
	public function test_dashboard_render_does_not_fatal() {
		$file = dirname( __DIR__, 4 ) . '/addons/pro/includes/admin/class-wp-mcp-ai-fnb-dashboard-page.php';
		$this->assertFileExists( $file );
		require_once $file;

		ob_start();
		WP_MCP_AI_Fnb_Dashboard_Page::render_page();
		$html = ob_get_clean();

		$this->assertIsString( $html );
		// The analytics section renders when the fixture month resolves.
		$this->assertStringContainsString( 'Latest analytics', $html );

		// Chart.js canvases must sit inside fixed-height wrappers (the
		// maintainAspectRatio:false resize loop otherwise grows them forever).
		$this->assertStringContainsString( 'fnb-chart-wrap', $html );
		$this->assertStringNotContainsString( 'height="120"', $html );
	}
}
