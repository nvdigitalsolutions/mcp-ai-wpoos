<?php
/**
 * CI guard for AJAX handler coverage.
 *
 * Runs `php bin/audit-ajax-handlers.php --check` and asserts that every
 * `wp_ajax_wp_mcp_ai_*` handler registered in the codebase is either
 * referenced by a test file under `tests/` or explicitly allow-listed in
 * `tests/ajax-coverage-allowlist.txt`.
 *
 * The seed allow-list captures the 185 handlers that were untested when the
 * AJAX gap-fill plan was adopted. As coverage clusters land in subsequent PRs,
 * remove the corresponding entries from the allow-list so this guard starts
 * enforcing them.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * AJAX coverage CI guard.
 */
class Test_AJAX_Handler_Coverage extends WP_UnitTestCase {

	/**
	 * The audit script must report zero new coverage gaps.
	 */
	public function test_no_untested_handlers_outside_allowlist() {
		$plugin_root = dirname( __DIR__ );
		$script      = $plugin_root . '/bin/audit-ajax-handlers.php';

		$this->assertFileExists( $script, 'Audit script bin/audit-ajax-handlers.php must exist.' );

		$output    = array();
		$exit_code = 0;
		$cmd       = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --check 2>&1';
		exec( $cmd, $output, $exit_code );

		$this->assertSame(
			0,
			$exit_code,
			"Untested AJAX handlers detected (not on allow-list).\n"
			. "Add a test referencing the handler name, or add it to tests/ajax-coverage-allowlist.txt with a reason.\n\n"
			. implode( "\n", $output )
		);
	}
}
