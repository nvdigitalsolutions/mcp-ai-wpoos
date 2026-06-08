<?php
/**
 * PHPUnit test for ProductsController.
 *
 * @package FuniqBridge\Tests
 */

use FuniqBridge\REST\ProductsController;
use FuniqBridge\Schema;

/**
 * @covers \FuniqBridge\REST\ProductsController
 * @group funiq-bridge
 */
class TestProductsController extends WP_Test_REST_TestCase {

	/** @var int */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();

		// Register post types and REST routes.
		do_action( 'init' );
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
	 * GET /funiq/v1/products/{id} returns 404 for missing product.
	 */
	public function test_get_single_404(): void {
		$request  = new WP_REST_Request( 'GET', '/funiq/v1/products/99999' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'funiq_not_found', $response, 404 );
	}
}
