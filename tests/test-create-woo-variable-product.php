<?php
/**
 * Tests for WooCommerce variable product creation.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product.php';

/**
 * Test case for variable product creation with variations.
 */
class WP_MCP_AI_Create_Woo_Variable_Product_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		// Clean up any created products.
		$products = wc_get_products( array( 'limit' => -1 ) );
		foreach ( $products as $product ) {
			if ( $product->get_meta( '_test_product' ) === 'yes' ) {
				$product->delete( true );
			}
		}

		parent::tearDown();
	}

	/**
	 * Test that variable product type is supported in schema.
	 */
	public function test_schema_supports_variable_product_type() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'product_type', $schema['properties'] );
		$this->assertContains( 'variable', $schema['properties']['product_type']['enum'] );
	}

	/**
	 * Test that attributes schema includes variation flag.
	 */
	public function test_schema_attributes_include_variation_flag() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'attributes', $schema['properties'] );
		$this->assertArrayHasKey( 'properties', $schema['properties']['attributes']['items'] );
		$this->assertArrayHasKey( 'variation', $schema['properties']['attributes']['items']['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['attributes']['items']['properties']['variation']['type'] );
	}

	/**
	 * Test that variations parameter exists in schema.
	 */
	public function test_schema_includes_variations_parameter() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'variations', $schema['properties'] );
		$this->assertSame( 'array', $schema['properties']['variations']['type'] );
		$this->assertArrayHasKey( 'attributes', $schema['properties']['variations']['items']['properties'] );
		$this->assertArrayHasKey( 'regular_price', $schema['properties']['variations']['items']['properties'] );
		$this->assertArrayHasKey( 'sku', $schema['properties']['variations']['items']['properties'] );
	}

	/**
	 * Test creating a simple variable product with attributes marked for variations.
	 */
	public function test_create_variable_product_with_variation_attributes() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-VAR-001',
				'product_type' => 'variable',
				'title'        => 'Test Variable Product',
				'attributes'   => array(
					array(
						'name'      => 'Size',
						'options'   => array( 'Small', 'Medium', 'Large' ),
						'visible'   => true,
						'variation' => true,
					),
					array(
						'name'      => 'Color',
						'options'   => array( 'Red', 'Blue', 'Green' ),
						'visible'   => true,
						'variation' => true,
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'product_id', $result );
		$this->assertSame( 'variable', $result['product_type'] );

		// Mark product for cleanup.
		update_post_meta( $result['product_id'], '_test_product', 'yes' );

		// Verify attributes are set correctly.
		$product = wc_get_product( $result['product_id'] );
		$this->assertInstanceOf( 'WC_Product_Variable', $product );

		$attributes = $product->get_attributes();
		$this->assertNotEmpty( $attributes );

		// Check that at least one attribute is marked for variations.
		$has_variation_attribute = false;
		foreach ( $attributes as $attribute ) {
			if ( $attribute->get_variation() ) {
				$has_variation_attribute = true;
				break;
			}
		}
		$this->assertTrue( $has_variation_attribute, 'At least one attribute should be marked for variations' );
	}

	/**
	 * Test creating variable product with variations.
	 */
	public function test_create_variable_product_with_variations() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-VAR-002',
				'product_type' => 'variable',
				'title'        => 'Test T-Shirt',
				'attributes'   => array(
					array(
						'name'      => 'Size',
						'options'   => array( 'Small', 'Medium', 'Large' ),
						'visible'   => true,
						'variation' => true,
					),
					array(
						'name'      => 'Color',
						'options'   => array( 'Red', 'Blue' ),
						'visible'   => true,
						'variation' => true,
					),
				),
				'variations'   => array(
					array(
						'attributes'    => array(
							'Size'  => 'Small',
							'Color' => 'Red',
						),
						'sku'           => 'TSHIRT-S-RED',
						'regular_price' => '19.99',
					),
					array(
						'attributes'    => array(
							'Size'  => 'Medium',
							'Color' => 'Blue',
						),
						'sku'           => 'TSHIRT-M-BLUE',
						'regular_price' => '21.99',
						'sale_price'    => '18.99',
					),
					array(
						'attributes'    => array(
							'Size'  => 'Large',
							'Color' => 'Red',
						),
						'sku'           => 'TSHIRT-L-RED',
						'regular_price' => '23.99',
						'stock_status'  => 'instock',
						'manage_stock'  => true,
						'stock_quantity' => 50,
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'product_id', $result );

		// Mark product for cleanup.
		update_post_meta( $result['product_id'], '_test_product', 'yes' );

		// Verify variations were created.
		$product = wc_get_product( $result['product_id'] );
		$this->assertInstanceOf( 'WC_Product_Variable', $product );

		$variations = $product->get_children();
		$this->assertCount( 3, $variations, 'Should have created 3 variations' );

		// Check first variation details.
		$variation1 = wc_get_product( $variations[0] );
		$this->assertInstanceOf( 'WC_Product_Variation', $variation1 );
		$this->assertSame( 'TSHIRT-S-RED', $variation1->get_sku() );
		$this->assertSame( '19.99', $variation1->get_regular_price() );

		// Check second variation with sale price.
		$variation2 = wc_get_product( $variations[1] );
		$this->assertSame( 'TSHIRT-M-BLUE', $variation2->get_sku() );
		$this->assertSame( '21.99', $variation2->get_regular_price() );
		$this->assertSame( '18.99', $variation2->get_sale_price() );

		// Check third variation with stock management.
		$variation3 = wc_get_product( $variations[2] );
		$this->assertSame( 'TSHIRT-L-RED', $variation3->get_sku() );
		$this->assertTrue( $variation3->managing_stock() );
		$this->assertSame( 50, $variation3->get_stock_quantity() );
	}

	/**
	 * Test that simple products don't have variation attributes.
	 */
	public function test_simple_product_attributes_not_marked_for_variations() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-SIMPLE-001',
				'product_type' => 'simple',
				'title'        => 'Test Simple Product',
				'local_price'  => '29.99',
				'attributes'   => array(
					array(
						'name'      => 'Material',
						'options'   => array( 'Cotton', 'Polyester' ),
						'visible'   => true,
						'variation' => true, // Should be ignored for simple products.
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'product_id', $result );

		// Mark product for cleanup.
		update_post_meta( $result['product_id'], '_test_product', 'yes' );

		// Verify attributes are NOT marked for variations.
		$product = wc_get_product( $result['product_id'] );
		$this->assertInstanceOf( 'WC_Product_Simple', $product );

		$attributes = $product->get_attributes();
		foreach ( $attributes as $attribute ) {
			$this->assertFalse( $attribute->get_variation(), 'Simple product attributes should not be marked for variations' );
		}
	}

	/**
	 * Test that variations without required price are skipped.
	 */
	public function test_variations_without_price_are_skipped() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-VAR-003',
				'product_type' => 'variable',
				'title'        => 'Test Product',
				'attributes'   => array(
					array(
						'name'      => 'Size',
						'options'   => array( 'Small', 'Large' ),
						'visible'   => true,
						'variation' => true,
					),
				),
				'variations'   => array(
					array(
						'attributes'    => array( 'Size' => 'Small' ),
						'regular_price' => '19.99',
						'sku'           => 'TEST-SMALL',
					),
					array(
						'attributes' => array( 'Size' => 'Large' ),
						// Missing regular_price - should be skipped.
						'sku'        => 'TEST-LARGE',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'product_id', $result );

		// Mark product for cleanup.
		update_post_meta( $result['product_id'], '_test_product', 'yes' );

		// Verify only one variation was created.
		$product    = wc_get_product( $result['product_id'] );
		$variations = $product->get_children();
		$this->assertCount( 1, $variations, 'Should have created only 1 variation (second was skipped)' );
	}

	/**
	 * Test that global attributes are reused when they exist.
	 */
	public function test_global_attributes_are_reused() {
		if ( ! WP_MCP_AI_Tool_Create_Woo_Product::is_available() ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		// Create a global attribute first.
		$attribute_id = wc_create_attribute(
			array(
				'name'         => 'Test Color',
				'slug'         => 'testcolor',
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $attribute_id ) ) {
			$this->markTestSkipped( 'Could not create test attribute' );
		}

		// Register the taxonomy.
		register_taxonomy( 'pa_testcolor', array( 'product' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
		$result = $tool->execute(
			array(
				'reference'    => 'TEST-VAR-004',
				'product_type' => 'variable',
				'title'        => 'Test Product with Global Attribute',
				'attributes'   => array(
					array(
						'name'      => 'TestColor',
						'options'   => array( 'Red', 'Blue' ),
						'visible'   => true,
						'variation' => true,
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'product_id', $result );

		// Mark product for cleanup.
		update_post_meta( $result['product_id'], '_test_product', 'yes' );

		// Verify the global attribute was used.
		$product    = wc_get_product( $result['product_id'] );
		$attributes = $product->get_attributes();

		$found_global_attr = false;
		foreach ( $attributes as $attribute ) {
			if ( $attribute->is_taxonomy() && $attribute->get_name() === 'pa_testcolor' ) {
				$found_global_attr = true;
				break;
			}
		}

		$this->assertTrue( $found_global_attr, 'Should use global attribute when it exists' );

		// Clean up the global attribute.
		wc_delete_attribute( $attribute_id );
	}
}
