<?php
/**
 * F&B Observation Mode Tests — loose observation → canonical binding.
 *
 * Covers: terminology (type → table) resolution, loose alias mapping
 * (qty→qty_sold, recorded_at→date), raw preservation, unknown-type errors, and
 * multi-record grouping.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B observation mode.
 */
class Test_Fnb_Observation extends WP_UnitTestCase {

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
				'class-wp-mcp-ai-fnb-observation.php',
			) as $file
		) {
			$path = $this->toolkit_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * A "waste" observation resolves to table 10.0.
	 */
	public function test_type_resolves_to_table() {
		$this->assertSame( '10.0', WP_MCP_AI_Fnb_Observation::table_for_type( 'waste' ) );
		$this->assertSame( '6.0', WP_MCP_AI_Fnb_Observation::table_for_type( 'daily sale' ) );
		$this->assertFalse( WP_MCP_AI_Fnb_Observation::table_for_type( 'mystery' ) );
	}

	/**
	 * Loose aliases map onto canonical columns and raw_value is preserved.
	 */
	public function test_bind_maps_aliases_and_preserves_raw() {
		$observation = array(
			'type'        => 'waste',
			'item'        => 'tomatoes',
			'qty'         => 2.5,
			'unit'        => 'kg',
			'recorded_at' => '2026-10-15',
			'source'      => 'supplier_csv',
			'raw_value'   => '2.5 kg',
		);

		$bound = WP_MCP_AI_Fnb_Observation::bind( $observation );

		$this->assertSame( '10.0', $bound['table_id'] );
		$this->assertSame( '2026-10-15', $bound['row']['date'] );
		$this->assertSame( 'tomatoes', $bound['row']['ingredient'] );
		$this->assertSame( '2.5', $bound['row']['qty'] );
		$this->assertSame( 'kg', $bound['row']['unit'] );
		$this->assertSame( '2.5 kg', $bound['row']['_raw_observation'] );
	}

	/**
	 * A "sale" observation maps qty → qty_sold.
	 */
	public function test_bind_sale_maps_qty_to_qty_sold() {
		$observation = array(
			'type'        => 'sale',
			'item'        => 'IG-01',
			'qty'         => 3,
			'recorded_at' => '2026-10-01',
		);

		$bound = WP_MCP_AI_Fnb_Observation::bind( $observation );

		$this->assertSame( '6.0', $bound['table_id'] );
		$this->assertSame( '3', $bound['row']['qty_sold'] );
	}

	/**
	 * An unknown type returns a WP_Error with the offending type.
	 */
	public function test_unknown_type_errors() {
		$bound = WP_MCP_AI_Fnb_Observation::bind( array( 'type' => 'mystery' ) );

		$this->assertInstanceOf( 'WP_Error', $bound );
		$this->assertSame( 'wp_mcp_ai_fnb_obs_unknown_type', $bound->get_error_code() );
	}

	/**
	 * Multiple observations group under their resolved tables, bad ones error.
	 */
	public function test_map_records_groups_and_records_errors() {
		$observations = array(
			array(
				'type' => 'sale',
				'item' => 'IG-01',
				'qty'  => 2,
			),
			array(
				'type' => 'waste',
				'item' => 'tomatoes',
				'qty'  => 1,
			),
			array( 'type' => 'mystery' ),
		);

		$result = WP_MCP_AI_Fnb_Observation::map_records( $observations );

		$this->assertArrayHasKey( '6.0', $result['grouped'] );
		$this->assertArrayHasKey( '10.0', $result['grouped'] );
		$this->assertCount( 1, $result['errors'] );
	}
}
