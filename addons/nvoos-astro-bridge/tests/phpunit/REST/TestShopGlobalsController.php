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
		delete_option( Schema::OPTION_PUBLIC_ORIGIN );

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
		delete_option( Schema::OPTION_PUBLIC_ORIGIN );
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
		$this->assertStringContainsString( '/order-received/', $data['returnUrl'] );
		$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $data['currency'] );
		$this->assertSame( '$', $data['currencySymbol'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['updatedAt'] );
		// 108 extension fields (read-only): the native default and no
		// payment methods without WooCommerce.
		$this->assertSame( 'native', $data['productsSource'] );
		$this->assertSame( array(), $data['paymentMethods'] );
	}

	/**
	 * PATCH happy path — all four fields, currency normalized to uppercase.
	 *
	 * The off-site `shop.example.com` host rides the 108 H1 allowlist filter.
	 */
	public function test_patch_all_fields(): void {
		add_filter(
			'funiq_bridge_checkout_url_allowlist',
			static fn() => array( 'shop.example.com' )
		);
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request(
				array(
					'checkoutUrl'    => 'https://shop.example.com/checkout/',
					'cartUrl'        => 'https://shop.example.com/cart/',
					'returnUrl'      => 'https://shop.example.com/checkout/order-received/',
					'currency'       => 'eur',
					'currencySymbol' => '€',
				)
			)
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'https://shop.example.com/checkout/', $data['checkoutUrl'] );
		$this->assertSame( 'https://shop.example.com/cart/', $data['cartUrl'] );
		$this->assertSame( 'https://shop.example.com/checkout/order-received/', $data['returnUrl'] );
		$this->assertSame( 'EUR', $data['currency'] );
		$this->assertSame( '€', $data['currencySymbol'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Off-site return URL without an allowlist entry → typed 400 (108 H1).
	 */
	public function test_patch_offsite_return_url_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'returnUrl' => 'https://evil.example.com/thanks/' ) )
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_return_url_not_allowed', $response->as_error()->get_error_code() );

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
	 * Off-site checkout URL without an allowlist entry → typed 400 (108 H1).
	 */
	public function test_patch_offsite_checkout_url_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'checkoutUrl' => 'https://evil.example.com/checkout/' ) )
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_checkout_url_not_allowed', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Userinfo URLs are rejected even for the site's own host (108 H1).
	 */
	public function test_patch_userinfo_url_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'checkoutUrl' => 'https://user@example.org/checkout/' ) )
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_checkout_url_not_allowed', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * The site's own host passes the same-site rule without the allowlist.
	 */
	public function test_patch_same_site_url_accepted(): void {
		wp_set_current_user( $this->admin_id );

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$response  = rest_get_server()->dispatch(
			$this->patch_request(
				array( 'checkoutUrl' => 'https://' . $site_host . '/checkout/?utm_source=store' )
			)
		);
		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'https://' . $site_host . '/checkout/?utm_source=store', $response->get_data()['checkoutUrl'] );

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
	 * Proposal 135 — the shopper-facing defaults follow the configured public
	 * origin, not the WordPress (CMS) origin.
	 */
	public function test_defaults_follow_the_public_origin(): void {
		update_option( Schema::OPTION_PUBLIC_ORIGIN, 'https://store.example.com' );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/shop' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'https://store.example.com/checkout/', $data['checkoutUrl'] );
		$this->assertSame( 'https://store.example.com/cart/', $data['cartUrl'] );
		$this->assertSame( 'https://store.example.com/checkout/order-received/', $data['returnUrl'] );
	}

	/**
	 * Proposal 135 — the public-origin host is a first-class same-site host:
	 * no allowlist filter is needed when the CMS lives on a subdomain.
	 */
	public function test_public_origin_host_is_allowed_without_the_allowlist(): void {
		update_option( Schema::OPTION_PUBLIC_ORIGIN, 'https://store.example.com' );
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'checkoutUrl' => 'https://store.example.com/checkout/' ) )
		);
		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'https://store.example.com/checkout/', $response->get_data()['checkoutUrl'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Proposal 135 — a third host is still rejected; the public origin does
	 * not widen the allowlist beyond the two real origins.
	 */
	public function test_third_host_is_still_rejected(): void {
		update_option( Schema::OPTION_PUBLIC_ORIGIN, 'https://store.example.com' );
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'checkoutUrl' => 'https://evil.example.com/checkout/' ) )
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_shop_checkout_url_not_allowed', $response->as_error()->get_error_code() );

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
