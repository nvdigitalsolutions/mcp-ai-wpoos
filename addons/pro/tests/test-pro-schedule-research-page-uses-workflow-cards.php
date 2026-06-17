<?php
/**
 * Tests that the Research & Add Schedule page renders using the new
 * workflow-card pattern (`.workflow-option[data-workflow=...]`) and no
 * longer uses the legacy `.mode-tab` markup.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Class Test_Pro_Schedule_Research_Page_Uses_Workflow_Cards
 */
class Test_Pro_Schedule_Research_Page_Uses_Workflow_Cards extends WP_UnitTestCase {

	/**
	 * Capture the rendered HTML of the Schedule Research page.
	 *
	 * @return string
	 */
	protected function render_page_html() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active.' );
		}

		$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-research-page.php';
		if ( ! file_exists( $page_file ) ) {
			$this->markTestSkipped( 'Schedule research page file missing.' );
		}
		require_once $page_file;

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Research_Page' ) ) {
			$this->markTestSkipped( 'Schedule research page class not loaded.' );
		}

		// Use an admin user so the capability check passes.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		WP_MCP_AI_Pro_Schedule_Research_Page::render_page();
		return (string) ob_get_clean();
	}

	/**
	 * The page renders three workflow cards.
	 */
	public function test_renders_workflow_cards() {
		$html = $this->render_page_html();

		$this->assertStringContainsString( 'class="wp-mcp-ai-workflow-selector"', $html, 'workflow selector container present' );
		$this->assertStringContainsString( 'data-workflow="research"', $html, 'AI Research card present' );
		$this->assertStringContainsString( 'data-workflow="import"', $html, 'Bulk Import card present' );
		$this->assertStringContainsString( 'data-workflow="review"', $html, 'Review card present' );
		$this->assertStringContainsString( 'data-workflow="calendar"', $html, 'Calendar card present' );
	}

	/**
	 * The page wraps each renderer in a workflow-content panel.
	 */
	public function test_renders_workflow_content_panels() {
		$html = $this->render_page_html();

		$this->assertStringContainsString( 'id="workflow-research"', $html );
		$this->assertStringContainsString( 'id="workflow-import"', $html );
		$this->assertStringContainsString( 'id="workflow-review"', $html );
		$this->assertStringContainsString( 'id="workflow-calendar"', $html );
	}

	/**
	 * The page no longer emits the legacy .mode-tab markup.
	 */
	public function test_no_legacy_mode_tab_markup() {
		$html = $this->render_page_html();

		// The bespoke wp-mcp-ai-mode-tabs container must be gone.
		$this->assertStringNotContainsString( 'wp-mcp-ai-mode-tabs', $html, 'legacy mode-tabs container removed' );
		// Anchor-style mode-tab links must be gone.
		$this->assertDoesNotMatchRegularExpression( '/class="[^"]*\bmode-tab\b[^"]*"/', $html, 'legacy mode-tab links removed' );
	}

	/**
	 * Settings + Manager quick-action links are present in the page header.
	 */
	public function test_page_links_to_settings_and_manager() {
		$html = $this->render_page_html();

		$this->assertStringContainsString( 'page=nvoos-pro-schedule-manager', $html );
		$this->assertStringContainsString( 'page=wp-mcp-ai-pro-schedule-toolkit-settings', $html );
	}
}
