<?php
/**
 * Tests for the database output guard.
 *
 * The guard suppresses `$wpdb` error display around a callback so failing
 * queries cannot leak HTML into JSON response surfaces, and restores the
 * original error-display state afterwards.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the database output guard.
 *
 * @group db
 */
class Test_Db_Output_Guard extends WP_UnitTestCase {

	/**
	 * Restore default $wpdb error-display state between tests.
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->show_errors     = false;
		$wpdb->suppress_errors = false;

		parent::tearDown();
	}

	/**
	 * The guard returns the callback result and restores the original
	 * error-display state.
	 */
	public function test_run_restores_error_display_state_and_returns_result() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-db-output-guard.php';

		global $wpdb;
		$wpdb->show_errors = true;
		$wpdb->suppress_errors( false );

		$result = WP_MCP_AI_Db_Output_Guard::run(
			'test_guard',
			function () {
				return 'payload';
			}
		);

		$this->assertSame( 'payload', $result );
		$this->assertTrue( $wpdb->show_errors );
		$this->assertFalse( $wpdb->suppress_errors );
	}

	/**
	 * A failing query inside the guarded callback produces no output even
	 * when error display is enabled.
	 */
	public function test_run_suppresses_db_error_output() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-db-output-guard.php';

		global $wpdb;
		$wpdb->show_errors = true;
		$wpdb->suppress_errors( false );

		$this->expectOutputString( '' );

		WP_MCP_AI_Db_Output_Guard::run(
			'test_guard',
			function () use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional failing probe; the point of the test is the suppressed error output.
				$wpdb->get_var( "SELECT 1 FROM `{$wpdb->prefix}wp_mcp_ai_guard_no_such_table` LIMIT 1" );

				return null;
			}
		);

		$this->assertTrue( $wpdb->show_errors );
		$this->assertFalse( $wpdb->suppress_errors );
	}
}
