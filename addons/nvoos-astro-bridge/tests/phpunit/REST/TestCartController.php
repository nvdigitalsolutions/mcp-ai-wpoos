<?php
/**
 * PHPUnit tests for the cart sync controller (Proposal 108 Phase 2):
 * the fail-closed gates (Woo missing, native mode, bad session, bad lines)
 * and the full sync flow through the zero-HTTP Store API seam.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;

/**
 * Cart sync REST tests.
 *
 * @covers \NvoosAstroBridge\REST\CartController
 * @covers \NvoosAstroBridge\Woo\StoreApiClient
 * @group nvoos-astro-bridge
 */
class TestCartController extends WP_Test_REST_TestCase {

	/**
	 * Set up test state: REST routes, clean options, admin user.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		update_option( Schema::OPTION_PRODUCT_SOURCE, 'woo' );
		add_filter( 'funiq_bridge_store_api_available', '__return_true' );
		delete_option( Schema::OPTION_WOO_SSO_ENABLED );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
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
	 * Admin user id used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Build a sync request.
	 *
	 * @param array<string, mixed> $body    Request body.
	 * @param string               $session Session header value.
	 * @return WP_REST_Request
	 */
	private function sync_request( array $body, string $session = 'c3a2f1e0-1111-4b22-9c33-000000000001' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/cart/sync' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-nvoos-cart-session', $session );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * Canned Store API responses via a per-call resolver (zero-HTTP seam).
	 *
	 * @param callable $resolver fn( string $method, string $path ): array|null — canned response or null to fall through.
	 * @return void
	 */
	private function stub_store_api( callable $resolver ): void {
		add_filter(
			'funiq_bridge_store_api_pre_response',
			static function ( $pre, $method, $path ) use ( $resolver ) {
				$entry = $resolver( $method, $path );
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
			3
		);
	}

	/**
	 * Without Woo (availability seam off), the proxy fails closed with 501.
	 */
	public function test_woo_missing_returns_501(): void {
		add_filter( 'funiq_bridge_store_api_available', '__return_false' );

		$response = rest_get_server()->dispatch( $this->sync_request( array( 'lines' => array() ) ) );
		$this->assertEquals( 501, $response->get_status() );
		$this->assertSame( 'astro_cart_woo_missing', $response->as_error()->get_error_code() );
	}

	/**
	 * Native product mode disables the proxy (400) — the URL handoff owns it.
	 */
	public function test_native_mode_returns_400(): void {
		update_option( Schema::OPTION_PRODUCT_SOURCE, 'native' );

		$response = rest_get_server()->dispatch( $this->sync_request( array( 'lines' => array() ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_cart_native_mode', $response->as_error()->get_error_code() );
	}

	/**
	 * A missing or malformed session header is a typed 400.
	 */
	public function test_session_header_required(): void {
		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/cart/sync' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'lines' => array() ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_cart_invalid_session', $response->as_error()->get_error_code() );

		$response = rest_get_server()->dispatch( $this->sync_request( array( 'lines' => array() ), 'bad session!' ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_cart_invalid_session', $response->as_error()->get_error_code() );
	}

	/**
	 * Malformed lines are rejected by the arg validator (typed 400).
	 */
	public function test_invalid_lines_rejected(): void {
		$response = rest_get_server()->dispatch(
			$this->sync_request(
				array(
					'lines' => array(
						array( 'productId' => 1, 'qty' => 0 ),
					),
				)
			)
		);
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * The full sync flow: bootstrap → add items (variation attributes
	 * included) → coupon → normalized cart back, token cached per session.
	 */
	public function test_sync_full_flow(): void {
		$calls = array();
		$cart_state = array(
			'items'  => array(
				array(
					'key'      => 'abc',
					'id'       => 3,
					'name'     => 'Halo Chair',
					'quantity' => 2,
					'prices'   => array( 'price' => '849' ),
				),
			),
			'totals' => array( 'total_items' => '2', 'total_price' => '169800' ),
		);
		$this->stub_store_api(
			static function ( $method, $path ) use ( $cart_state ) {
				if ( 'POST' === $method && '/cart' === $path ) {
					return array(
						'status'  => 200,
						'headers' => array( 'cart-token' => 'tok-123' ),
						'body'    => '{}',
					);
				}
				if ( 'GET' === $method && '/cart' === $path ) {
					return array( 'status' => 200, 'body' => wp_json_encode( $cart_state ) );
				}
				if ( '/cart/add-item' === $path || '/cart/apply-coupon' === $path ) {
					return array( 'status' => 200, 'body' => wp_json_encode( $cart_state ) );
				}
				return null;
			}
		);

		add_filter(
			'funiq_bridge_store_api_pre_response',
			static function ( $pre, $method, $path ) use ( &$calls ) {
				$calls[] = array( $method, $path );
				return $pre;
			},
			20,
			3
		);

		$response = rest_get_server()->dispatch(
			$this->sync_request(
				array(
					'lines'  => array(
						array(
							'productId' => 3,
							'qty'       => 2,
							'variantId' => 303,
							'attributes' => array(
								array( 'name' => 'Size', 'value' => 'Large' ),
							),
						),
					),
					'coupon' => 'SAVE10',
				)
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'session', $data );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 3, $data['items'][0]['productId'] );
		$this->assertSame( 'Halo Chair', $data['items'][0]['name'] );
		$this->assertSame( 2, $data['totals']['totalItems'] );
		$this->assertSame( '169800', $data['totals']['totalPrice'] );

		// The token is cached for the session — a second sync skips bootstrap.
		$this->assertNotFalse( get_transient( 'funiq_bridge_cart_token_' . md5( 'c3a2f1e0-1111-4b22-9c33-000000000001' ) ) );

		$add_item_call = null;
		foreach ( $calls as $call ) {
			if ( '/cart/add-item' === $call[1] ) {
				$add_item_call = $call;
			}
		}
		$this->assertNotNull( $add_item_call );
	}

	/**
	 * A rejected add-item surfaces as an error notice, not a failed sync.
	 */
	public function test_add_item_error_becomes_notice(): void {
		$this->stub_store_api(
			static function ( $method, $path ) {
				if ( 'POST' === $method && '/cart' === $path ) {
					return array(
						'status'  => 200,
						'headers' => array( 'cart-token' => 'tok-123' ),
						'body'    => '{}',
					);
				}
				if ( 'GET' === $method && '/cart' === $path ) {
					return array( 'status' => 200, 'body' => wp_json_encode( array( 'items' => array(), 'totals' => array() ) ) );
				}
				if ( '/cart/add-item' === $path ) {
					return array(
						'status' => 400,
						'body'   => wp_json_encode( array( 'message' => 'Sorry, this product cannot be purchased.' ) ),
					);
				}
				return null;
			}
		);

		$response = rest_get_server()->dispatch(
			$this->sync_request(
				array(
					'lines' => array(
						array( 'productId' => 99, 'qty' => 1 ),
					),
				)
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['notices'] );
		$this->assertSame( 'error', $data['notices'][0]['type'] );
		$this->assertStringContainsString( 'cannot be purchased', $data['notices'][0]['text'] );
	}
}
