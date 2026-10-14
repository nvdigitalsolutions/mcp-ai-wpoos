<?php
/**
 * F&B Workbook Source Test — pins the data-source resolution fallback.
 *
 * Verifies that `WP_MCP_AI_Fnb_Settings::workbook_id()` returns the explicit
 * `data_workbook_id` when configured, and otherwise falls back to the
 * canonical demo workbook (filterable via `wp_mcp_ai_fnb_demo_workbook_id`).
 *
 * Regression for the "F&B read path stops at 'file not found'" break: a fresh
 * install had no configured workbook or folder, so `fnb_read_table` could
 * never resolve the single shared demo workbook and fell through to the
 * split-file lookup dead-end.
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
	 * With no explicit workbook configured, workbook_id() falls back to the demo.
	 */
	public function test_workbook_id_falls_back_to_demo() {
		$this->maybe_skip();

		$this->assertSame(
			WP_MCP_AI_Fnb_Settings::DEMO_WORKBOOK_ID,
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'workbook_id() should resolve to the demo workbook when data_workbook_id is empty.'
		);
	}

	/**
	 * An explicit data_workbook_id wins over the demo fallback.
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
	 * A whitespace-only explicit ID is treated as unset (trimmed).
	 */
	public function test_workbook_id_trims_whitespace_only_value() {
		$this->maybe_skip();

		update_option( 'wp_mcp_ai_fnb_settings', array( 'data_workbook_id' => '   ' ) );

		$this->assertSame(
			WP_MCP_AI_Fnb_Settings::DEMO_WORKBOOK_ID,
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'A blank/whitespace workbook ID should fall back to the demo ID.'
		);
	}

	/**
	 * The demo-workbook fallback is filterable and can be disabled.
	 */
	public function test_workbook_id_filter_can_disable_demo_fallback() {
		$this->maybe_skip();

		add_filter( 'wp_mcp_ai_fnb_demo_workbook_id', '__return_empty_string' );

		$this->assertSame(
			'',
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'Filtering the demo workbook ID to an empty string must disable the fallback.'
		);
	}

	/**
	 * The demo-workbook fallback filter can point at a custom workbook.
	 */
	public function test_workbook_id_filter_can_override_demo() {
		$this->maybe_skip();

		add_filter(
			'wp_mcp_ai_fnb_demo_workbook_id',
			function () {
				return 'custom-demo-workbook';
			}
		);

		$this->assertSame(
			'custom-demo-workbook',
			WP_MCP_AI_Fnb_Settings::workbook_id(),
			'Filtering the demo workbook ID must allow a custom default.'
		);
	}
}
