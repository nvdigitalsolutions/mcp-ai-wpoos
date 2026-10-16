<?php
/**
 * F&B Workbook Source Test — pins the data-source resolution contract.
 *
 * Verifies that `WP_MCP_AI_Fnb_Settings::workbook_id()` returns the explicit
 * `data_workbook_id` when configured and otherwise returns empty (the demo
 * workbook is no longer a silent fallback — universalisation P5), and that
 * demo mode is off by default.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: workbook source resolution.
 */
class Test_Fnb_Workbook_Source extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-settings.php';
		}

		delete_option( 'wp_mcp_ai_fnb_settings' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		remove_all_filters( 'wp_mcp_ai_fnb_demo_workbook_id' );
		parent::tearDown();
	}

	/**
	 * Skip when the toolkit settings class is unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}
	}

	/**
	 * The canonical demo workbook ID constant is non-empty and stable.
	 */
	public function test_demo_workbook_id_constant_is_set() {
		$this->maybe_skip();

		$id = WP_MCP_AI_Fnb_Settings::DEMO_WORKBOOK_ID;
		$this->assertIsString( $id );
		$this->assertNotSame( '', $id, 'Demo workbook ID must not be empty.' );
		$this->assertSame( '1L6YzAq08EnR-Hvu9Wne_dULNDBuBhBwplrv8KdIlWlE', $id );
	}

	/**
	 * With no explicit workbook configured, workbook_id() returns empty — the
	 * demo workbook is no longer a silent fallback (universalisation P5).
	 */
	public function test_workbook_id_empty_when_unset() {
		$this->maybe_skip();

		$this->assertSame(
			'',
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'workbook_id() must return empty when data_workbook_id is unset (no demo fallback).'
		);
	}

	/**
	 * An explicit data_workbook_id wins.
	 */
	public function test_workbook_id_prefers_explicit_setting() {
		$this->maybe_skip();

		update_option( 'wp_mcp_ai_fnb_settings', array( 'data_workbook_id' => 'explicit-workbook-id' ) );

		$this->assertSame(
			'explicit-workbook-id',
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'An explicitly configured data_workbook_id must take precedence.'
		);
	}

	/**
	 * A whitespace-only explicit ID is treated as unset (trimmed) and returns empty.
	 */
	public function test_workbook_id_trims_whitespace_only_value() {
		$this->maybe_skip();

		update_option( 'wp_mcp_ai_fnb_settings', array( 'data_workbook_id' => '   ' ) );

		$this->assertSame(
			'',
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'A blank/whitespace workbook ID must resolve to empty (no demo fallback).'
		);
	}

	/**
	 * Demo mode is off by default and demo is never a silent fallback.
	 */
	public function test_demo_mode_off_by_default() {
		$this->maybe_skip();

		$this->assertFalse( WP_MCP_AI_Fnb_Settings::is_demo_mode(), 'Demo mode must be off by default.' );
	}
}
