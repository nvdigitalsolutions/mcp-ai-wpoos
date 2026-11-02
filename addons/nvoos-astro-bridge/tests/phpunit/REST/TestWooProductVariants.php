<?php
/**
 * PHPUnit tests for the WooCommerce variable-product variants array
 * (Proposal 106 §1.1) — single-doc hydration, flat listings, and the
 * golden snapshot for the variant document shape.
 *
 * WooCommerce is loaded by the test environment as an optional plugin, so
 * variable products + variations are created directly through the CRUD
 * APIs (the TestWooProductSource convention).
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Sources\WooProductSource;
use NvoosAstroBridge\Sources\WooTaxonomy;

/**
 * Variants tests (Proposal 106 §1.1).
 *
 * @covers \NvoosAstroBridge\Sources\WooProductSource
 * @group nvoos-astro-bridge
 */
class TestWooProductVariants extends WP_Test_REST_TestCase {

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
	 * Create the golden variable product: two custom attributes (Size,
	 * Color) and two variations (on-sale + regular).
	 *
	 * @return int Parent product ID.
	 */
	private function create_variable_fixture(): int {
		$size = new WC_Product_Attribute();
		$size->set_id( 0 );
		$size->set_name( 'Size' );
		$size->set_options( array( '3-Seater', '2-Seater' ) );
		$size->set_position( 0 );
		$size->set_visible( true );
		$size->set_variation( true );

		$color = new WC_Product_Attribute();
		$color->set_id( 0 );
		$color->set_name( 'Color' );
		$color->set_options( array( 'Oak', 'Sage' ) );
		$color->set_position( 1 );
		$color->set_visible( true );
		$color->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Aurora Sofa' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $size, $color ) );
		$parent_id = $parent->save();

		$this->add_variation(
			$parent_id,
			'AUR-3S-OAK',
			'1499',
			'1299',
			4,
			array(
				'attribute_size'  => '3-Seater',
				'attribute_color' => 'Oak',
			)
		);
		$this->add_variation(
			$parent_id,
			'AUR-2S-SAGE',
			'999',
			'',
			0,
			array(
				'attribute_size'  => '2-Seater',
				'attribute_color' => 'Sage',
			)
		);

		return $parent_id;
	}

