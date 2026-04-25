<?php
/**
 * Test: PR 12 uninstall hygiene.
 *
 * The activation/uninstall code in `includes/bootstrap/activation.php`
 * runs at process shutdown so it cannot be trivially exercised in
 * isolation. We assert the static intent instead: the metric-events
 * table created by PR 9 must be present in the drop list. A grep-style
 * test is enough — and far less brittle than booting a second WP
 * install just to call `wp_mcp_ai_uninstall_single_site()`.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static assertion that uninstall drops the metric-events table.
 */
class Test_WP_MCP_AI_Measurement_Uninstall_List extends WP_UnitTestCase {

	/**
	 * Asserts the activation file enumerates the PR 9 metric_events table.
	 */
	public function test_activation_php_drops_metric_events_table() {
		$path = WP_MCP_AI_PATH . 'includes/bootstrap/activation.php';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin file in tests, not a remote URL.
		$contents = (string) file_get_contents( $path );
		$this->assertStringContainsString(
			"\$wpdb->prefix . 'mcp_ai_metric_events'",
			$contents,
			'PR 9 introduced the mcp_ai_metric_events table; it must be dropped at uninstall when delete_on_uninstall is enabled.'
		);
	}
}
