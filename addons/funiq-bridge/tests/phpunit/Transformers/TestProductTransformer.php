<?php
/**
 * PHPUnit test for ProductTransformer.
 *
 * @package FuniqBridge\Tests
 */

use FuniqBridge\Transformers\ProductTransformer;
use FuniqBridge\Schema;

/**
 * @covers \FuniqBridge\Transformers\ProductTransformer
 * @group funiq-bridge
 */
class TestProductTransformer extends WP_UnitTestCase {

	/** @var ProductTransformer */
	private $transformer;

	public function setUp(): void {
		parent::setUp();
		$this->transformer = new ProductTransformer();
	}

	/**
	 * Transform produces the correct keys.
	 */
	public function test_transform_has_expected_keys(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => Schema::CPT_PRODUCT,
				'post_title' => 'Test Product',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, Schema::META_PRICE, 29.99 );

		$result = $this->transformer->transform( get_post( $post_id ) );

		$expected_keys = array(
			'id', 'name', 'price', 'oldPrice', 'description',
			'category', 'brand', 'colors', 'width', 'height', 'depth',
			'rating', 'isBestseller', 'isFeatured', 'image', 'images',
			'statuses', 'promotion', 'createdAt', 'updatedAt',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing key: $key" );
		}
	}

	/**
	 * Price meta is cast to float.
	 */
	public function test_price_is_float(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => Schema::CPT_PRODUCT,
				'post_title' => 'Priced Product',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, Schema::META_PRICE, '19.99' );

		$result = $this->transformer->transform( get_post( $post_id ) );

		$this->assertIsFloat( $result['price'] );
		$this->assertEquals( 19.99, $result['price'] );
	}

	/**
	 * Boolean meta is cast properly.
	 */
	public function test_boolean_meta_is_bool(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => Schema::CPT_PRODUCT,
				'post_title' => 'Featured Product',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, Schema::META_IS_FEATURED, '1' );

		$result = $this->transformer->transform( get_post( $post_id ) );

		$this->assertTrue( $result['isFeatured'] );
		$this->assertFalse( $result['isBestseller'] );
	}
}