	/**
	 * Create one variation through the CRUD API.
	 *
	 * @param int                   $parent_id  Parent product ID.
	 * @param string                $sku        SKU.
	 * @param string                $regular    Regular price.
	 * @param string                $sale       Sale price ('' = not on sale).
	 * @param int                   $stock      Managed stock quantity.
	 * @param array<string, string> $attributes Variation attributes.
	 * @return int Variation ID.
	 */
	private function add_variation( int $parent_id, string $sku, string $regular, string $sale, int $stock, array $attributes ): int {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_sku( $sku );
		$variation->set_regular_price( $regular );
		if ( '' !== $sale ) {
			$variation->set_sale_price( $sale );
		}
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $stock );
		$variation->set_attributes( $attributes );
		return $variation->save();
	}

	/**
	 * Simple products never carry a `variants` key.
	 */
	public function test_simple_product_has_no_variants_key(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Lumen Armchair' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '299' );
		$product->save();

		$source = new WooProductSource();
		$doc    = $source->get_product( $product->get_id() );

		$this->assertNotNull( $doc );
		$this->assertArrayNotHasKey( 'variants', $doc );
	}

	/**
	 * Variable product single docs hydrate the §1.1 variants array, ordered
	 * by the parent's attribute order, with price/oldPrice/stock/image.
	 */
	public function test_variable_product_single_doc_has_variants(): void {
		$parent_id = $this->create_variable_fixture();

		$source = new WooProductSource();
		$doc    = $source->get_product( $parent_id );

		$this->assertNotNull( $doc );
		$this->assertArrayHasKey( 'variants', $doc );
		$this->assertCount( 2, $doc['variants'] );

		$first = $doc['variants'][0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertSame( 'AUR-3S-OAK', $first['sku'] );
		$this->assertSame( 1299.0, $first['price'] );
		$this->assertSame( 1499.0, $first['oldPrice'] );
		$this->assertSame( 4, $first['stock'] );
		$this->assertSame( '', $first['image'] );

		// Attributes follow the parent's get_attributes() order.
		$this->assertSame(
			array(
				array(
					'name'  => 'Size',
					'value' => '3-Seater',
				),
				array(
					'name'  => 'Color',
					'value' => 'Oak',
				),
			),
			$first['attributes']
		);

		$second = $doc['variants'][1];
		$this->assertSame( 'AUR-2S-SAGE', $second['sku'] );
		$this->assertSame( 999.0, $second['price'] );
		$this->assertNull( $second['oldPrice'] );
		$this->assertSame( 0, $second['stock'] );
		$this->assertSame( '2-Seater', $second['attributes'][0]['value'] );
	}

	/**
	 * Listings stay flat — no `variants` key on listing documents.
	 */
	public function test_listing_stays_flat(): void {
		$parent_id = $this->create_variable_fixture();

		$source = new WooProductSource();
		$result = $source->list_products( 1, 50, array(), null );

		$docs = $result['docs'];
		$this->assertNotEmpty( $docs );

		foreach ( $docs as $doc ) {
			$this->assertArrayNotHasKey( 'variants', $doc );
		}

		$ids = wp_list_pluck( $docs, 'id' );
		$this->assertContains( $parent_id, $ids );
	}

	/**
	 * The variable fixture matches the committed golden (masked fields).
	 */
	public function test_variable_product_matches_golden(): void {
		$parent_id = $this->create_variable_fixture();

		$source = new WooProductSource();
		$doc    = $source->get_product( $parent_id );

		$this->assertNotNull( $doc );
		$this->assertSame( 'Aurora Sofa', $doc['name'] );

		$golden = $this->load_golden( 'product-woo-variable.json' );

		$this->assertSame(
			$this->normalize_golden_variants( $golden['variants'] ),
			$this->mask_variants( $doc['variants'] )
		);
	}

	/**
	 * Normalize golden variant values to the transformer's runtime types.
	 *
	 * JSON round-trips drop float markers (1299.0 → 1299) while the
	 * transformer emits PHP floats — the same discipline as
	 * TestTransformerGolden::normalize_golden_fields().
	 *
	 * @param array<int, array<string, mixed>> $variants Golden variants.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_golden_variants( array $variants ): array {
		return array_map(
			static function ( array $variant ): array {
				foreach ( array( 'price', 'oldPrice' ) as $key ) {
					if ( isset( $variant[ $key ] ) && null !== $variant[ $key ] ) {
						$variant[ $key ] = (float) $variant[ $key ];
					}
				}
				if ( isset( $variant['stock'] ) && null !== $variant['stock'] ) {
					$variant['stock'] = (int) $variant['stock'];
				}
				return $variant;
			},
			$variants
		);
	}

	/**
	 * Mask volatile fields (variation ids) for the snapshot.
	 *
	 * @param array<int, array<string, mixed>> $variants Transformer output.
	 * @return array<int, array<string, mixed>>
	 */
	private function mask_variants( array $variants ): array {
		return array_map(
			static function ( array $variant ): array {
				return array(
					'sku'        => $variant['sku'],
					'price'      => (float) $variant['price'],
					'oldPrice'   => null === $variant['oldPrice'] ? null : (float) $variant['oldPrice'],
					'stock'      => $variant['stock'],
					'attributes' => $variant['attributes'],
					'image'      => $variant['image'],
				);
			},
			$variants
		);
	}

	/**
	 * Load a golden fixture file.
	 *
	 * @param string $file File name under tests/golden/.
	 * @return array<string, mixed>
	 */
	private function load_golden( string $file ): array {
		$path    = dirname( __DIR__, 2 ) . '/golden/' . $file;
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			$this->fail( "Golden fixture {$file} is not valid JSON." );
		}
		return $decoded;
	}
}
