<?php
/**
 * PHPUnit tests for the checkout controller (Proposal 110 Phase A):
 * the fail-closed gates (Woo missing, native mode, bad session), the merged
 * checkout doc, the address/payment-data normalization (asserted through the
 * extended zero-HTTP seam), the place-order envelope, and the rate limit.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;

/**
 * Checkout REST tests.
 *
 * @covers \NvoosAstroBridge\REST\CheckoutController
 * @covers \NvoosAstroBridge\Woo\StoreApiClient
 * @group nvoos-astro-bridge
 */
class TestCheckoutController extends WP_Test_REST_TestCase {

	/**
	 * Set up test state: REST routes, clean options, woo mode + availability.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		update_option( Schema::OPTION_PRODUCT_SOURCE, 'woo' );
		add_filter( 'funiq_bridge_store_api_available', '__return_true' );

		wp_set_current_user( 0 );
	}

	/**
	 * Clean options between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( Schema::OPTION_PRODUCT_SOURCE );
		parent::tearDown();
	}

	/**
	 * Canned Store API responses via a per-call resolver (zero-HTTP seam).
	 * The 4th arg carries the resolved wp_remote_request args so tests can
	 * assert the outbound nonce/token headers and JSON body.
	 *
	 * @param callable|null                    $resolver fn( string $method, string $path, array $args ): array|null.
	 * @param array<int, array<string, mixed>> $calls Recorder (method, path, args).
	 * @return void
	 */
	private function stub_store_api( ?callable $resolver = null, array &$calls = array() ): void {
		add_filter(
			'funiq_bridge_store_api_pre_response',
			static function ( $pre, $method, $path, $args = array() ) use ( $resolver, &$calls ) {
				$calls[] = array( $method, $path, $args );
				if ( null === $resolver ) {
					return $pre;
				}
				$entry = $resolver( $method, $path, $args );
				if ( null === $entry ) {
					return $pre;
				}
				return array(
					'status'  => $entry['status'],
					'headers' => $entry['headers'] ?? array(),
					'body'    => $entry['body'],
				);
			},
			10,
			4
		);
	}

