<?php
/**
 * F&B Data Resolution Tests — header drift + covers fallback.
 *
 * Pins the fixes for two data-resolution regressions found on real client
 * data:
 *
 * 1. M-21 recipe cost returned 0 because the Recipes sheet's
 *    "Cost per portion at as-of date (LKR)" header did not resolve to the
 *    canonical `cost_per_portion` key. The menu table also carries a
 *    precomputed "Recipe cost (LKR)" column, which is now the first choice.
 * 2. Covers (M-02) returned 0 when the daily-covers sheet was absent, even
 *    though the utilities sheet (13.0) carries a "Total covers" column.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B data resolution.
 */
class Test_Fnb_Data_Resolution extends WP_UnitTestCase {

	/**
	 * Toolkit directory.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Reflection handle for the data source's private reader singleton.
	 *
	 * @var ReflectionProperty|null
	 */
	private $reader_property;

	/**
	 * Reader instance captured before a test overrides it.
	 *
	 * @var mixed
	 */
	private $original_reader;

	/**
	 * Set up the fixture-backed environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-fixture-reader.php',
				'class-wp-mcp-ai-fnb-metrics.php',
				'class-wp-mcp-ai-fnb-cpt-reader.php',
			) as $file
		) {
			$path = $this->toolkit_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		delete_option( 'wp_mcp_ai_fnb_settings' );
		$this->clear_table_cache();

		$this->reader_property = new ReflectionProperty( 'WP_MCP_AI_Fnb_Data_Source', 'reader' );
		$this->reader_property->setAccessible( true );
		$this->original_reader = $this->reader_property->getValue( null );
	}

	/**
	 * Restore global state.
	 */
	public function tearDown(): void {
		if ( $this->reader_property instanceof ReflectionProperty ) {
			$this->reader_property->setValue( null, $this->original_reader );
		}
		$this->clear_table_cache();
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * Skip when the toolkit is unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) || ! class_exists( 'WP_MCP_AI_Fnb_Metrics' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}
	}

	/**
	 * Delete cached table payloads for the tables under test.
	 *
	 * @return void
	 */
	private function clear_table_cache() {
		foreach ( array( '1.0', '2.0', '6.0', '7.0', '13.0', '16.0' ) as $table_id ) {
			delete_transient( 'fnb_table_' . md5( $table_id ) );
		}
	}

	/**
	 * Replace the source reader and drop the cached tables.
	 *
	 * @param WP_MCP_AI_Fnb_Table_Reader $reader Reader stub.
	 * @return void
	 */
	private function use_reader( $reader ) {
		$this->reader_property->setValue( null, $reader );
		$this->clear_table_cache();
	}

	/**
	 * Return the oracle values.
	 *
	 * @return array
	 */
	private function oracle() {
		$file = $this->toolkit_dir . '/tests/expected-values.php';

		return file_exists( $file ) ? require $file : array();
	}

	/**
	 * The Menu sheet's precomputed recipe-cost columns resolve.
	 */
	public function test_menu_recipe_cost_columns_resolve() {
		$this->maybe_skip();

		$menu = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0' );
		$this->assertNotInstanceOf( 'WP_Error', $menu );

		$headers = $menu['header_index'];
		$this->assertNotFalse(
			WP_MCP_AI_Fnb_Data_Source::resolve_column( '1.0', $headers, 'recipe_cost' ),
			'"Recipe cost (LKR)" must resolve to the canonical recipe_cost key.'
		);
		$this->assertNotFalse(
			WP_MCP_AI_Fnb_Data_Source::resolve_column( '1.0', $headers, 'gross_margin' ),
			'"Gross margin (LKR)" must resolve to the canonical gross_margin key.'
		);
	}

	/**
	 * The Recipes sheet's unit-suffixed cost-per-portion header resolves.
	 */
	public function test_recipes_cost_per_portion_header_drift_resolves() {
		$this->maybe_skip();

		$recipes = WP_MCP_AI_Fnb_Data_Source::get_table( '2.0' );
		$this->assertNotInstanceOf( 'WP_Error', $recipes );

		$index = WP_MCP_AI_Fnb_Data_Source::resolve_column( '2.0', $recipes['header_index'], 'cost_per_portion' );
		$this->assertNotFalse( $index, '"Cost per portion at as-of date (LKR)" must resolve.' );
		$this->assertStringContainsString( 'Cost per portion', $recipes['columns'][ $index ] );
	}

	/**
	 * M-21 returns the menu's precomputed cost and margin for F-35.
	 */
	public function test_m21_uses_menu_recipe_cost() {
		$this->maybe_skip();

		$result = WP_MCP_AI_Fnb_Metrics::m21_dish_margin( 'F-35', '2026-09' );

		$this->assertEqualsWithDelta( 1762.0, $result['recipe_cost'], 1.0, 'F-35 recipe cost' );
		$this->assertEqualsWithDelta( 3938.0, $result['value'], 1.0, 'F-35 gross margin' );
		$this->assertEqualsWithDelta( 0.3091, $result['cost_pct'], 0.001, 'F-35 cost %' );
		$this->assertSame( 'menu', $result['recipe_cost_source'] );
		$this->assertGreaterThan( 0.0, $result['popularity_share'], 'F-35 popularity share must be non-zero.' );
	}

	/**
	 * Menu engineering now classifies with real margins and shares.
	 */
	public function test_menu_engineering_uses_resolved_recipe_costs() {
		$this->maybe_skip();

		$result = WP_MCP_AI_Fnb_Metrics::menu_engineering( '2026-09' );
		$this->assertNotEmpty( $result['rows'] );

		$by_id = array();
		foreach ( $result['rows'] as $row ) {
			$by_id[ $row['item_id'] ] = $row;
		}

		$this->assertArrayHasKey( 'F-35', $by_id );
		$this->assertGreaterThan( 0.0, $by_id['F-35']['margin'], 'F-35 margin must include its recipe cost.' );
		$this->assertGreaterThan( 0.0, $by_id['F-35']['popularity_share'], 'F-35 popularity share must be non-zero.' );
	}

	/**
	 * The utilities table exposes its Total covers column.
	 */
	public function test_utilities_total_covers_resolves() {
		$this->maybe_skip();

		$utilities = WP_MCP_AI_Fnb_Data_Source::get_table( '13.0' );
		$this->assertNotInstanceOf( 'WP_Error', $utilities );

		$index = WP_MCP_AI_Fnb_Data_Source::resolve_column( '13.0', $utilities['header_index'], 'total_covers' );
		$this->assertNotFalse( $index, '"Total covers" must resolve on the utilities table.' );
		$this->assertStringContainsString( 'Total covers', $utilities['columns'][ $index ] );
	}

	/**
	 * M-02 falls back to utilities Total covers when 7.0 is unavailable.
	 */
	public function test_m02_falls_back_to_utilities_total_covers() {
		$this->maybe_skip();

		$fixture = new WP_MCP_AI_Fnb_Fixture_Reader();
		$stub    = new class( $fixture ) implements WP_MCP_AI_Fnb_Table_Reader {
			/**
			 * Fixture delegate.
			 *
			 * @var WP_MCP_AI_Fnb_Fixture_Reader
			 */
			private $fixture;

			/**
			 * Constructor.
			 *
			 * @param WP_MCP_AI_Fnb_Fixture_Reader $fixture Fixture delegate.
			 */
			public function __construct( $fixture ) {
				$this->fixture = $fixture;
			}

			/**
			 * Fail only the daily-covers table.
			 *
			 * @param string $table_id Spec table ID.
			 * @param string $connection_override Connection override (delegated).
			 * @return array|WP_Error
			 */
			public function read_table( $table_id, $connection_override = '' ) {
				if ( '7.0' === $table_id ) {
					return new WP_Error( 'wp_mcp_ai_fnb_sheet_not_found', 'Daily covers sheet missing (test).' );
				}

				return $this->fixture->read_table( $table_id, $connection_override );
			}
		};

		$this->use_reader( $stub );

		$result = WP_MCP_AI_Fnb_Metrics::m02_covers( '2026-09' );

		$this->assertEqualsWithDelta( 2441.0, $result['value'], 0.5, 'Covers must fall back to utilities Total covers.' );
		$this->assertSame( 30, $result['days'] );
		$this->assertSame( 'fnb_metrics:M-02:utilities', $result['source'] );
	}

	/**
	 * M-09 and M-11 inherit the utilities covers fallback.
	 */
	public function test_m09_and_m11_use_utilities_covers_fallback() {
		$this->maybe_skip();

		$fixture = new WP_MCP_AI_Fnb_Fixture_Reader();
		$stub    = new class( $fixture ) implements WP_MCP_AI_Fnb_Table_Reader {
			/**
			 * Fixture delegate.
			 *
			 * @var WP_MCP_AI_Fnb_Fixture_Reader
			 */
			private $fixture;

			/**
			 * Constructor.
			 *
			 * @param WP_MCP_AI_Fnb_Fixture_Reader $fixture Fixture delegate.
			 */
			public function __construct( $fixture ) {
				$this->fixture = $fixture;
			}

			/**
			 * Fail only the daily-covers table.
			 *
			 * @param string $table_id Spec table ID.
			 * @param string $connection_override Connection override (delegated).
			 * @return array|WP_Error
			 */
			public function read_table( $table_id, $connection_override = '' ) {
				if ( '7.0' === $table_id ) {
					return new WP_Error( 'wp_mcp_ai_fnb_sheet_not_found', 'Daily covers sheet missing (test).' );
				}

				return $this->fixture->read_table( $table_id, $connection_override );
			}
		};

		$this->use_reader( $stub );

		$oracle = $this->oracle();

		$m09 = WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( '2026-09' );
		$m11 = WP_MCP_AI_Fnb_Metrics::m11_electricity_per_cover( '2026-09' );

		$this->assertEqualsWithDelta( (float) $oracle['overtime_hours_per_100_covers_2026_09'], $m09['value'], 0.02, 'M-09 with utilities covers' );
		$this->assertEqualsWithDelta( (float) $oracle['kwh_per_cover_2026_09'], $m11['value'], 0.002, 'M-11 with utilities covers' );
		$this->assertSame( 2441.0, (float) $m11['covers'] );
	}

	/**
	 * A source failure on a native-backed table points at native storage.
	 */
	public function test_native_table_source_error_mentions_native_storage() {
		$this->maybe_skip();

		$fixture = new WP_MCP_AI_Fnb_Fixture_Reader();
		$stub    = new class( $fixture ) implements WP_MCP_AI_Fnb_Table_Reader {
			/**
			 * Fixture delegate.
			 *
			 * @var WP_MCP_AI_Fnb_Fixture_Reader
			 */
			private $fixture;

			/**
			 * Constructor.
			 *
			 * @param WP_MCP_AI_Fnb_Fixture_Reader $fixture Fixture delegate.
			 */
			public function __construct( $fixture ) {
				$this->fixture = $fixture;
			}

			/**
			 * Fail the bookings table (native-backed in the toolkit).
			 *
			 * @param string $table_id Spec table ID.
			 * @param string $connection_override Connection override (delegated).
			 * @return array|WP_Error
			 */
			public function read_table( $table_id, $connection_override = '' ) {
				if ( '16.0' === $table_id ) {
					return new WP_Error( 'wp_mcp_ai_fnb_sheet_tab_missing', 'No bookings worksheet (test).' );
				}

				return $this->fixture->read_table( $table_id, $connection_override );
			}
		};

		$this->use_reader( $stub );
		update_option( 'wp_mcp_ai_fnb_settings', array( 'enable_fnb_native_storage' => false ) );

		$result = WP_MCP_AI_Fnb_Data_Source::get_table( '16.0' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'native storage', strtolower( $result->get_error_message() ) );
	}
}
