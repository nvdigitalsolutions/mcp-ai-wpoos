<?php
/**
 * Tests for Remote Connection Tool - Image Optimization
 *
 * Tests the image optimization functionality to reduce token usage.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Remote Connection Tool Image Optimization.
 */
class Test_Remote_Connection_Tool_Image_Optimization extends WP_UnitTestCase {

	/**
	 * Remote connection tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Remote_WP_Connection
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php';

		$this->tool = new WP_MCP_AI_Tool_Remote_WP_Connection();
	}

	/**
	 * Test optimize_product_images method with full image data.
	 *
	 * Uses reflection to test protected method.
	 */
	public function test_optimize_product_images_basic() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id'     => 1,
				'name'   => 'Test Product',
				'images' => array(
					(object) array(
						'id'                => 101,
						'date_created'      => '2024-01-01T00:00:00',
						'date_created_gmt'  => '2024-01-01T00:00:00',
						'date_modified'     => '2024-01-01T00:00:00',
						'date_modified_gmt' => '2024-01-01T00:00:00',
						'src'               => 'https://example.com/image1.jpg',
						'name'              => 'Image 1',
						'alt'               => 'Alt text 1',
					),
					(object) array(
						'id'                => 102,
						'date_created'      => '2024-01-02T00:00:00',
						'date_created_gmt'  => '2024-01-02T00:00:00',
						'date_modified'     => '2024-01-02T00:00:00',
						'date_modified_gmt' => '2024-01-02T00:00:00',
						'src'               => 'https://example.com/image2.jpg',
						'name'              => 'Image 2',
						'alt'               => 'Alt text 2',
					),
				),
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertIsArray( $result[0]->images );
		$this->assertCount( 2, $result[0]->images );

		// Check that only src and alt remain.
		$image = $result[0]->images[0];
		$this->assertIsObject( $image );
		$this->assertObjectHasProperty( 'src', $image );
		$this->assertObjectHasProperty( 'alt', $image );
		$this->assertEquals( 'https://example.com/image1.jpg', $image->src );
		$this->assertEquals( 'Alt text 1', $image->alt );

		// Check that verbose fields are removed.
		$this->assertObjectNotHasProperty( 'id', $image );
		$this->assertObjectNotHasProperty( 'date_created', $image );
		$this->assertObjectNotHasProperty( 'date_modified', $image );
		$this->assertObjectNotHasProperty( 'name', $image );
	}

	/**
	 * Test optimize_product_images limits to 3 images.
	 */
	public function test_optimize_product_images_limit_to_three() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$images = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$images[] = (object) array(
				'id'  => 100 + $i,
				'src' => 'https://example.com/image' . $i . '.jpg',
				'alt' => 'Alt text ' . $i,
			);
		}

		$products = array(
			(object) array(
				'id'     => 1,
				'name'   => 'Test Product',
				'images' => $images,
			),
		);

		$result = $method->invoke( $this->tool, $products );

		// Should only keep first 3 images.
		$this->assertCount( 3, $result[0]->images );
		$this->assertEquals( 'https://example.com/image1.jpg', $result[0]->images[0]->src );
		$this->assertEquals( 'https://example.com/image2.jpg', $result[0]->images[1]->src );
		$this->assertEquals( 'https://example.com/image3.jpg', $result[0]->images[2]->src );
	}

	/**
	 * Test optimize_product_images with array format images.
	 */
	public function test_optimize_product_images_array_format() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id'     => 1,
				'name'   => 'Test Product',
				'images' => array(
					array(
						'id'           => 101,
						'src'          => 'https://example.com/image1.jpg',
						'alt'          => 'Alt text 1',
						'date_created' => '2024-01-01',
					),
				),
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$this->assertIsArray( $result[0]->images );
		$this->assertCount( 1, $result[0]->images );
		
		$image = $result[0]->images[0];
		$this->assertIsArray( $image );
		$this->assertArrayHasKey( 'src', $image );
		$this->assertArrayHasKey( 'alt', $image );
		$this->assertArrayNotHasKey( 'id', $image );
		$this->assertArrayNotHasKey( 'date_created', $image );
	}

	/**
	 * Test optimize_product_images with single image field (variations).
	 */
	public function test_optimize_product_images_single_image() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$variations = array(
			(object) array(
				'id'    => 1,
				'sku'   => 'VAR-001',
				'image' => (object) array(
					'id'                => 201,
					'date_created'      => '2024-01-01T00:00:00',
					'src'               => 'https://example.com/variation1.jpg',
					'alt'               => 'Variation alt',
					'name'              => 'Variation Image',
				),
			),
		);

		$result = $method->invoke( $this->tool, $variations );

		$this->assertIsObject( $result[0]->image );
		$this->assertObjectHasProperty( 'src', $result[0]->image );
		$this->assertObjectHasProperty( 'alt', $result[0]->image );
		$this->assertEquals( 'https://example.com/variation1.jpg', $result[0]->image->src );
		$this->assertEquals( 'Variation alt', $result[0]->image->alt );

		// Check that verbose fields are removed.
		$this->assertObjectNotHasProperty( 'id', $result[0]->image );
		$this->assertObjectNotHasProperty( 'date_created', $result[0]->image );
		$this->assertObjectNotHasProperty( 'name', $result[0]->image );
	}

	/**
	 * Test optimize_product_images with no images.
	 */
	public function test_optimize_product_images_no_images() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id'   => 1,
				'name' => 'Test Product',
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertObjectNotHasProperty( 'images', $result[0] );
	}

	/**
	 * Test optimize_product_images with empty images array.
	 */
	public function test_optimize_product_images_empty_images() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id'     => 1,
				'name'   => 'Test Product',
				'images' => array(),
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$this->assertIsArray( $result[0]->images );
		$this->assertEmpty( $result[0]->images );
	}

	/**
	 * Test optimize_product_images with non-array input.
	 */
	public function test_optimize_product_images_non_array() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, null );

		$this->assertNull( $result );
	}

	/**
	 * Test optimize_product_images with missing alt text.
	 */
	public function test_optimize_product_images_missing_alt() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'optimize_product_images' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id'     => 1,
				'name'   => 'Test Product',
				'images' => array(
					(object) array(
						'id'  => 101,
						'src' => 'https://example.com/image1.jpg',
						// No alt field.
					),
				),
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$image = $result[0]->images[0];
		$this->assertObjectHasProperty( 'alt', $image );
		$this->assertEquals( '', $image->alt ); // Should default to empty string.
	}
}