	/**
	 * Build a checkout request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $route   Route (relative to the NV namespace).
	 * @param array<string, mixed> $body    Request body (may be empty).
	 * @param string               $session Session header value.
	 * @return WP_REST_Request
	 */
	private function checkout_request( string $method, string $route, array $body = array(), string $session = 'c3a2f1e0-1111-4b22-9c33-000000000001' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/nvoos-commerce/v1' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-nvoos-cart-session', $session );
		if ( ! empty( $body ) ) {
			$request->set_body( wp_json_encode( $body ) );
		}
		return $request;
	}

	/**
	 * The default canned Store API graph: bootstrap cart, cart state with
	 * shipping rates, checkout draft with persisted addresses.
	 *
	 * @return callable
	 */
	private function default_resolver(): callable {
		$cart_state   = array(
			'items'          => array(
				array(
					'key'      => 'abc',
					'id'       => 3,
					'name'     => 'Halo Chair',
					'quantity' => 2,
					'prices'   => array( 'price' => '849' ),
				),
			),
			'totals'         => array(
				'total_items'    => '2',
				'total_price'    => '169800',
				'total_shipping' => '1000',
				'total_tax'      => '200',
			),
			'shipping_rates' => array(
				array(
					'package_id'     => 'pkg-0',
					'name'           => 'Shipment',
					'shipping_rates' => array(
						array(
							'rate_id'     => 'flat_rate',
							'name'        => 'Flat rate',
							'description' => '',
							'price'       => '1000',
							'selected'    => true,
						),
					),
				),
			),
			'needs_shipping' => true,
			'coupons'        => array(),
		);
		$checkout_doc = array(
			'order_id'         => 146,
			'status'           => 'checkout-draft',
			'order_key'        => 'wc_order_VPffqyvgWVqWL',
			'billing_address'  => array(
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'company'    => '',
				'address_1'  => '1 Astro Way',
				'address_2'  => '',
				'city'       => 'Velocity',
				'state'      => 'CA',
				'postcode'   => '90001',
				'country'    => 'US',
				'phone'      => '',
				'email'      => 'jane@example.com',
			),
			'shipping_address' => array(
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'company'    => '',
				'address_1'  => '1 Astro Way',
				'address_2'  => '',
				'city'       => 'Velocity',
				'state'      => 'CA',
				'postcode'   => '90001',
				'country'    => 'US',
				'phone'      => '',
				'email'      => 'jane@example.com',
			),
			'payment_method'   => '',
			'payment_result'   => array(),
		);

		return static function ( $method, $path ) use ( $cart_state, $checkout_doc ) {
			if ( 'POST' === $method && '/cart' === $path ) {
				return array(
					'status'  => 200,
					'headers' => array( 'cart-token' => 'tok-123' ),
					'body'    => '{}',
				);
			}
			if ( 'GET' === $method && '/cart' === $path ) {
				return array(
					'status' => 200,
					'body'   => wp_json_encode( $cart_state ),
				);
			}
			if ( 'GET' === $method && '/checkout' === $path ) {
				return array(
					'status' => 200,
					'body'   => wp_json_encode( $checkout_doc ),
				);
			}
			if ( 'POST' === $method && '/cart/update-customer' === $path ) {
				return array(
					'status' => 200,
					'body'   => wp_json_encode( $cart_state ),
				);
			}
			if ( 'POST' === $method && '/cart/select-shipping-rate' === $path ) {
				return array(
					'status' => 200,
					'body'   => wp_json_encode( $cart_state ),
				);
			}
			if ( 'POST' === $method && '/cart/apply-coupon' === $path ) {
				return array(
					'status' => 200,
					'body'   => wp_json_encode( $cart_state ),
				);
			}
			return null;
		};
	}

	/**
	 * Without Woo (availability seam off), every checkout route fails 501.
	 */
	public function test_woo_missing_returns_501(): void {
		add_filter( 'funiq_bridge_store_api_available', '__return_false' );

		$response = rest_get_server()->dispatch( $this->checkout_request( 'GET', '/checkout' ) );
		$this->assertEquals( 501, $response->get_status() );
		$this->assertSame( 'astro_checkout_woo_missing', $response->as_error()->get_error_code() );
	}

	/**
	 * Native product mode disables the embedded checkout (400).
	 */
	public function test_native_mode_returns_400(): void {
		update_option( Schema::OPTION_PRODUCT_SOURCE, 'native' );

		$response = rest_get_server()->dispatch( $this->checkout_request( 'GET', '/checkout' ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_checkout_native_mode', $response->as_error()->get_error_code() );
	}

	/**
	 * A missing or malformed session header is a typed 400.
	 */
	public function test_session_header_required(): void {
		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/checkout' );
		$request->set_header( 'content-type', 'application/json' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_checkout_invalid_session', $response->as_error()->get_error_code() );

		$response = rest_get_server()->dispatch( $this->checkout_request( 'GET', '/checkout', array(), 'bad session!' ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_checkout_invalid_session', $response->as_error()->get_error_code() );
	}

	/**
	 * GET /checkout merges the draft order + cart state into one app doc and
	 * never carries public cache headers.
	 */
	public function test_get_checkout_merges_doc(): void {
		$this->stub_store_api( $this->default_resolver() );

		$response = rest_get_server()->dispatch( $this->checkout_request( 'GET', '/checkout' ) );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 146, $data['orderId'] );
		$this->assertSame( 'checkout-draft', $data['orderStatus'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'Halo Chair', $data['items'][0]['name'] );
		$this->assertSame( '169800', $data['totals']['totalPrice'] );
		$this->assertSame( 'Jane', $data['billingAddress']['firstName'] );
		$this->assertSame( '1 Astro Way', $data['shippingAddress']['address1'] );
		$this->assertCount( 1, $data['shippingRates'] );
		$this->assertSame( 'flat_rate', $data['shippingRates'][0]['rates'][0]['rateId'] );
		$this->assertTrue( $data['needsShipping'] );
	}

	/**
	 * The checkout routes are excluded from the public B1 cache filter and
	 * pin private/no-store headers (the shop-globals test pattern — the
	 * header filters are invoked directly, since rest_post_dispatch does not
	 * run inside rest_get_server()->dispatch()).
	 */
	public function test_checkout_never_marked_public(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/checkout' );
		$response = rest_ensure_response( array( 'session' => 's-1' ) );

		// B1 must NOT mark the per-session surface cacheable.
		$b1 = \NvoosAstroBridge\REST\BaseController::set_cache_headers_on_public_gets(
			$response,
			rest_get_server(),
			$request
		);
		$this->assertArrayNotHasKey( 'Cache-Control', $b1->get_headers() );

		// The controller's own filter pins the private/no-store pair.
		$controller = new \NvoosAstroBridge\REST\CheckoutController();
		$pinned     = $controller->set_no_store_headers( $b1, rest_get_server(), $request );
		$headers    = $pinned->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringNotContainsString( 'public', $headers['Cache-Control'] );
	}

	/**
	 * POST /checkout/customer maps camelCase addresses to the Store API
	 * snake_case shape and stamps the nonce headers on the outbound call.
	 */
	public function test_update_customer_normalizes_address_and_nonce(): void {
		$calls = array();
		$this->stub_store_api( $this->default_resolver(), $calls );

		$response = rest_get_server()->dispatch(
			$this->checkout_request(
				'POST',
				'/checkout/customer',
				array(
					'billingAddress'  => array(
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => 'NV',
						'address1'  => '1 Astro Way',
						'address2'  => '',
						'city'      => 'Velocity',
						'state'     => 'CA',
						'postcode'  => '90001',
						'country'   => 'us',
						'phone'     => '5550100',
						'email'     => 'jane@example.com',
					),
					'shippingAddress' => array(
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => '',
						'address1'  => '1 Astro Way',
						'address2'  => '',
						'city'      => 'Velocity',
						'state'     => 'CA',
						'postcode'  => '90001',
						'country'   => 'US',
						'phone'     => '',
						'email'     => 'jane@example.com',
					),
				)
			)
		);
		$this->assertEquals( 200, $response->get_status() );

		$update_call = null;
		foreach ( $calls as $call ) {
			if ( 'POST' === $call[0] && '/cart/update-customer' === $call[1] ) {
				$update_call = $call;
			}
		}
		$this->assertNotNull( $update_call );

		$args    = $update_call[2];
		$headers = $args['headers'];
		$this->assertSame( 'tok-123', $headers['Cart-Token'] );
		$this->assertNotEmpty( $headers['Nonce'] );
		$this->assertNotEmpty( $headers['X-WC-Store-API-Nonce'] );

		$body = json_decode( $args['body'], true );
		$this->assertSame( 'Jane', $body['billing_address']['first_name'] );
		$this->assertSame( '1 Astro Way', $body['billing_address']['address_1'] );
		$this->assertSame( 'US', $body['billing_address']['country'] );
		$this->assertSame( 'jane@example.com', $body['billing_address']['email'] );
		$this->assertSame( 'Jane', $body['shipping_address']['first_name'] );
	}

	/**
	 * POST /checkout/customer with neither address is a typed 400.
	 */
	public function test_update_customer_requires_an_address(): void {
		$this->stub_store_api( $this->default_resolver() );

		$response = rest_get_server()->dispatch( $this->checkout_request( 'POST', '/checkout/customer', array( 'other' => true ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_checkout_invalid_body', $response->as_error()->get_error_code() );
	}

	/**
	 * POST /checkout relays addresses, payment_data and expected_total to the
	 * Store API and returns the order envelope.
	 */
	public function test_place_order_full_flow(): void {
		$calls = array();
		$this->stub_store_api(
			static function ( $method, $path ) {
				if ( 'POST' === $method && '/cart' === $path ) {
					return array(
						'status'  => 200,
						'headers' => array( 'cart-token' => 'tok-123' ),
						'body'    => '{}',
					);
				}
				if ( 'POST' === $method && '/checkout' === $path ) {
					return array(
						'status' => 200,
						'body'   => wp_json_encode(
							array(
								'order_id'       => 200,
								'order_key'      => 'wc_order_Fixture',
								'status'         => 'processing',
								'payment_result' => array(
									'payment_status'  => 'success',
									'payment_details' => array(),
									'redirect_url'    => '',
								),
							)
						),
					);
				}
				return null;
			},
			$calls
		);

		$response = rest_get_server()->dispatch(
			$this->checkout_request(
				'POST',
				'/checkout',
				array(
					'payment_method'  => 'stripe',
					'billingAddress'  => array(
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => '',
						'address1'  => '1 Astro Way',
						'address2'  => '',
						'city'      => 'Velocity',
						'state'     => 'CA',
						'postcode'  => '90001',
						'country'   => 'US',
						'phone'     => '',
					),
					'shippingAddress' => array(
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => '',
						'address1'  => '1 Astro Way',
						'address2'  => '',
						'city'      => 'Velocity',
						'state'     => 'CA',
						'postcode'  => '90001',
						'country'   => 'US',
						'phone'     => '',
					),
					'paymentData'     => array(
						array(
							'key'   => 'stripe_source',
							'value' => 'pm_123abc',
						),
						array(
							'key'   => 'wc-stripe-new-payment-method',
							'value' => true,
						),
					),
					'expectedTotal'   => '170800',
				)
			)
		);
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 200, $data['orderId'] );
		$this->assertSame( 'wc_order_Fixture', $data['orderKey'] );
		$this->assertSame( 'success', $data['paymentResult']['paymentStatus'] );
		$this->assertSame( '', $data['paymentResult']['redirectUrl'] );

		$checkout_call = null;
		foreach ( $calls as $call ) {
			if ( 'POST' === $call[0] && '/checkout' === $call[1] ) {
				$checkout_call = $call;
			}
		}
		$this->assertNotNull( $checkout_call );

		$body = json_decode( $checkout_call[2]['body'], true );
		$this->assertSame( 'stripe', $body['payment_method'] );
		$this->assertSame( 'Jane', $body['billing_address']['first_name'] );
		$this->assertSame( 'pm_123abc', $body['payment_data'][0]['value'] );
		// Booleans ride through as JSON booleans (wc_string_to_bool reads them).
		$this->assertTrue( $body['payment_data'][1]['value'] );
		$this->assertSame( '170800', $body['expected_total'] );
	}

	/**
	 * POST /checkout without a payment_method is rejected by the arg
	 * validator before the callback runs.
	 */
	public function test_place_order_requires_payment_method(): void {
		$response = rest_get_server()->dispatch(
			$this->checkout_request(
				'POST',
				'/checkout',
				array(
					'billingAddress'  => array(),
					'shippingAddress' => array(),
				)
			)
		);
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * POST /checkout without both addresses is a typed 400.
	 */
	public function test_place_order_requires_addresses(): void {
		$this->stub_store_api( $this->default_resolver() );

		$response = rest_get_server()->dispatch(
			$this->checkout_request( 'POST', '/checkout', array( 'payment_method' => 'cod' ) )
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_checkout_invalid_billing_address', $response->as_error()->get_error_code() );
	}

	/**
	 * A Store API total-mismatch rejection (409) surfaces as a typed error
	 * with the refreshed cart preserved for the storefront.
	 */
	public function test_total_mismatch_preserves_refreshed_cart(): void {
		$this->stub_store_api(
			static function ( $method, $path ) {
				if ( 'POST' === $method && '/cart' === $path ) {
					return array(
						'status'  => 200,
						'headers' => array( 'cart-token' => 'tok-123' ),
						'body'    => '{}',
					);
				}
				if ( 'POST' === $method && '/checkout' === $path ) {
					return array(
						'status' => 409,
						'body'   => wp_json_encode(
							array(
								'code'    => 'woocommerce_rest_checkout_total_mismatch',
								'message' => 'The order total changed. Please review your order.',
								'data'    => array( 'totals' => array( 'total_price' => '180000' ) ),
							)
						),
					);
				}
				return null;
			}
		);

		$response = rest_get_server()->dispatch(
			$this->checkout_request(
				'POST',
				'/checkout',
				array(
					'payment_method'  => 'cod',
					'billingAddress'  => array(
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => '',
						'address1'  => '1 Astro Way',
						'address2'  => '',
						'city'      => 'Velocity',
						'state'     => 'CA',
						'postcode'  => '90001',
						'country'   => 'US',
						'phone'     => '',
					),
					'shippingAddress' => array(
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
						'company'   => '',
						'address1'  => '1 Astro Way',
						'address2'  => '',
						'city'      => 'Velocity',
						'state'     => 'CA',
						'postcode'  => '90001',
						'country'   => 'US',
						'phone'     => '',
					),
					'expectedTotal'   => '170800',
				)
			)
		);
		$this->assertEquals( 409, $response->get_status() );

		$error = $response->as_error();
		$this->assertSame( 'astro_checkout_rejected', $error->get_error_code() );
		$error_data = $error->get_error_data();
		$this->assertSame( '180000', $error_data['cart']['totals']['total_price'] );
	}

	/**
	 * POST /checkout rate-limits per session: the 11th attempt 429s.
	 */
	public function test_place_order_rate_limits(): void {
		$this->stub_store_api(
			static function ( $method, $path ) {
				if ( 'POST' === $method && '/cart' === $path ) {
					return array(
						'status'  => 200,
						'headers' => array( 'cart-token' => 'tok-123' ),
						'body'    => '{}',
					);
				}
				if ( 'POST' === $method && '/checkout' === $path ) {
					return array(
						'status' => 200,
						'body'   => wp_json_encode(
							array(
								'order_id'       => 1,
								'order_key'      => 'wc_order_x',
								'status'         => 'processing',
								'payment_result' => array( 'payment_status' => 'success' ),
							)
						),
					);
				}
				return null;
			}
		);

		$body = array(
			'payment_method'  => 'cod',
			'billingAddress'  => array(
				'firstName' => 'Jane',
				'lastName'  => 'Doe',
				'company'   => '',
				'address1'  => '1 Astro Way',
				'address2'  => '',
				'city'      => 'Velocity',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
				'phone'     => '',
			),
			'shippingAddress' => array(
				'firstName' => 'Jane',
				'lastName'  => 'Doe',
				'company'   => '',
				'address1'  => '1 Astro Way',
				'address2'  => '',
				'city'      => 'Velocity',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
				'phone'     => '',
			),
		);

		for ( $i = 0; $i < 10; $i++ ) {
			$response = rest_get_server()->dispatch( $this->checkout_request( 'POST', '/checkout', $body ) );
			$this->assertEquals( 200, $response->get_status() );
		}

		$response = rest_get_server()->dispatch( $this->checkout_request( 'POST', '/checkout', $body ) );
		$this->assertEquals( 429, $response->get_status() );
		$this->assertSame( 'astro_checkout_rate_limited', $response->as_error()->get_error_code() );
	}
}
