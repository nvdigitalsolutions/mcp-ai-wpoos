<?php
/**
 * F&B Adapter-Parity Test — Phase 9 acceptance.
 *
 * Imports the demo workbook's five transactional tables into the native
 * CPTs losslessly, then proves the metric engine returns IDENTICAL results
 * whether the data resolves from the CSV fixture backend or from
 * WordPress native storage (§3 oracle suite vs CPT-backed fixtures).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: fixture-backend vs native-CPT metric parity.
 */
class Test_Fnb_Cpt_Parity extends WP_UnitTestCase {

	/**
	 * Toolkit directory.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up the F&B services with the fixture backend.
	 */
	public function setUp(): void {
		parent::setUp();

		// Force the CSV fixture adapter (no network) for the primary backend.
		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		$base = $this->toolkit_dir . '/';
		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-fixture-reader.php',
				'class-wp-mcp-ai-fnb-metrics.php',
				'trait-wp-mcp-ai-fnb-native-cpt-storage.php',
				'class-wp-mcp-ai-fnb-cpt-reader.php',
				'class-wp-mcp-ai-fnb-sale-cpt.php',
				'class-wp-mcp-ai-fnb-purchase-cpt.php',
				'class-wp-mcp-ai-fnb-stock-count-cpt.php',
				'class-wp-mcp-ai-fnb-waste-cpt.php',
				'class-wp-mcp-ai-fnb-booking-cpt.php',
			) as $file
		) {
			if ( file_exists( $base . $file ) ) {
				require_once $base . $file;
			}
		}

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * Metric snapshot — the §3 metric surface on the active backend.
	 *
	 * Every value the engine can compute from the data tables is captured so
	 * both backends can be compared byte-for-byte. WP_Error entries would
	 * make the comparison vacuous, so the test asserts none occur.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot() {
		$month = '2026-09';

		return array(
			'm01_revenue'             => WP_MCP_AI_Fnb_Metrics::m01_revenue( $month ),
			'm02_covers'              => WP_MCP_AI_Fnb_Metrics::m02_covers( $month ),
			'm03_avg_spend'           => WP_MCP_AI_Fnb_Metrics::m03_avg_spend( $month ),
			'm04_food_cost_pct'       => WP_MCP_AI_Fnb_Metrics::m04_food_cost_pct( $month ),
			'm05_beverage_cost_pct'   => WP_MCP_AI_Fnb_Metrics::m05_beverage_cost_pct( $month ),
			'm06_cost_per_cover'      => WP_MCP_AI_Fnb_Metrics::m06_cost_per_cover( $month ),
			'm09_overtime_per_100'    => WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( $month ),
			'm10_labour_pct'          => WP_MCP_AI_Fnb_Metrics::m10_labour_pct( $month ),
			'm11_electricity_per_cov' => WP_MCP_AI_Fnb_Metrics::m11_electricity_per_cover( $month ),
			'm12_expected_covers'     => WP_MCP_AI_Fnb_Metrics::m12_expected_covers( '2026-10-09' ),
			'm13_expected_portions'   => WP_MCP_AI_Fnb_Metrics::m13_expected_portions( 'B-01', '2026-10-09' ),
			'm14_days_of_cover'       => WP_MCP_AI_Fnb_Metrics::m14_days_of_cover( 'ING-001', '2026-09-30' ),
			'm15_stock_used'          => WP_MCP_AI_Fnb_Metrics::m15_stock_used( 'ING-001', '2026-09-01', '2026-09-30' ),
			'm16_expected_use'        => WP_MCP_AI_Fnb_Metrics::m16_expected_use( 'ING-001', $month ),
			'm17_unexplained_var'     => WP_MCP_AI_Fnb_Metrics::m17_unexplained_variance( 'ING-001', '2026-09-01', '2026-09-30' ),
			'm18_waste_pct'           => WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $month ),
			'm19_price_change_impact' => WP_MCP_AI_Fnb_Metrics::m19_price_change_impact( $month ),
			'm20_budget_variance'     => WP_MCP_AI_Fnb_Metrics::m20_budget_variance( $month ),
			'm21_dish_margin'         => WP_MCP_AI_Fnb_Metrics::m21_dish_margin( 'B-01', $month ),
			'menu_engineering'        => WP_MCP_AI_Fnb_Metrics::menu_engineering( $month ),
			'unit_price_as_of'        => WP_MCP_AI_Fnb_Metrics::unit_price_as_of( 'ING-001', '2026-09-15' ),
			'total_operating_cost'    => WP_MCP_AI_Fnb_Metrics::total_operating_cost( $month ),
		);
	}

	/**
	 * Import every fixture row of a transactional table into its CPT.
	 *
	 * Direct inserts mirror the fixture file order so native reads
	 * (date ASC, ID ASC) match the fixture row order exactly.
	 *
	 * @param string $table_id  Spec table ID.
	 * @param string $cpt_class CPT class name.
	 * @return int Imported row count.
	 */
	private function import_table( $table_id, $cpt_class ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( $table_id, false );
		if ( is_wp_error( $table ) ) {
			return 0;
		}

		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		$canonical  = $definition['canonical'];
		$headers    = $table['header_index'];
		$post_type  = $cpt_class::POST_TYPE;

		$imported = 0;
		foreach ( $table['rows'] as $row ) {
			$values = array();
			foreach ( $canonical as $canonical_key ) {
				$values[ $canonical_key ] = (string) WP_MCP_AI_Fnb_Data_Source::cell( $table_id, $headers, $row, $canonical_key );
			}

			$date_candidates = array( 'date', 'count_date', 'delivery_date' );
			$post_date       = '';
			foreach ( $date_candidates as $date_key ) {
				if ( isset( $values[ $date_key ] ) && '' !== $values[ $date_key ] ) {
					$post_date = WP_MCP_AI_Fnb_Data_Source::date_only( $values[ $date_key ] );
					break;
				}
			}

			$post_args = array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s %d', $table_id, $imported + 1 ),
			);
			if ( '' !== $post_date ) {
				$post_args['post_date'] = $post_date . ' 12:00:00';
			}

			$post_id = wp_insert_post( $post_args, true );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			foreach ( $values as $canonical_key => $value ) {
				update_post_meta( $post_id, '_fnb_' . $canonical_key, $value );
			}
			++$imported;
		}

		return $imported;
	}

	/**
	 * Test: the metric engine returns identical results on both backends.
	 */
	public function test_metrics_identical_on_native_storage() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) || ! class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}

		// Baseline: fixture (Drive/CSV) backend.
		delete_option( 'wp_mcp_ai_fnb_settings' );
		$baseline = $this->snapshot();
		foreach ( $baseline as $key => $value ) {
			$this->assertNotInstanceOf( 'WP_Error', $value, $key . ' must compute on the fixture backend' );
		}

		// Lossless import of the five transactional tables.
		$imported  = $this->import_table( '6.0', 'WP_MCP_AI_Fnb_Sale_CPT' );
		$imported += $this->import_table( '8.0', 'WP_MCP_AI_Fnb_Purchase_CPT' );
		$imported += $this->import_table( '9.0', 'WP_MCP_AI_Fnb_Stock_Count_CPT' );
		$imported += $this->import_table( '10.0', 'WP_MCP_AI_Fnb_Waste_CPT' );
		$imported += $this->import_table( '16.0', 'WP_MCP_AI_Fnb_Booking_CPT' );
		$this->assertGreaterThan( 9000, $imported, 'The import must round-trip the demo workbook rows.' );

		// Switch to native storage.
		update_option( 'wp_mcp_ai_fnb_settings', array( 'enable_fnb_native_storage' => true ) );
		WP_MCP_AI_Fnb_Sale_CPT::register();
		WP_MCP_AI_Fnb_Purchase_CPT::register();
		WP_MCP_AI_Fnb_Stock_Count_CPT::register();
		WP_MCP_AI_Fnb_Waste_CPT::register();
		WP_MCP_AI_Fnb_Booking_CPT::register();

		// The transactional tables now resolve from the CPTs.
		$native_sales = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0' );
		$this->assertSame( 'fnb_native_cpt', $native_sales['source'], 'Table 6.0 must dispatch to native storage.' );
		$fixture_sales = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0', false );
		$this->assertSame( $fixture_sales['count'], $native_sales['count'], 'The native table must hold every fixture row.' );

		// Native-backend snapshot must be byte-identical to the fixture one.
		$native = $this->snapshot();
		foreach ( $native as $key => $value ) {
			$this->assertNotInstanceOf( 'WP_Error', $value, $key . ' must compute on the native backend' );
		}
		$this->assertEquals( $baseline, $native, 'Metric results must be identical on both storage backends.' );
	}
}
