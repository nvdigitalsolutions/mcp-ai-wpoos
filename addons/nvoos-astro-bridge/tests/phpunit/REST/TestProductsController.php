<?php
/**
 * PHPUnit test for ProductsController.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\REST\ProductsController;
use NvoosAstroBridge\Schema;

/**
 * @covers \NvoosAstroBridge\REST\ProductsController
 * @group nvoos-astro-bridge
 */
class TestProductsController extends WP_Test_REST_TestCase {

	/** @var int */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();

		// Register the bridge CPTs/taxonomies directly — re-firing
		// do_action('init') re-registers WooCommerce blocks/integrations and
		// fails every test with "already registered" notices (test-suite
		// skill pattern #2). REST routes register on the rest_api_init fire.
		$plugin = \NvoosAstroBridge\Plugin::instance();
		if ( ! post_type_exists( Schema::CPT_PRODUCT ) ) {
			$plugin->register_post_types();
			$plugin->register_taxonomies();
		}
		do_action( 'rest_api_init' );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * GET /funiq/v1/products returns Payload-paginated format.
	 */
	public function test_get_items_returns_payload_format(): void {
		$request  = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'docs', $data );
		$this->assertArrayHasKey( 'totalDocs', $data );
		$this->assertArrayHasKey( 'totalPages', $data );
		$this->assertArrayHasKey( 'hasNextPage', $data );
		$this->assertArrayHasKey( 'hasPrevPage', $data );
	}

	/**
	 * where[id][equals] returns a single product.
	 */
	public function test_where_id_equals_query(): void {
		$pid = $this->factory->post->create(
			array(
				'post_type'  => Schema::CPT_PRODUCT,
				'post_title' => 'Test Product',
				'post_status' => 'publish',
			)
		);

		$request = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$request->set_query_params(
			array(
				'where' => array( 'id' => array( 'equals' => $pid ) ),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['docs'] );
		$this->assertEquals( $pid, $data['docs'][0]['id'] );
		$this->assertEquals( 'Test Product', $data['docs'][0]['name'] );
	}

	/**
	 * Unauthenticated users cannot create products.
	 */
	public function test_unauthenticated_cannot_create(): void {
		$request = new WP_REST_Request( 'POST', '/funiq/v1/products' );
		$request->set_body_params( array( 'name' => 'Unauthorized' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * Authenticated admin can create a product.
	 */
	public function test_admin_can_create_product(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/funiq/v1/products' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'name'        => 'New Product',
					'price'       => 19.99,
					'description' => 'A test product',
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'New Product', $data['name'] );
		$this->assertEquals( 19.99, $data['price'] );
	}

	/**
	 * The promotion relationship object is persisted as its ID — never the
	 * intval() coercion of the array (which yields 1 for any non-empty
	 * array, silently pointing every product at post 1).
	 */
	public function test_promotion_relationship_stores_id(): void {
		$promo_id = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PROMOTION,
				'post_title'  => 'Launch Week',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/funiq/v1/products' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'name'      => 'Promo Product',
					'price'     => 59.99,
					'promotion' => array( 'id' => $promo_id ),
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotNull( $data['promotion'] );
		$this->assertEquals( $promo_id, $data['promotion']['id'] );
	}

	/**
	 * GET /funiq/v1/products/{id} returns 404 for missing product.
	 */
	public function test_get_single_404(): void {
		$request  = new WP_REST_Request( 'GET', '/funiq/v1/products/99999' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'astro_not_found', $response, 404 );
	}
}
