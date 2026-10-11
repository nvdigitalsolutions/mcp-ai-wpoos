<?php
/**
 * F&B Metrics Oracle Test — reproduces the demo workbook's Check totals sheet.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: metric engine reproduces the Check totals oracle values.
 */
class Test_Fnb_Metrics_Oracle extends WP_UnitTestCase {

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

		// Force the CSV fixture adapter (no network).
		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-settings.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-data-source.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-fixture-reader.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-metrics.php';
		}
	}

	/**
	 * Skip when the toolkit files are unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}
	}

	/**
	 * Oracle values from the workbook's Check totals sheet.
	 *
	 * @return array
	 */
	private function oracle() {
		$file = $this->toolkit_dir . '/tests/expected-values.php';

		return file_exists( $file ) ? require $file : array();
	}

	/**
	 * Numeric oracle value.
	 *
	 * @param string $key Oracle key.
	 * @return float|null
	 */
	private function oracle_num( $key ) {
		$values = $this->oracle();

		return isset( $values[ $key ] ) ? (float) $values[ $key ] : null;
	}

	/**
	 * Test: covers (M-02) match for all three months.
	 */
	public function test_m02_covers_match_check_totals() {
		$this->maybe_skip();
		foreach ( array( '2026-07', '2026-08', '2026-09' ) as $month ) {
			$result = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );
			// Oracle keys use underscores, month strings use hyphens (Y-m).
			$this->assertEquals( $this->oracle_num( 'covers_' . str_replace( '-', '_', $month ) ), $result['value'], 'Covers mismatch for ' . $month );
		}
	}

	/**
	 * Test: revenue (M-01) matches, split by type and total.
	 */
	public function test_m01_revenue_matches_check_totals() {
		$this->maybe_skip();
		$food = WP_MCP_AI_Fnb_Metrics::m01_revenue( '2026-09', 'Food' );
		$bev  = WP_MCP_AI_Fnb_Metrics::m01_revenue( '2026-09', 'Beverage' );
		$this->assertEquals( $this->oracle_num( 'food_revenue_2026_09' ), $food['value'], 'Sep food revenue' );
		$this->assertEquals( $this->oracle_num( 'beverage_revenue_2026_09' ), $bev['value'], 'Sep beverage revenue' );
		$this->assertEquals( $this->oracle_num( 'total_revenue_2026_09' ), $food['value'] + $bev['value'], 'Sep total revenue' );
	}

	/**
	 * Test: average spend per cover (M-03).
	 */
	public function test_m03_average_spend_matches() {
		$this->maybe_skip();
		$result = WP_MCP_AI_Fnb_Metrics::m03_avg_spend( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'average_spend_per_cover_2026_09' ), $result['value'], 0.1, 'Avg spend Sep' );
	}

	/**
	 * Test: stock-used cost (M-04/M-05 numerators) — value-based COGS.
	 * The engine rounds sums to 2 dp (LKR) while the oracle carries 3 dp,
	 * so compare with the ±1 LKR sum tolerance used by the smoke harness.
	 */
	public function test_stock_used_cost_matches_check_totals() {
		$this->maybe_skip();
		$m04 = WP_MCP_AI_Fnb_Metrics::m04_food_cost_pct( '2026-09' );
		$m05 = WP_MCP_AI_Fnb_Metrics::m05_beverage_cost_pct( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'food_cost_stock_used_2026_09' ), $m04['stock_used']['value'], 1.0, 'Food cost (stock used) Sep' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'beverage_cost_stock_used_2026_09' ), $m05['stock_used']['value'], 1.0, 'Beverage cost (stock used) Sep' );
	}

	/**
	 * Test: food/beverage cost % (M-04/M-05).
	 */
	public function test_cost_percentages_match() {
		$this->maybe_skip();
		$m04 = WP_MCP_AI_Fnb_Metrics::m04_food_cost_pct( '2026-09' );
		$m05 = WP_MCP_AI_Fnb_Metrics::m05_beverage_cost_pct( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'food_cost_2026_09' ), $m04['value'], 0.0001, 'Food cost % Sep' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'beverage_cost_2026_09' ), $m05['value'], 0.0001, 'Beverage cost % Sep' );
	}

	/**
	 * Test: total operating cost + cost per cover (M-06).
	 */
	public function test_m06_cost_per_cover_matches() {
		$this->maybe_skip();
		$opex = WP_MCP_AI_Fnb_Metrics::total_operating_cost( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'total_operating_expenses_2026_09' ), $opex['total'], 0.1, 'Total opex Sep' );

		$m06 = WP_MCP_AI_Fnb_Metrics::m06_cost_per_cover( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'cost_per_cover_2026_09' ), $m06['value'], 0.1, 'Cost per cover Sep' );
	}

	/**
	 * Test: volume vs per-cover effect (M-07/M-08) on payroll.
	 */
	public function test_volume_and_per_cover_effect_match() {
		$this->maybe_skip();
		$sep  = WP_MCP_AI_Fnb_Metrics::payroll_total( '2026-09' );
		$aug  = WP_MCP_AI_Fnb_Metrics::payroll_total( '2026-08' );
		$covs = WP_MCP_AI_Fnb_Metrics::m02_covers( '2026-09' );
		$covp = WP_MCP_AI_Fnb_Metrics::m02_covers( '2026-08' );

		$volume    = WP_MCP_AI_Fnb_Metrics::m07_volume_effect( $aug['value'], $covp['value'], $covs['value'] );
		$per_cover = WP_MCP_AI_Fnb_Metrics::m08_per_cover_effect( $sep['value'], $aug['value'], $volume );

		$this->assertEqualsWithDelta( $this->oracle_num( 'payroll_volume_effect' ), $volume, 0.1, 'Payroll volume effect' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'payroll_per_cover_effect' ), $per_cover, 0.1, 'Payroll per-cover effect' );
	}

	/**
	 * Test: overtime per 100 covers (M-09) and kWh per cover (M-11).
	 */
	public function test_m09_and_m11_match() {
		$this->maybe_skip();
		$m09 = WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'overtime_hours_per_100_covers_2026_09' ), $m09['value'], 0.01, 'Overtime per 100 covers Sep' );

		$m11 = WP_MCP_AI_Fnb_Metrics::m11_electricity_per_cover( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'kwh_per_cover_2026_09' ), $m11['value'], 0.001, 'kWh per cover Sep' );
	}

	/**
	 * Test: waste value (M-18) matches.
	 */
	public function test_m18_waste_value_matches() {
		$this->maybe_skip();
		$m18 = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( '2026-09' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'waste_logged_value_2026_09' ), $m18['waste_value'], 0.1, 'Waste value Sep' );
	}

	/**
	 * Test: budget variance (M-20) for food revenue and maintenance.
	 */
	public function test_m20_budget_variance_matches() {
		$this->maybe_skip();
		$result  = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( '2026-09' );
		$by_line = array();
		foreach ( $result['rows'] as $row ) {
			$by_line[ $row['line'] ] = $row;
		}
		$this->assertEqualsWithDelta( $this->oracle_num( 'food_revenue_sep_vs_budget' ), $by_line['Food revenue']['variance'], 0.1, 'Food revenue vs budget' );
		$this->assertEqualsWithDelta( $this->oracle_num( 'maintenance_sep_vs_budget' ), $by_line['Maintenance']['variance'], 0.1, 'Maintenance vs budget' );
	}

	/**
	 * Test: menu-engineering quadrant labels every item (decision 2026-10-08).
	 */
	public function test_menu_engineering_classifies_all_items() {
		$this->maybe_skip();
		$result = WP_MCP_AI_Fnb_Metrics::menu_engineering( '2026-09' );
		$this->assertNotEmpty( $result['rows'], 'Menu engineering rows' );
		foreach ( $result['rows'] as $item ) {
			$this->assertContains( $item['quadrant'], array( 'Star', 'Plowhorse', 'Puzzle', 'Dog' ), 'Quadrant for ' . $item['item_id'] );
		}
	}
}
