<?php
/**
 * F&B Structure Matcher Tests — header-based table inference.
 *
 * Covers: confidence scoring, required-column matching, alias drift, and the
 * best_table selection above/below threshold.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B structure matcher.
 */
class Test_Fnb_Structure_Matcher extends WP_UnitTestCase {

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

		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-structure-matcher.php',
			) as $file
		) {
			$path = $this->toolkit_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * A daily-sales header set (with spacing/casing drift) must match 6.0 at full confidence.
	 */
	public function test_daily_sales_headers_match_full_confidence() {
		$headers = array( 'Date', 'Month', 'Item ID', 'Item', 'Type', 'Qty Sold', 'Price LKR', 'Revenue LKR' );

		$score = WP_MCP_AI_Fnb_Structure_Matcher::match_table( '6.0', $headers );

		$this->assertIsArray( $score );
		$this->assertSame( 1.0, $score['confidence'] );
		$this->assertContains( 'date', $score['matched'] );
		$this->assertContains( 'item_id', $score['matched'] );
		$this->assertContains( 'qty_sold', $score['matched'] );
		$this->assertContains( 'revenue_lkr', $score['matched'] );
		$this->assertEmpty( $score['missing'] );
	}

	/**
	 * A partial header set (missing required columns) must score below full.
	 */
	public function test_partial_headers_score_below_full() {
		// Only date + item_id present of the four required for 6.0.
		$headers = array( 'Date', 'Item ID' );

		$score = WP_MCP_AI_Fnb_Structure_Matcher::match_table( '6.0', $headers );

		$this->assertIsArray( $score );
		$this->assertEqualsWithDelta( 0.5, $score['confidence'], 0.0001 );
		$this->assertContains( 'qty_sold', $score['missing'] );
		$this->assertContains( 'revenue_lkr', $score['missing'] );
	}

	/**
	 * Unknown headers must not match any table above the default threshold.
	 */
	public function test_unknown_headers_no_best_table() {
		$headers = array( 'foo', 'bar', 'baz', 'qux' );

		$best = WP_MCP_AI_Fnb_Structure_Matcher::best_table( $headers );

		$this->assertFalse( $best );
	}

	/**
	 * Alias-drift ("Delivered" instead of "delivery_date") still resolves purchases (8.0).
	 */
	public function test_alias_drift_resolves_purchases() {
		$headers = array( 'Delivery Date', 'Month', 'Supplier ID', 'Invoice', 'Ingredient ID', 'Qty Delivered' );

		$score = WP_MCP_AI_Fnb_Structure_Matcher::match_table( '8.0', $headers );

		$this->assertIsArray( $score );
		$this->assertContains( 'delivery_date', $score['matched'] );
		$this->assertContains( 'supplier_id', $score['matched'] );
		$this->assertContains( 'invoice', $score['matched'] );
	}

	/**
	 * Select the correct table when two share overlapping columns.
	 */
	public function test_best_table_selects_waste_over_sales() {
		// Waste log (10.0) requires date + ingredient_id + qty + reason.
		$headers = array( 'Date', 'Month', 'Ingredient ID', 'Ingredient', 'Qty', 'Unit', 'Reason', 'Logged By' );

		$best = WP_MCP_AI_Fnb_Structure_Matcher::best_table( $headers );

		$this->assertIsArray( $best );
		$this->assertSame( '10.0', $best['table_id'] );
		$this->assertEqualsWithDelta( 1.0, $best['score']['confidence'], 0.0001 );
	}
}
