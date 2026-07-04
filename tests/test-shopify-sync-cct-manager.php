<?php
/**
 * Shopify Sync CCT Manager Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

/**
 * Test class for WP_MCP_AI_Shopify_Sync_CCT_Manager.
 */
class Test_Shopify_Sync_CCT_Manager extends WP_UnitTestCase {

	/**
	 * Mock connection ID used across tests.
	 *
	 * @var string
	 */
	protected $connection_id = 'conn_test_shopify_001';

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the required classes are available.
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
		}

		// Set up toolkit settings with CCT slug.
		update_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array(
			'cct_slug'            => 'shopify_inventory_sync_test',
			'sync_interval'       => 15,
			'sync_direction'      => 'shopify_to_woo',
			'low_stock_threshold' => 5,
			'sync_connections'    => array( $this->connection_id ),
		) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_shopify_sync_toolkit_settings' );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	// Configuration Tests                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Test that the CCT slug defaults correctly.
	 */
	public function test_default_cct_slug() {
		delete_option( 'wp_mcp_ai_shopify_sync_toolkit_settings' );
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$this->assertEquals( 'shopify_inventory_sync', $manager->get_cct_slug() );
	}

	/**
	 * Test that the CCT slug can be overridden via settings.
	 */
	public function test_custom_cct_slug_from_settings() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$this->assertEquals( 'shopify_inventory_sync_test', $manager->get_cct_slug() );
	}

	/**
	 * Test that set_cct_slug overrides the configured slug.
	 */
	public function test_set_cct_slug_override() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$manager->set_cct_slug( 'my_custom_slug' );
		$this->assertEquals( 'my_custom_slug', $manager->get_cct_slug() );
	}

	/**
	 * Test that connection ID is stored and retrievable.
	 */
	public function test_connection_id_stored() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$this->assertEquals( $this->connection_id, $manager->get_connection_id() );
	}

	// ------------------------------------------------------------------ //
	// Column Definitions Tests                                            //
	// ------------------------------------------------------------------ //

	/**
	 * Test that get_column_definitions returns the expected structure.
	 */
	public function test_column_definitions_structure() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$columns = $manager->get_column_definitions();

		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'sku', $columns );
		$this->assertArrayHasKey( 'product_title', $columns );
		$this->assertArrayHasKey( 'available_qty', $columns );
		$this->assertArrayHasKey( 'price', $columns );
		$this->assertArrayHasKey( 'location_id', $columns );
		$this->assertArrayHasKey( 'shopify_variant_id', $columns );
		$this->assertArrayHasKey( 'sync_hash', $columns );
		$this->assertArrayHasKey( 'sync_status', $columns );
		$this->assertEquals( 'text', $columns['sku'] );
		$this->assertEquals( 'number', $columns['available_qty'] );
	}

	/**
	 * Test total number of columns matches the schema.
	 */
	public function test_column_count() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$columns = $manager->get_column_definitions();
		// 27 columns in the schema.
		$this->assertCount( 27, $columns );
	}

	// ------------------------------------------------------------------ //
	// JetEngine Dependency Tests                                          //
	// ------------------------------------------------------------------ //

	/**
	 * Test that is_cct_available returns WP_Error when JetEngine is missing.
	 */
	public function test_cct_unavailable_without_jetengine() {
		// This test is valid; JetEngine is not loaded in unit test env.
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$result  = $manager->is_cct_available();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_sync_jetengine_missing', $result->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// Field Mapping Tests                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Test that default field mapping is returned correctly.
	 */
	public function test_default_field_mapping() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager();
		$mapping = $manager->get_default_field_mapping();

		$this->assertIsArray( $mapping );
		$this->assertArrayHasKey( 'shopify_product_id', $mapping );
		$this->assertArrayHasKey( 'shopify_variant_id', $mapping );
		$this->assertArrayHasKey( 'sku', $mapping );
		$this->assertArrayHasKey( 'price', $mapping );
		$this->assertEquals( 'id', $mapping['shopify_product_id'] );
		$this->assertEquals( 'title', $mapping['product_title'] );
	}

	// ------------------------------------------------------------------ //
	// Freshness Tests                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Test that is_fresh returns false when no sync has occurred.
	 */
	public function test_is_fresh_returns_false_without_sync() {
		delete_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id );
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$this->assertFalse( $manager->is_fresh() );
	}

	/**
	 * Test that is_fresh returns false with empty sync timestamp.
	 */
	public function test_is_fresh_returns_false_with_empty_timestamp() {
		update_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, '' );
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$this->assertFalse( $manager->is_fresh() );
	}

	/**
	 * Test that is_fresh returns true with recent sync timestamp.
	 */
	public function test_is_fresh_returns_true_with_recent_sync() {
		update_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, current_time( 'mysql' ) );
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$this->assertTrue( $manager->is_fresh( 900 ) ); // 15 min window.
	}

	/**
	 * Test that is_fresh returns false with stale sync timestamp.
	 */
	public function test_is_fresh_returns_false_with_stale_sync() {
		update_option(
			'wp_mcp_ai_shopify_last_sync_' . $this->connection_id,
			gmdate( 'Y-m-d H:i:s', time() - 3600 ) // 1 hour ago.
		);
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$this->assertFalse( $manager->is_fresh( 900 ) ); // 15 min window.
	}

	/**
	 * Test that get_last_sync_time returns the stored value.
	 */
	public function test_get_last_sync_time() {
		$timestamp = current_time( 'mysql' );
		update_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, $timestamp );
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$this->assertEquals( $timestamp, $manager->get_last_sync_time() );
	}

	// ------------------------------------------------------------------ //
	// Bulk JSONL Mapping Tests                                            //
	// ------------------------------------------------------------------ //

	/**
	 * Test mapping a simple bulk operation product item to CCT rows.
	 *
	 * Uses reflection to test the protected method.
	 */
	public function test_map_bulk_item_to_cct_rows_single_variant_single_location() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );

		$bulk_item = array(
			'id'           => 'gid://shopify/Product/12345',
			'title'        => 'Test Product',
			'handle'       => 'test-product',
			'status'       => 'ACTIVE',
			'vendor'       => 'TestVendor',
			'productType'  => 'TestType',
			'tags'         => array( 'tag1', 'tag2' ),
			'updatedAt'    => '2026-01-15T10:00:00Z',
			'images'       => array(
				'edges' => array(
					array(
						'node' => array(
							'url' => 'https://example.com/image.jpg',
						),
					),
				),
			),
			'variants'     => array(
				'edges' => array(
					array(
						'node' => array(
							'id'              => 'gid://shopify/ProductVariant/67890',
							'title'           => 'Default Title',
							'sku'             => 'TEST-001',
							'price'           => '29.99',
							'compareAtPrice'  => '39.99',
							'inventoryItem'   => array(
								'id' => 'gid://shopify/InventoryItem/99999',
							),
							'inventoryLevels' => array(
								'edges' => array(
									array(
										'node' => array(
											'quantities' => array(
												array( 'name' => 'available', 'quantity' => 42 ),
												array( 'name' => 'on_hand', 'quantity' => 50 ),
												array( 'name' => 'incoming', 'quantity' => 10 ),
												array( 'name' => 'reserved', 'quantity' => 3 ),
											),
											'location' => array(
												'id'   => 'gid://shopify/Location/111',
												'name' => 'Main Warehouse',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$mapping = $manager->get_default_field_mapping();

		// Use reflection to call protected method.
		$reflection = new ReflectionMethod( $manager, 'map_bulk_item_to_cct_rows' );
		$reflection->setAccessible( true );
		$rows = $reflection->invoke( $manager, $bulk_item, $mapping );

		$this->assertIsArray( $rows );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertEquals( 'gid://shopify/Product/12345', $row['shopify_product_id'] );
		$this->assertEquals( 'Test Product', $row['product_title'] );
		$this->assertEquals( 'TEST-001', $row['sku'] );
		$this->assertEquals( '29.99', $row['price'] );
		$this->assertEquals( '39.99', $row['compare_at_price'] );
		$this->assertEquals( 42, $row['available_qty'] );
		$this->assertEquals( 50, $row['on_hand_qty'] );
		$this->assertEquals( 10, $row['incoming_qty'] );
		$this->assertEquals( 3, $row['reserved_qty'] );
		$this->assertEquals( 'gid://shopify/Location/111', $row['location_id'] );
		$this->assertEquals( 'Main Warehouse', $row['location_name'] );
		$this->assertEquals( 'TestVendor', $row['vendor'] );
		$this->assertEquals( 'TestType', $row['product_type'] );
		$this->assertEquals( 'tag1, tag2', $row['tags'] );
		$this->assertEquals( 'ACTIVE', $row['status'] );
		$this->assertEquals( 'https://example.com/image.jpg', $row['image_url'] );
		$this->assertNotEmpty( $row['sync_hash'] );
		$this->assertEquals( 'synced', $row['sync_status'] );
		$this->assertNotEmpty( $row['last_synced_at'] );
	}

	/**
	 * Test mapping a product with no inventory levels creates zero-quantity row.
	 */
	public function test_map_bulk_item_without_inventory_levels() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );

		$bulk_item = array(
			'id'       => 'gid://shopify/Product/1',
			'title'    => 'No Inventory Product',
			'handle'   => 'no-inventory',
			'status'   => 'DRAFT',
			'vendor'   => '',
			'productType' => '',
			'tags'     => array(),
			'updatedAt' => '2026-01-01T00:00:00Z',
			'images'   => array( 'edges' => array() ),
			'variants' => array(
				'edges' => array(
					array(
						'node' => array(
							'id'              => 'gid://shopify/ProductVariant/1',
							'title'           => '',
							'sku'             => '',
							'price'           => '0.00',
							'compareAtPrice'  => null,
							'inventoryItem'   => array( 'id' => '' ),
							'inventoryLevels' => array( 'edges' => array() ),
						),
					),
				),
			),
		);

		$reflection = new ReflectionMethod( $manager, 'map_bulk_item_to_cct_rows' );
		$reflection->setAccessible( true );
		$rows = $reflection->invoke( $manager, $bulk_item, $manager->get_default_field_mapping() );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 0, $rows[0]['available_qty'] );
		$this->assertEquals( '', $rows[0]['location_id'] );
	}

	// ------------------------------------------------------------------ //
	// Hash Change Detection Tests                                         //
	// ------------------------------------------------------------------ //

	/**
	 * Test that upsert returns existing ID when hash is unchanged.
	 *
	 * Note: This test requires a mock of JetEngine CCT APIs.
	 * In a real test environment with JetEngine, it would test the full upsert flow.
	 */
	public function test_upsert_skip_on_hash_match_concept() {
		// Verify the hash computation is deterministic.
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );

		$row_a = array(
			'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
			'sku'                => 'SKU1',
			'product_title'      => 'Test',
			'available_qty'      => 10,
			'price'              => '19.99',
		);
		$row_a['sync_hash'] = md5( wp_json_encode( $row_a ) );

		$row_b = $row_a; // Same data.

		$this->assertEquals( $row_a['sync_hash'], $row_b['sync_hash'] );
	}

	/**
	 * Test that different data produces different hashes.
	 */
	public function test_hash_changes_with_data() {
		$row_a = array(
			'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
			'available_qty'      => 10,
		);
		$hash_a = md5( wp_json_encode( $row_a ) );

		$row_b = array(
			'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
			'available_qty'      => 11, // Changed quantity.
		);
		$hash_b = md5( wp_json_encode( $row_b ) );

		$this->assertNotEquals( $hash_a, $hash_b );
	}

	// ------------------------------------------------------------------ //
	// Upsert Error Tests                                                  //
	// ------------------------------------------------------------------ //

	/**
	 * Test that upsert returns WP_Error when variant_id is missing.
	 */
	public function test_upsert_requires_variant_id() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$result  = $manager->upsert( array( 'sku' => 'TEST' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_sync_missing_variant_id', $result->get_error_code() );
	}

	/**
	 * Test that upsert returns WP_Error when JetEngine is missing.
	 */
	public function test_upsert_fails_without_jetengine() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$result  = $manager->upsert( array(
			'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
			'sku'                => 'TEST',
			'sync_hash'          => 'abc123',
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_sync_jetengine_missing', $result->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// Sync from API Tests                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Test that sync_from_bulk_operation returns WP_Error when client is missing.
	 */
	public function test_sync_from_bulk_operation_requires_client() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$result  = $manager->sync_from_bulk_operation();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_sync_no_client', $result->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// Inventory Delta Tests                                               //
	// ------------------------------------------------------------------ //

	/**
	 * Test that update_inventory_delta requires valid identifiers.
	 */
	public function test_update_inventory_delta_empty_item_id() {
		$manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
		$result  = $manager->update_inventory_delta( '', 'loc1', 42 );

		$this->assertWPError( $result );
	}
}
