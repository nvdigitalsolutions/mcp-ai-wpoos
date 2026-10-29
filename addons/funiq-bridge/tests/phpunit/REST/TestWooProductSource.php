<?php
/**
 * PHPUnit tests for the WooCommerce product source + controller routing.
 *
 * WooCommerce is loaded by the test environment as an optional plugin, so
 * the in-process CRUD APIs are available; products are created directly
 * through WC_Product_Simple (no admin context needed).
 *
 * @package FuniqBridge\Tests
 */

use FuniqBridge\Schema;
use FuniqBridge\Sources\ProductSources;
use FuniqBridge\Sources\WooTaxonomy;

/**
 * WooCommerce integration tests (source + REST routing + pseudo-collections).
 *
 * @covers \FuniqBridge\Sources\WooProductSource
 * @covers \FuniqBridge\Sources\ProductSources
 * @covers \FuniqBridge\Sources\WooTaxonomy
 * @covers \FuniqBridge\REST\ProductsController
 * @covers \FuniqBridge\REST\StatusesController
 * @group funiq-bridge
 */
class TestWooProductSource extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up: routes, admin user, woo mode via filter.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! WooTaxonomy::is_woo_available() ) {
			$this->markTestSkipped( 'WooCommerce is not loaded in this environment.' );
		}

		do_action( 'rest_api_init' );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );

		add_filter( 'funiq_bridge_products_source', static fn() => 'woo' );
	}

	/**
	 * Remove the mode filter.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'funiq_bridge_products_source' );
		parent::tearDown();
	}

	/**
	 * Create a Woo product directly through the CRUD API.
	 *
	 * @param string $name  Product name.
	 * @param string $price Price.
	 * @return int Product ID.
	 */
	private function create_woo_product( string $name, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->save();
		return $product->get_id();
	}

	// -----------------------------------------------------------------------
	// Source-level behaviour.
	// -----------------------------------------------------------------------

	/**
	 * Woo products map onto the Payload-shaped contract document.
	 */
	public function test_transform_maps_woo_fields(): void {
		$term    = wp_insert_term( 'Sofas', 'product_cat' );
		$term_id = (int) $term['term_id'];

		$product = new WC_Product_Simple();
		$product->set_name( 'Aurora Sofa' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '1299' );
		$product->set_sale_price( '999' );
		$product->set_description( 'A three-seater.' );
		$product->set_width( '220' );
		$product->set_height( '80' );
		$product->set_length( '90' );
		$product->set_featured( true );
		$product->set_category_ids( array( $term_id ) );
		$product->save();

		$source = new \FuniqBridge\Sources\WooProductSource();
		$doc    = $source->get_product( $product->get_id() );

		$this->assertNotNull( $doc );
		$this->assertSame( 'Aurora Sofa', $doc['name'] );
		$this->assertSame( 999.0, $doc['price'] );
		$this->assertSame( 1299.0, $doc['oldPrice'] );
		$this->assertSame( 220.0, $doc['width'] );
		$this->assertSame( 'A three-seater.', $doc['description'] );
		$this->assertTrue( $doc['isFeatured'] );
		$this->assertNull( $doc['promotion'] );
		$this->assertSame( Schema::SOURCE_WOO, $doc['source'] );
		$this->assertSame( $term_id, $doc['category']['id'] );
		$this->assertNotEmpty( $doc['statuses'] );
		$this->assertSame( 'In stock', $doc['statuses'][0]['name'] );
	}

	/**
	 * list_products returns published Woo products with correct totals.
	 */
	public function test_list_products_returns_publish_products(): void {
		$id_a = $this->create_woo_product( 'Alpha', '10' );
		$id_b = $this->create_woo_product( 'Beta', '20' );

		// Draft products must not appear (publish-only contract).
		$draft = new WC_Product_Simple();
		$draft->set_name( 'Hidden' );
		$draft->set_status( 'draft' );
		$draft->set_regular_price( '5' );
		$draft->save();

		$source = new \FuniqBridge\Sources\WooProductSource();
		$result = $source->list_products( 1, 10, array(), null );

		$ids = wp_list_pluck( $result['docs'], 'id' );
		$this->assertContains( $id_a, $ids );
		$this->assertContains( $id_b, $ids );
		$this->assertNotContains( $draft->get_id(), $ids );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
	}

	/**
	 * where name.like searches, price.gte filters, and sort price orders.
	 */
	public function test_where_and_sort(): void {
		$this->create_woo_product( 'Aurora Sofa', '999' );
		$this->create_woo_product( 'Lumen Armchair', '299' );

		$source = new \FuniqBridge\Sources\WooProductSource();

		$searched = $source->list_products(
			1,
			10,
			array( 'name' => array( 'like' => 'Aurora' ) ),
			null
		);
		$this->assertCount( 1, $searched['docs'] );
		$this->assertSame( 'Aurora Sofa', $searched['docs'][0]['name'] );

		$priced = $source->list_products(
			1,
			10,
			array( 'price' => array( 'gte' => 500 ) ),
			null
		);
		$this->assertCount( 1, $priced['docs'] );
		$this->assertSame( 'Aurora Sofa', $priced['docs'][0]['name'] );

		// Bare `price` sorts DESC per the contract convention; `-price` flips
		// to ascending.
		$sorted = $source->list_products( 1, 10, array(), '-price' );
		$prices = wp_list_pluck( $sorted['docs'], 'price' );
		$this->assertSame( array( 299.0, 999.0 ), $prices );
	}

	/**
	 * create_product writes a Woo product with sale-price mapping.
	 */
	public function test_create_product_maps_prices(): void {
		$source = new \FuniqBridge\Sources\WooProductSource();
		$doc    = $source->create_product(
			array(
				'name'     => 'Terra Table',
				'price'    => 199,
				'oldPrice' => 259,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $doc );
		$product = wc_get_product( $doc['id'] );
		$this->assertSame( '259', $product->get_regular_price() );
		$this->assertSame( '199', $product->get_sale_price() );
		$this->assertSame( Schema::SOURCE_WOO, $doc['source'] );
	}

	/**
	 * update_product touches only provided fields; delete removes the product.
	 */
	public function test_update_and_delete(): void {
		$id     = $this->create_woo_product( 'Drift Bookcase', '700' );
		$source = new \FuniqBridge\Sources\WooProductSource();

		$updated = $source->update_product( $id, array( 'price' => 650 ) );
		$this->assertSame( 650.0, $updated['price'] );
		$product = wc_get_product( $id );
		$this->assertSame( 'Drift Bookcase', $product->get_name() );

		$this->assertTrue( $source->delete_product( $id ) );
		$this->assertFalse( wc_get_product( $id ) );
	}

	// -----------------------------------------------------------------------
	// Registry + controller routing.
	// -----------------------------------------------------------------------

	/**
	 * In woo mode the controller serves Woo products end to end.
	 */
	public function test_controller_routes_to_woo_in_woo_mode(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/products' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'name'  => 'Cove Unit',
					'price' => 899,
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( Schema::SOURCE_WOO, $data['source'] );
		$this->assertSame( 'product', get_post_type( $data['id'] ) );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products/' . $data['id'] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'Cove Unit', $response->get_data()['name'] );

		$request  = new WP_REST_Request( 'DELETE', '/nvoos-commerce/v1/products/' . $data['id'] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 204, $response->get_status() );
	}

	/**
	 * In both mode the listing merges native + Woo products.
	 */
	public function test_both_mode_merges_sources(): void {
		remove_all_filters( 'funiq_bridge_products_source' );
		add_filter( 'funiq_bridge_products_source', static fn() => 'both' );

		$native_id = wp_insert_post(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Native Lamp',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $native_id, Schema::META_PRICE, '49' );

		$woo_id = $this->create_woo_product( 'Woo Lamp', '59' );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$ids = wp_list_pluck( $data['docs'], 'id' );
		$this->assertContains( $native_id, $ids );
		$this->assertContains( $woo_id, $ids );

		$sources = wp_list_pluck( $data['docs'], 'source' );
		$this->assertContains( Schema::SOURCE_NATIVE, $sources );
		$this->assertContains( Schema::SOURCE_WOO, $sources );
	}

	/**
	 * The statuses collection becomes the stock-status pseudo-collection in
	 * woo mode and rejects writes.
	 */
	public function test_statuses_pseudo_collection_in_woo_mode(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/statuses' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$names = wp_list_pluck( $data['docs'], 'name' );
		$this->assertContains( 'In stock', $names );
		$this->assertContains( 'Out of stock', $names );
		$this->assertContains( 'On backorder', $names );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/statuses' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'name' => 'Nope' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 405, $response->get_status() );
	}

	/**
	 * The categories collection serves product_cat in woo mode.
	 */
	public function test_categories_serve_product_cat_in_woo_mode(): void {
		$term    = wp_insert_term( 'Chairs', 'product_cat' );
		$term_id = (int) $term['term_id'];

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/categories' );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( $response->get_data()['docs'], 'id' );
		$this->assertContains( $term_id, $ids );
	}

	/**
	 * Default mode stays native — woo products are invisible unless opted in.
	 */
	public function test_default_mode_is_native(): void {
		remove_all_filters( 'funiq_bridge_products_source' );
		$this->assertSame( 'native', ProductSources::mode() );
		$this->assertCount( 1, ProductSources::active() );
		$this->assertSame( Schema::SOURCE_NATIVE, ProductSources::active()[0]->name() );
	}
}
