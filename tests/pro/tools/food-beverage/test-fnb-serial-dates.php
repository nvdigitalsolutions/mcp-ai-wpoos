<?php
/**
 * F&B Serial-Date Resolution Tests — Google Sheets / Excel serial dates.
 *
 * Real client exports (read from Drive with UNFORMATTED_VALUE) carry date
 * cells as serial numbers: days since 1899-12-30, e.g. 46023 = 2026-01-01.
 * Regression from PR #7108: month derivation switched to the `date` cell,
 * and `date_only()` interpreted the serials as epoch timestamps, collapsing
 * every row into month "1970-01" — the trend payload then had fewer than
 * two months and the dashboard charts disappeared entirely.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B serial-date resolution.
 */
class Test_Fnb_Serial_Dates extends WP_UnitTestCase {

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
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}
	}

	/**
	 * Delete cached table payloads.
	 *
	 * @return void
	 */
	private function clear_table_cache() {
		foreach ( array( '6.0', '7.0', '13.0' ) as $table_id ) {
			delete_transient( 'fnb_table_' . md5( $table_id ) );
			delete_transient( 'fnb_table_' . md5( $table_id . ':cpt' ) );
		}
	}

	/**
	 * Sheets serial dates convert to real Y-m-d values.
	 */
	public function test_serial_dates_convert() {
		$this->maybe_skip();

		$this->assertSame( '2026-01-01', WP_MCP_AI_Fnb_Data_Source::date_only( '46023' ) );
		$this->assertSame( '2026-08-01', WP_MCP_AI_Fnb_Data_Source::date_only( '46235' ) );
		$this->assertSame( '2026-01', WP_MCP_AI_Fnb_Data_Source::month_of( '46023' ) );
		$this->assertSame( '2026-08', WP_MCP_AI_Fnb_Data_Source::month_of( '46235' ) );
	}

	/**
	 * Epoch timestamps and date strings keep their existing behaviour.
	 */
	public function test_epoch_and_strings_unchanged() {
		$this->maybe_skip();

		$this->assertSame( '2026-01-01', WP_MCP_AI_Fnb_Data_Source::date_only( '1767225600' ) );
		$this->assertSame( '2026-07-01', WP_MCP_AI_Fnb_Data_Source::date_only( '2026-07-01 00:00:00' ) );
		$this->assertSame( '2026-07', WP_MCP_AI_Fnb_Data_Source::month_of( '2026-07-01 00:00:00' ) );
	}

	/**
	 * Empty, zero and negative cells mean "no date" — never a phantom month.
	 */
	public function test_empty_and_zero_cells() {
		$this->maybe_skip();

		$this->assertSame( '', WP_MCP_AI_Fnb_Data_Source::date_only( '' ) );
		$this->assertSame( '', WP_MCP_AI_Fnb_Data_Source::date_only( '0' ) );
		$this->assertSame( '', WP_MCP_AI_Fnb_Data_Source::date_only( '-1' ) );
		$this->assertSame( '', WP_MCP_AI_Fnb_Data_Source::month_of( '0' ) );
	}

	/**
	 * The dashboard trend payload resolves serial-date months and carries
	 * per-month revenue instead of returning null.
	 */
	public function test_trend_payload_resolves_serial_dates() {
		$this->maybe_skip();

		$stub = new class() implements WP_MCP_AI_Fnb_Table_Reader {
			/**
			 * Return two months of daily-sales rows with serial dates.
			 *
			 * @param string $table_id           Spec table ID.
			 * @param string $connection_override Connection override (ignored).
			 * @return array|WP_Error
			 */
			public function read_table( $table_id, $connection_override = '' ) {
				unset( $connection_override );

				if ( '6.0' === $table_id ) {
					return array(
						'columns' => array( 'date', 'month', 'item_id', 'item', 'type', 'qty_sold', 'price', 'revenue' ),
						'rows'    => array(
							array( '46023', '2026-01', 'A-01', 'Item A', 'Food', '10', '100', '1000' ),
							array( '46235', '2026-08', 'B-01', 'Item B', 'Food', '5', '200', '1000' ),
						),
					);
				}

				return new WP_Error( 'wp_mcp_ai_fnb_stub_missing', 'No fixture data (test).' );
			}
		};

		$this->reader_property->setValue( null, $stub );
		$this->clear_table_cache();

		$file = dirname( __DIR__, 4 ) . '/addons/pro/includes/admin/class-wp-mcp-ai-fnb-dashboard-page.php';
		$this->assertFileExists( $file );
		require_once $file;

		$payload = WP_MCP_AI_Fnb_Dashboard_Page::trend_payload();

		$this->assertNotNull( $payload, 'Serial-date rows must produce a trend payload with at least two months.' );
		$this->assertSame( array( '2026-01', '2026-08' ), $payload['labels'] );
		$this->assertSame( array( 1000.0, 1000.0 ), $payload['revenue'] );
	}
}
