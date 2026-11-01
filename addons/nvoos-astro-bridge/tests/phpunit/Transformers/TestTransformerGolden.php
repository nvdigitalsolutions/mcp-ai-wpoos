<?php
/**
 * Golden snapshot test — the bridge side of the Proposal 092 Phase 2.7
 * contract drift guard.
 *
 * Creates a product with known fixture data and asserts the transformer's
 * output matches the committed golden files. If a field is renamed,
 * re-shaped, or dropped, this test fails BEFORE the drift reaches the
 * storefront fixtures. The companion generator
 * (examples/nvoos-astro-velocity/scripts/sync-bridge-fixtures.mjs)
 * propagates the goldens into the Astro app's tests/fixtures/, and CI
 * diffs the regenerated files against the committed ones.
 *
 * Volatile fields (ids, media URLs, timestamps) are masked — the snapshot
 * pins the contract shape and values, not database identity.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Transformers\ProductTransformer;

/**
 * Transformer golden tests.
 *
 * @covers \NvoosAstroBridge\Transformers\ProductTransformer
 * @group nvoos-astro-bridge
 */
class TestTransformerGolden extends WP_UnitTestCase {

	/**
	 * The transformer output matches the committed golden (masked fields).
	 */
	public function test_aurora_matches_golden(): void {
		$plugin = \NvoosAstroBridge\Plugin::instance();
		if ( ! post_type_exists( Schema::CPT_PRODUCT ) ) {
			$plugin->register_post_types();
			$plugin->register_taxonomies();
		}

		$golden = $this->load_golden( 'product-aurora.json' );
		$fields = $this->normalize_golden_fields( $golden['fields'] );

		// Terms.
		$category = wp_insert_term( $fields['category']['name'], Schema::TAX_CATEGORY );
		$brand    = wp_insert_term( $fields['brand']['name'], Schema::TAX_BRAND );
		$status   = wp_insert_term( $fields['statuses'][0]['name'], Schema::TAX_STATUS );
		$color_ids = array();
		foreach ( $fields['colors'] as $color ) {
			$result = wp_insert_term( $color['name'], Schema::TAX_COLOR );
			update_term_meta( $result['term_id'], Schema::TERM_META_HEX_CODE, $color['hexCode'] );
			$color_ids[] = (int) $result['term_id'];
		}

		// Promotion (embedded reference).
		$promotion_id = wp_insert_post(
			array(
				'post_type'    => Schema::CPT_PROMOTION,
				'post_title'   => $fields['promotion']['title'],
				'post_content' => $fields['promotion']['description'],
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $promotion_id, Schema::META_PROMO_START_DATE, $fields['promotion']['startDate'] );
		update_post_meta( $promotion_id, Schema::META_PROMO_END_DATE, $fields['promotion']['endDate'] );
		update_post_meta( $promotion_id, Schema::META_PROMO_ACTIVE, $fields['promotion']['active'] );

		// Product.
		$product_id = wp_insert_post(
			array(
				'post_type'    => Schema::CPT_PRODUCT,
				'post_title'   => $fields['name'],
				'post_content' => $fields['description'],
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $product_id, Schema::META_PRICE, $fields['price'] );
		update_post_meta( $product_id, Schema::META_OLD_PRICE, $fields['oldPrice'] );
		update_post_meta( $product_id, Schema::META_WIDTH, $fields['width'] );
		update_post_meta( $product_id, Schema::META_HEIGHT, $fields['height'] );
		update_post_meta( $product_id, Schema::META_DEPTH, $fields['depth'] );
		update_post_meta( $product_id, Schema::META_RATING, $fields['rating'] );
		update_post_meta( $product_id, Schema::META_IS_BESTSELLER, $fields['isBestseller'] );
		update_post_meta( $product_id, Schema::META_IS_FEATURED, $fields['isFeatured'] );
		update_post_meta( $product_id, Schema::META_PROMOTION_ID, $promotion_id );

		wp_set_object_terms( $product_id, array( (int) $category['term_id'] ), Schema::TAX_CATEGORY );
		wp_set_object_terms( $product_id, array( (int) $brand['term_id'] ), Schema::TAX_BRAND );
		wp_set_object_terms( $product_id, array( (int) $status['term_id'] ), Schema::TAX_STATUS );
		wp_set_object_terms( $product_id, $color_ids, Schema::TAX_COLOR );

		$output = ( new ProductTransformer() )->transform( get_post( $product_id ) );

		$this->assertSame( $fields, $this->mask( $output ) );
	}

	/**
	 * Mask volatile fields (ids, URLs, timestamps) for the snapshot.
	 *
	 * @param array<string, mixed> $output Transformer output.
	 * @return array<string, mixed>
	 */
	private function mask( array $output ): array {
		return array(
			'name'         => $output['name'],
			'price'        => $output['price'],
			'oldPrice'     => $output['oldPrice'],
			'description'  => $output['description'],
			'category'     => array( 'name' => $output['category']['name'] ),
			'brand'        => array( 'name' => $output['brand']['name'] ),
			'colors'       => array_map(
				static function ( array $color ): array {
					return array(
						'name'    => $color['name'],
						'hexCode' => $color['hexCode'],
					);
				},
				$output['colors']
			),
			'width'        => $output['width'],
			'height'       => $output['height'],
			'depth'        => $output['depth'],
			'rating'       => $output['rating'],
			'isBestseller' => $output['isBestseller'],
			'isFeatured'   => $output['isFeatured'],
			'statuses'     => array_map(
				static function ( array $status ): array {
					return array( 'name' => $status['name'] );
				},
				$output['statuses']
			),
			'promotion'    => array(
				'title'       => $output['promotion']['title'],
				'description' => $output['promotion']['description'],
				'startDate'   => $output['promotion']['startDate'],
				'endDate'     => $output['promotion']['endDate'],
				'active'      => $output['promotion']['active'],
			),
			'status'       => $output['status'],
		);
	}

	/**
	 * Load a golden fixture file.
	 *
	 * @param string $file File name under tests/golden/.
	 * @return array{fixtureId: int, createdAt: string, updatedAt: string, media: array<string, mixed>, fields: array<string, mixed>}
	 */
	private function load_golden( string $file ): array {
		$path    = dirname( __DIR__, 2 ) . '/golden/' . $file;
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			$this->fail( "Golden fixture {$file} is not valid JSON." );
		}
		return $decoded;
	}

	/**
	 * Normalize golden field values to the transformer's runtime types.
	 *
	 * JSON round-trips drop float markers (1299.0 → 1299) while the
	 * transformer emits PHP floats from floatval(); term order comes back
	 * alphabetical from get_terms() regardless of insertion order. The
	 * snapshot therefore normalizes both sides to floats + name order.
	 *
	 * @param array<string, mixed> $fields Golden fields.
	 * @return array<string, mixed>
	 */
	private function normalize_golden_fields( array $fields ): array {
		foreach ( array( 'price', 'oldPrice', 'width', 'height', 'depth', 'rating' ) as $key ) {
			if ( isset( $fields[ $key ] ) && null !== $fields[ $key ] ) {
				$fields[ $key ] = (float) $fields[ $key ];
			}
		}
		foreach ( array( 'colors', 'statuses' ) as $key ) {
			if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ) {
				usort(
					$fields[ $key ],
					static function ( array $a, array $b ): int {
						return strcmp( (string) $a['name'], (string) $b['name'] );
					}
				);
			}
		}
		return $fields;
	}
}
