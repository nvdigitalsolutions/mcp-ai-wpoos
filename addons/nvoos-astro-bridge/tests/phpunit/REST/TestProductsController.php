<?php
/**
 * PHPUnit test for ProductsController.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\REST\ProductsController;
use NvoosAstroBridge\Schema;

/**
 * REST controller tests for the products collection.
 *
 * @covers \NvoosAstroBridge\REST\ProductsController
 * @group nvoos-astro-bridge
 */
class TestProductsController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up the test environment.
	 */
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
	 * The where[id][equals] filter returns a single product.
	 */
	public function test_where_id_equals_query(): void {
		$pid = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Test Product',
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
	 * Reset the session to a truly anonymous state.
	 *
	 * The shared test bootstrap leaves a fully-capped admin logged in (plus a
	 * valid auth cookie) for its own REST suites — bridge permission tests
	 * must clear both, or AuthSupport::maybe_restore_user_from_logged_in_cookie()
	 * re-resolves that admin and every "unauthenticated" dispatch succeeds.
	 *
	 * @return void
	 */
	private function as_anonymous(): void {
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	/**
	 * Unauthenticated users cannot create products.
	 */
	public function test_unauthenticated_cannot_create(): void {
		$this->as_anonymous();

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

	/**
	 * The public listing never includes draft products.
	 */
	public function test_public_list_excludes_drafts(): void {
		$published = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Published Product',
				'post_status' => 'publish',
			)
		);
		$draft     = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Draft Product',
				'post_status' => 'draft',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$response = rest_get_server()->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data()['docs'], 'id' );

		$this->assertContains( $published, $ids );
		$this->assertNotContains( $draft, $ids );
	}

	/**
	 * An authenticated admin can create a draft product; the document carries
	 * the publication status and the post is stored as a draft.
	 */
	public function test_admin_can_create_draft_product(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/funiq/v1/products' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'name'   => 'Draft Product',
					'price'  => 9.99,
					'status' => 'draft',
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'draft', $data['status'] );
		$this->assertEquals( 'draft', get_post_status( $data['id'] ) );
	}

	/**
	 * Listing drafts requires the manage capability — unauthenticated
	 * requests are rejected.
	 */
	public function test_unauthenticated_cannot_list_drafts(): void {
		$this->as_anonymous();

		$request = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$request->set_query_params( array( 'status' => 'draft' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * With the manage capability, status=draft returns only drafts and
	 * status=any returns every product.
	 */
	public function test_admin_can_list_drafts_and_any(): void {
		$published = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Published Product',
				'post_status' => 'publish',
			)
		);
		$draft     = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Draft Product',
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$request->set_query_params( array( 'status' => 'draft' ) );
		$draft_ids = wp_list_pluck( rest_get_server()->dispatch( $request )->get_data()['docs'], 'id' );
		$this->assertContains( $draft, $draft_ids );
		$this->assertNotContains( $published, $draft_ids );

		$request = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$request->set_query_params( array( 'status' => 'any' ) );
		$any_ids = wp_list_pluck( rest_get_server()->dispatch( $request )->get_data()['docs'], 'id' );
		$this->assertContains( $draft, $any_ids );
		$this->assertContains( $published, $any_ids );
	}

	/**
	 * PUT can move a product between publish and draft.
	 */
	public function test_admin_can_update_product_status(): void {
		$pid = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Status Product',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $this->admin_id );

		// Publish → draft.
		$request = new WP_REST_Request( 'PUT', "/funiq/v1/products/{$pid}" );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'status' => 'draft' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'draft', $response->get_data()['status'] );

		// Draft → publish.
		$request = new WP_REST_Request( 'PUT', "/funiq/v1/products/{$pid}" );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'status' => 'publish' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'publish', $response->get_data()['status'] );
	}

	/**
	 * The public single-product read hides drafts with a 404 — the existence
	 * of unreleased products never leaks to the catalog surface.
	 */
	public function test_public_cannot_read_draft_single(): void {
		$pid = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Unreleased Product',
				'post_status' => 'draft',
			)
		);
		$this->as_anonymous();

		$request  = new WP_REST_Request( 'GET', "/funiq/v1/products/{$pid}" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'astro_not_found', $response, 404 );
	}

	/**
	 * Managers can read draft products by ID.
	 */
	public function test_admin_can_read_draft_single(): void {
		$pid = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Unreleased Product',
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', "/funiq/v1/products/{$pid}" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'draft', $response->get_data()['status'] );
	}

	/**
	 * Unsupported status values are rejected by the param schema.
	 */
	public function test_invalid_status_param_returns_400(): void {
		$request = new WP_REST_Request( 'GET', '/funiq/v1/products' );
		$request->set_query_params( array( 'status' => 'scheduled' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}
}
