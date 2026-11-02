<?php
/**
 * PHPUnit tests for the shop globals controller (Proposal 106 §1.3):
 * seeded defaults, public GET, PATCH happy/invalid paths, permission
 * gate, and the short cache headers.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;

/**
 * Shop globals REST tests.
 *
 * @covers \NvoosAstroBridge\REST\ShopGlobalsController
 * @covers \NvoosAstroBridge\REST\BaseController
 * @group nvoos-astro-bridge
 */
class TestShopGlobalsController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test state: REST routes, admin, clean shop option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		delete_option( Schema::OPTION_SHOP );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Clean the option between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( Schema::OPTION_SHOP );
		parent::tearDown();
	}

	/**
	 * Build a JSON PATCH request for the shop route.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return WP_REST_Request
	 */
	private function patch_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'PATCH', '/nvoos-commerce/v1/globals/shop' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * GET /globals/shop is registered on both namespaces and is public.
	 */
	public function test_route_registered_and_public(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/funiq/v1/globals/shop', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/globals/shop', $routes );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/shop' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * The default doc carries the §1.3 contract shape.
	 */
	public function test_default_doc_shape(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/shop' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertStringContainsString( '/checkout/', $data['checkoutUrl'] );
		$this->assertStringContainsString( '/cart/', $data['cartUrl'] );
		$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $data['currency'] );
		$this->assertSame( '$', $data['currencySymbol'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['updatedAt'] );
	}

	/**
	 * PATCH happy path — all four fields, currency normalized to uppercase.
	 */
	public function test_patch_all_fields(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request(
				array(
					'checkoutUrl'    => 'https://shop.example.com/checkout/',
					'cartUrl'        => 'https://shop.example.com/cart/',
					'currency'       => 'eur',
					'currencySymbol' => '€',
				)
			)
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'https://shop.example.com/checkout/', $data['checkoutUrl'] );
		$this->assertSame( 'https://shop.example.com/cart/', $data['cartUrl'] );
		$this->assertSame( 'EUR', $data['currency'] );
		$this->assertSame( '€', $data['currencySymbol'] );

		wp_set_current_user( 0 );
	}

	/**
	 * PATCH partial — untouched fields keep their stored values.
	 */
	public function test_patch_is_partial(): void {
		wp_set_current_user( $this->admin_id );

		rest_get_server()->dispatch(
			$this->patch_request( array( 'currency' => 'gbp' ) )
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/shop' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'GBP', $data['currency'] );
		$this->assertStringContainsString( '/checkout/', $data['checkoutUrl'] );
		$this->assertSame( '$', $data['currencySymbol'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Invalid currency → typed 400.
	 */
	public function test_patch_invalid_currency(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'currency' => 'EURO' ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_invalid_currency', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Invalid checkout URL → typed 400.
	 */
	public function test_patch_invalid_checkout_url(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'checkoutUrl' => 'not-a-url' ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_invalid_checkout_url', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Unknown fields → typed 400.
	 */
	public function test_patch_unknown_field_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'taxRate' => 21 ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_invalid_field', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * PATCH without the manage capability → 403.
	 */
	public function test_patch_requires_capability(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'currency' => 'GBP' ) )
		);
		$this->assertEquals( 403, $response->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * The short cache headers apply to the shop route too.
	 */
	public function test_short_cache_headers(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/shop' );
		$response = \NvoosAstroBridge\REST\BaseController::set_short_cache_headers_on_public_gets(
			rest_ensure_response( Schema::shop_defaults() ),
			rest_get_server(),
			$request
		);

		$headers = $response->get_headers();
		$this->assertStringContainsString( 's-maxage=60', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'stale-while-revalidate=15', $headers['Cache-Control'] );
		$this->assertArrayHasKey( 'ETag', $headers );
	}
}
