<?php
/**
 * EZuite CCT Manager — API response shape tests.
 *
 * Verifies that the LX_ItemStockPull response shape (ItemCode +
 * Stock_By_Location) and the legacy LX_ItemPull shape (Item_Code +
 * top-level Location_Code) both normalize and map into CCT rows correctly.
 *
 * @package WP_MCP_AI_Pro
 * @since 3.2.0
 */

/**
 * Test class for WP_MCP_AI_EZuite_CCT_Manager item mapping.
 *
 * @group ezuite
 * @group pro
 */
class Test_EZuite_CCT_Manager_API_Shape extends WP_UnitTestCase {

	/**
	 * CCT manager instance.
	 *
	 * @var WP_MCP_AI_EZuite_CCT_Manager
	 */
	protected $manager;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				define( 'WP_MCP_AI_PRO_PATH', WP_MCP_AI_PATH . '../addons/pro/' );
			} else {
				$this->markTestSkipped( 'WP_MCP_AI_PATH or WP_MCP_AI_PRO_PATH not defined.' );
				return;
			}
		}

		$class_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-cct-manager.php';
		if ( ! file_exists( $class_file ) ) {
			$this->markTestSkipped( 'Pro addon not available. EZuite CCT Manager is a pro class.' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {
			require_once $class_file;
		}

		$this->manager = new WP_MCP_AI_EZuite_CCT_Manager();

		// Keep the tests isolated from any stored custom mapping.
		delete_option( 'wp_mcp_ai_ezuite_toolkit_settings' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		delete_option( 'wp_mcp_ai_ezuite_toolkit_settings' );

		parent::tear_down();
	}

	/**
	 * A canonical LX_ItemStockPull item maps to a CCT row with the location
	 * breakdown preserved.
	 */
	public function test_maps_new_itemstockpull_shape() {
		$row = $this->invoke_map(
			array(
				'ItemCode'          => '170639',
				'Item_Name'         => 'Earrings with Stickpin 1500 MC',
				'Supplier_Name'     => 'Coeur de Lion',
				'Group_Name'        => 'Earrings',
				'Selling_Price'     => 8200.0,
				'Qty'               => 1.0,
				'Stock_By_Location' => array(
					array(
						'Setup_Location_Code' => 'EZCMP316/EZLOC-3',
						'Location_Name'       => 'A Little Sparkle – One Galle Face',
						'Qty'                 => 1.0,
					),
				),
			),
			array(),
			'conn_new'
		);

		$this->assertSame( '170639', $row['sku'] );
		$this->assertSame( 'Earrings with Stickpin 1500 MC', $row['name'] );
		$this->assertSame( 'EZCMP316/EZLOC-3', $row['warehouse'] );
		$this->assertSame( 'A Little Sparkle – One Galle Face', $row['location_name'] );
		$this->assertSame( 'Coeur de Lion', $row['supplier'] );
		$this->assertSame( 1, $row['quantity'] );
		$this->assertSame( '8200.00', $row['cost_price'] );
		$this->assertSame( 'conn_new', $row['connection_id'] );

		$stock = json_decode( $row['stock_by_location'], true );
		$this->assertIsArray( $stock );
		$this->assertCount( 1, $stock );
		$this->assertSame( 'EZCMP316/EZLOC-3', $stock[0]['Setup_Location_Code'] );
	}

	/**
	 * A legacy LX_ItemPull item still maps, and gets a synthesized
	 * single-location breakdown.
	 */
	public function test_maps_legacy_itempull_shape() {
		$row = $this->invoke_map(
			array(
				'Item_Code'     => 'C316/L16/ITM-10',
				'Item_Name'     => 'Bangle 1816 Crystal Gold',
				'Location_Code' => 'MAIN',
				'Qty'           => 37.0,
				'Selling_Price' => 129.00,
			),
			array(),
			'conn_legacy'
		);

		$this->assertSame( 'C316/L16/ITM-10', $row['sku'] );
		$this->assertSame( 'MAIN', $row['warehouse'] );
		$this->assertSame( 37, $row['quantity'] );

		$stock = json_decode( $row['stock_by_location'], true );
		$this->assertIsArray( $stock );
		$this->assertCount( 1, $stock );
		$this->assertSame( 'MAIN', $stock[0]['Setup_Location_Code'] );
		// json_encode drops the trailing .0 on whole-number floats, so the
		// decoded Qty is an int.
		$this->assertSame( 37, $stock[0]['Qty'] );
	}

	/**
	 * When the new shape omits the top-level Qty, the quantity falls back to
	 * the sum of the per-location quantities.
	 */
	public function test_quantity_falls_back_to_location_sum() {
		$row = $this->invoke_map(
			array(
				'ItemCode'          => 'SUM-1',
				'Item_Name'         => 'Sum Item',
				'Stock_By_Location' => array(
					array(
						'Setup_Location_Code' => 'LOC-A',
						'Location_Name'       => 'Location A',
						'Qty'                 => 2.0,
					),
					array(
						'Setup_Location_Code' => 'LOC-B',
						'Location_Name'       => 'Location B',
						'Qty'                 => 3.0,
					),
				),
			),
			array(),
			'conn_sum'
		);

		$this->assertSame( 5, $row['quantity'] );
		$this->assertSame( 'LOC-A', $row['warehouse'] );
		$this->assertSame( 'Location A', $row['location_name'] );

		$stock = json_decode( $row['stock_by_location'], true );
		$this->assertCount( 2, $stock );
	}

	/**
	 * A custom field-mapping override pointing warehouse at Location_Name
	 * resolves because normalization exposes the alias.
	 */
	public function test_custom_mapping_override_resolves() {
		$row = $this->invoke_map(
			array(
				'ItemCode'          => 'OVR-1',
				'Item_Name'         => 'Override Item',
				'Stock_By_Location' => array(
					array(
						'Setup_Location_Code' => 'LOC-9',
						'Location_Name'       => 'Flagship Store',
						'Qty'                 => 1.0,
					),
				),
			),
			array( 'warehouse' => 'Location_Name' ),
			'conn_ovr'
		);

		$this->assertSame( 'Flagship Store', $row['warehouse'] );
	}

	/**
	 * Item code aliases resolve in both directions during normalization.
	 */
	public function test_normalize_aliases_item_code_both_directions() {
		$method = new ReflectionMethod( 'WP_MCP_AI_EZuite_CCT_Manager', 'normalize_ezuite_item' );
		$method->setAccessible( true );

		$from_new    = $method->invoke( $this->manager, array( 'ItemCode' => 'NEW-1' ) );
		$from_legacy = $method->invoke( $this->manager, array( 'Item_Code' => 'OLD-1' ) );

		$this->assertSame( 'NEW-1', $from_new['Item_Code'] );
		$this->assertSame( 'NEW-1', $from_new['ItemCode'] );
		$this->assertSame( 'OLD-1', $from_legacy['ItemCode'] );
		$this->assertSame( 'OLD-1', $from_legacy['Item_Code'] );
	}

	/**
	 * An item without any item code maps to an empty SKU so upsert can
	 * reject it with the honest missing-SKU error.
	 */
	public function test_missing_sku_maps_to_empty() {
		$row = $this->invoke_map(
			array(
				'Item_Name' => 'No Code Item',
				'Qty'       => 1.0,
			),
			array(),
			'conn_none'
		);

		$this->assertSame( '', $row['sku'] );
	}

	/**
	 * Invoke the protected map_ezuite_item_to_cct_row() via reflection.
	 *
	 * @param array  $item          Raw EZuite item.
	 * @param array  $mapping       Field mapping overrides.
	 * @param string $connection_id Connection ID.
	 * @return array Mapped CCT row.
	 */
	protected function invoke_map( $item, $mapping = array(), $connection_id = '' ) {
		$method = new ReflectionMethod( 'WP_MCP_AI_EZuite_CCT_Manager', 'map_ezuite_item_to_cct_row' );
		$method->setAccessible( true );

		return $method->invoke( $this->manager, $item, $mapping, $connection_id );
	}
}
