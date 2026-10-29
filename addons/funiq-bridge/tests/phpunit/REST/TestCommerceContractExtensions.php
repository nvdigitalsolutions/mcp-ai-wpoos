<?php
/**
 * PHPUnit tests for the Proposal 092 bridge enhancements:
 * B1 cache headers, B2 where/sort operators, the nvoos-commerce/v1
 * namespace alias, and the B4 auth/promocode surface.
 *
 * @package FuniqBridge\Tests
 */

use FuniqBridge\Schema;

/**
 * Commerce contract extension tests (Proposal 092 bridge surface).
 *
 * @covers \FuniqBridge\REST\BaseController
 * @covers \FuniqBridge\REST\AuthController
 * @covers \FuniqBridge\REST\ProductsController
 * @covers \FuniqBridge\REST\PromocodesController
 * @group funiq-bridge
 */
class TestCommerceContractExtensions extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test state: register CPTs + REST routes, create an admin.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Register the bridge CPTs/taxonomies directly — re-firing
		// do_action('init') re-registers WooCommerce blocks/integrations and
		// fails every test with "already registered" notices (test-suite
		// skill pattern #2). REST routes register on the rest_api_init fire.
		$plugin = \FuniqBridge\Plugin::instance();
		if ( ! post_type_exists( Schema::CPT_PRODUCT ) ) {
			$plugin->register_post_types();
			$plugin->register_taxonomies();
		}
		do_action( 'rest_api_init' );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
	}

	// -----------------------------------------------------------------------
	// Namespace alias.
	// -----------------------------------------------------------------------

	/**
	 * The NV commerce contract namespace serves the same routes as legacy.
	 */
	public function test_nv_namespace_alias_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/funiq/v1/products', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/products', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/auth/nonce', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/promocodes/validate', $routes );
	}

	// -----------------------------------------------------------------------
	// B1 — cache headers on public GETs.
	//
	// Note: WP applies rest_post_dispatch in serve_request() (the HTTP
	// layer), not in dispatch() — so the filter logic is exercised by
	// calling the static method directly, exactly as the HTTP layer does.
	// -----------------------------------------------------------------------

	/**
	 * The cache-header filter is registered on rest_post_dispatch.
	 */
	public function test_cache_filter_is_registered(): void {
		$this->assertNotFalse(
			has_filter(
				'rest_post_dispatch',
				array( \FuniqBridge\REST\BaseController::class, 'set_cache_headers_on_public_gets' )
			)
		);
	}

	/**
	 * Anonymous GETs carry public cache headers + validators.
	 */
	public function test_public_get_carries_cache_headers(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$response = \FuniqBridge\REST\BaseController::set_cache_headers_on_public_gets(
			rest_ensure_response(
				array(
					'docs'     => array(),
					'totalDocs' => 0,
				)
			),
			rest_get_server(),
			$request
		);

		$headers = $response->get_headers();

		$this->assertStringContainsString( 'public', $headers['Cache-Control'] );
		$this->assertStringContainsString( 's-maxage=300', $headers['Cache-Control'] );
		$this->assertArrayHasKey( 'ETag', $headers );
	}

	/**
	 * Single-document GETs also carry Last-Modified (from updatedAt).
	 */
	public function test_single_get_carries_last_modified(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products/1' );
		$response = \FuniqBridge\REST\BaseController::set_cache_headers_on_public_gets(
			rest_ensure_response(
				array(
					'id'        => 1,
					'updatedAt' => '2026-10-20 09:30:00',
				)
			),
			rest_get_server(),
			$request
		);

		$this->assertArrayHasKey( 'Last-Modified', $response->get_headers() );
	}

	/**
	 * Authenticated GETs are never marked public.
	 */
	public function test_authenticated_get_is_not_marked_public(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$response = \FuniqBridge\REST\BaseController::set_cache_headers_on_public_gets(
			rest_ensure_response( array( 'docs' => array(), 'totalDocs' => 0 ) ),
			rest_get_server(),
			$request
		);

		$headers = $response->get_headers();
		if ( isset( $headers['Cache-Control'] ) ) {
			$this->assertStringNotContainsString( 'public', $headers['Cache-Control'] );
		} else {
			// Absence of the header is the expected outcome — still an assertion.
			$this->addToAssertionCount( 1 );
		}

		wp_set_current_user( 0 );
	}

	// -----------------------------------------------------------------------
	// B2 — where operators + sort.
	// -----------------------------------------------------------------------

	/**
	 * Where[brand][equals] filters by brand taxonomy.
	 */
	public function test_where_brand_equals(): void {
		$brand_id = $this->factory->term->create(
			array(
				'taxonomy' => Schema::TAX_BRAND,
				'name'     => 'NV Home',
			)
		);

		$matching = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Brand Match',
				'post_status' => 'publish',
			)
		);
		$other    = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'No Brand',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $matching, array( $brand_id ), Schema::TAX_BRAND );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$request->set_query_params(
			array( 'where' => array( 'brand' => array( 'equals' => $brand_id ) ) )
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$ids = wp_list_pluck( $data['docs'], 'id' );
		$this->assertContains( $matching, $ids );
		$this->assertNotContains( $other, $ids );
	}

	/**
	 * Where[colors][in] matches any of the given color terms.
	 */
	public function test_where_colors_in(): void {
		$color_a = $this->factory->term->create(
			array(
				'taxonomy' => Schema::TAX_COLOR,
				'name'     => 'Sage',
			)
		);
		$color_b = $this->factory->term->create(
			array(
				'taxonomy' => Schema::TAX_COLOR,
				'name'     => 'Oat',
			)
		);

		$matching = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Sage Item',
				'post_status' => 'publish',
			)
		);
		$other    = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Plain Item',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $matching, array( $color_a ), Schema::TAX_COLOR );
		wp_set_object_terms( $other, array( $color_b ), Schema::TAX_COLOR );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$request->set_query_params(
			array( 'where' => array( 'colors' => array( 'in' => array( $color_a ) ) ) )
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$ids = wp_list_pluck( $data['docs'], 'id' );
		$this->assertContains( $matching, $ids );
		$this->assertNotContains( $other, $ids );
	}

	/**
	 * Sort=-price returns ascending price order.
	 */
	public function test_sort_price_ascending(): void {
		$expensive = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Expensive',
				'post_status' => 'publish',
			)
		);
		$cheap     = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Cheap',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $expensive, Schema::META_PRICE, 1000 );
		update_post_meta( $cheap, Schema::META_PRICE, 10 );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$request->set_query_params( array( 'sort' => '-price' ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$ids = wp_list_pluck( $data['docs'], 'id' );
		$this->assertContains( $cheap, $ids );
		$this->assertContains( $expensive, $ids );
		$this->assertLessThan(
			array_search( $expensive, $ids, true ),
			array_search( $cheap, $ids, true )
		);
	}

	// -----------------------------------------------------------------------
	// B4 — auth surface + promocode validation (owner decision Q5).
	// -----------------------------------------------------------------------

	/**
	 * GET /auth/nonce returns a REST nonce.
	 */
	public function test_auth_nonce(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/auth/nonce' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['nonce'] );
	}

	/**
	 * /auth/nonce must never be marked cacheable — the nonce is a
	 * session-tied CSRF token (cached copy breaks cookie auth).
	 */
	public function test_auth_nonce_is_never_marked_public(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/auth/nonce' );
		$response = \FuniqBridge\REST\BaseController::set_cache_headers_on_public_gets(
			rest_ensure_response( array( 'nonce' => 'fixture-nonce' ) ),
			rest_get_server(),
			$request
		);

		$this->assertArrayNotHasKey( 'Cache-Control', $response->get_headers() );
	}

	/**
	 * Registration requires a nonce (403 without one).
	 */
	public function test_register_requires_nonce(): void {
		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/auth/register' );
		$request->set_body_params(
			array(
				'email'    => 'no-nonce@example.com',
				'password' => 'longenough123',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Registration with a valid nonce creates a subscriber and logs it in.
	 */
	public function test_register_creates_customer(): void {
		$email = 'shopper-' . wp_rand( 1000, 9999 ) . '@example.com';

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/auth/register' );
		$request->set_body_params(
			array(
				'email'    => $email,
				'password' => 'longenough123',
				'name'     => 'Shopper',
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( $email, $data['email'] );

		$user = get_user_by( 'email', $email );
		$this->assertNotFalse( $user );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * GET /auth/me includes the dashboard fields for a manager.
	 *
	 * The capability is seeded on activation (Plugin::activate), which
	 * PHPUnit never runs — grant it explicitly to mirror production.
	 */
	public function test_auth_me_includes_manager_fields(): void {
		get_role( 'administrator' )->add_cap( Schema::CAP_MANAGE_FUNIQ );
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/auth/me' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['can_manage'] );
		$this->assertStringContainsString( 'admin.php?page=' . Schema::ADMIN_PAGE_SLUG, $data['admin_url'] );

		wp_set_current_user( 0 );
	}

	/**
	 * GET /auth/me for a subscriber carries no dashboard access.
	 */
	public function test_auth_me_subscriber_has_no_manager_fields(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/auth/me' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['can_manage'] );
		$this->assertSame( '', $data['admin_url'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Promocode validation: found + active returns the document.
	 */
	public function test_promocode_validate_found(): void {
		$promocode_id = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PROMOCODE,
				'post_title'  => 'SUMMER10',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $promocode_id, Schema::META_PROMOCODE_IS_ACTIVE, true );
		update_post_meta( $promocode_id, Schema::META_PROMOCODE_DISCOUNT, 10 );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/promocodes/validate' );
		$request->set_query_params( array( 'code' => 'summer10' ) ); // case-insensitive.
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'SUMMER10', $response->get_data()['code'] );
		$this->assertEquals( 10, $response->get_data()['discount'] );
	}

	/**
	 * Promocode validation: unknown code 404s.
	 */
	public function test_promocode_validate_not_found(): void {
		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/promocodes/validate' );
		$request->set_query_params( array( 'code' => 'NOPE999' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}
}
