<?php
/**
 * WooCommerce Store API client — the bridge's server-side cart surface
 * (Proposal 108 Phase 2).
 *
 * The storefront never talks to the Store API directly: this client keeps
 * the Woo session server-side, keyed by a client-supplied cart-session id.
 * Guest carts ride the Store API `Cart-Token` header (the Store API's own
 * guest-session mechanism); no consumer keys are involved.
 *
 * Test seam: the `funiq_bridge_store_api_pre_response` filter may return a
 * canned response array `{ status, headers, body }` — when set, no HTTP
 * leaves the process (the repo's zero-HTTP test convention).
 *
 * @package NvoosAstroBridge\Woo
 */

namespace NvoosAstroBridge\Woo;

use WP_Error;

/**
 * Store API client.
 */
class StoreApiClient {

	/**
	 * Store API base path under the site's wp-json.
	 */
	private const BASE_PATH = '/wp-json/wc/store/v1';

	/**
	 * Woo session transient prefix for cart tokens.
	 */
	private const TOKEN_TRANSIENT = 'funiq_bridge_cart_token_';

	/**
	 * The Store API nonce action — WooCommerce validates the `wc_store_api`
	 * nonce on every cart/checkout mutation (the Blocks checkout mints the
	 * same nonce server-side).
	 */
	private const NONCE_ACTION = 'wc_store_api';

	/**
	 * Whether the WooCommerce Store API surface exists.
	 *
	 * A `class_exists()` check alone is a false positive in the test env (the
	 * bootstrap defines a stub `WooCommerce` class) — the product source layer
	 * uses the same two-part check. The filter is the test/override seam.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$available = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );

		/**
		 * Override the Store API availability check (tests, staging setups).
		 *
		 * @param bool $available Current availability.
		 */
		return (bool) apply_filters( 'funiq_bridge_store_api_available', $available );
	}

	/**
	 * Resolve the cart token for a client cart-session id.
	 *
	 * @param string $session Client-generated session id.
	 * @return string Empty string when no session exists yet.
	 */
	public function get_cart_token( string $session ): string {
		$token = get_transient( $this->token_key( $session ) );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Store the cart token for a client cart-session id (24 h TTL).
	 *
	 * @param string $session Client-generated session id.
	 * @param string $token   Store API cart token.
	 * @return void
	 */
	public function set_cart_token( string $session, string $token ): void {
		set_transient( $this->token_key( $session ), $token, DAY_IN_SECONDS );
	}

	/**
	 * Bootstrap a Woo cart session — POST /cart returns the cart state and
	 * issues the guest `Cart-Token` response header.
	 *
	 * @return array{token: string, cart: array<string, mixed>}|WP_Error
	 */
	public function bootstrap_cart() {
		$response = $this->request( 'POST', '/cart' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers = $response['headers'];
		$token   = '';
		if ( isset( $headers['cart-token'] ) ) {
			$token = is_array( $headers['cart-token'] ) ? (string) reset( $headers['cart-token'] ) : (string) $headers['cart-token'];
		}

		if ( '' === $token ) {
			return new WP_Error(
				'astro_cart_token_missing',
				'The WooCommerce Store API did not issue a cart token.',
				array( 'status' => 502 )
			);
		}

		return array(
			'token' => $token,
			'cart'  => $this->decode_cart_body( $response['body'] ),
		);
	}

	/**
	 * Add one line to the Woo cart.
	 *
	 * @param string                                              $token      Cart token.
	 * @param int                                                 $product_id Product id.
	 * @param int                                                 $quantity   Quantity.
	 * @param array<int, array{attribute: string, value: string}> $variation Ordered variation attributes.
	 * @return array{ok: bool, cart: array<string, mixed>, notices: array<int, array{type: string, text: string}>}|WP_Error
	 */
	public function add_item( string $token, int $product_id, int $quantity, array $variation = array() ) {
		$body = array(
			'id'       => $product_id,
			'quantity' => $quantity,
		);
		if ( ! empty( $variation ) ) {
			$body['variation'] = $variation;
		}

		return $this->cart_mutation( '/cart/add-item', $token, $body );
	}

	/**
	 * Apply a coupon code to the Woo cart.
	 *
	 * @param string $token Cart token.
	 * @param string $code  Coupon code.
	 * @return array{ok: bool, cart: array<string, mixed>, notices: array<int, array{type: string, text: string}>}|WP_Error
	 */
	public function apply_coupon( string $token, string $code ) {
		return $this->cart_mutation( '/cart/apply-coupon', $token, array( 'code' => $code ) );
	}

	/**
	 * Mint a Store API nonce (server-side only — the storefront never sees
	 * this; it rides the bridge session).
	 *
	 * @return string
	 */
	public function mint_nonce(): string {
		return (string) wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Read the current Woo cart state.
	 *
	 * @param string $token Cart token.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_cart( string $token ) {
		$response = $this->request( 'GET', '/cart', null, $token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			return new WP_Error(
				'astro_cart_store_api_error',
				$this->error_message( $response['body'] ),
				array( 'status' => $response['status'] )
			);
		}

		return $this->decode_cart_body( $response['body'] );
	}

	/**
	 * Read the checkout doc for the current cart — the Store API draft order
	 * with the persisted billing/shipping addresses and the chosen payment
	 * method (Proposal 110 Phase A).
	 *
	 * @param string $token Cart token.
	 * @param string $nonce Store API nonce.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_checkout( string $token, string $nonce ) {
		$response = $this->request( 'GET', '/checkout', null, $token, $nonce );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			return new WP_Error(
				'astro_checkout_store_api_error',
				$this->error_message( $response['body'] ),
				array( 'status' => $response['status'] >= 500 ? 502 : $response['status'] )
			);
		}

		return $this->decode_cart_body( $response['body'] );
	}

	/**
	 * Process the order and payment — POST /checkout drives the configured
	 * gateway exactly like the Blocks checkout (Proposal 110 Phase A).
	 *
	 * @param string               $token Cart token.
	 * @param string               $nonce Store API nonce.
	 * @param array<string, mixed> $body  Checkout payload (addresses, payment).
	 * @return array<string, mixed>|WP_Error Decoded Store API response.
	 */
	public function checkout( string $token, string $nonce, array $body ) {
		$response = $this->request( 'POST', '/checkout', $body, $token, $nonce );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			$decoded = $this->decode_cart_body( $response['body'] );
			$message = isset( $decoded['message'] ) && is_string( $decoded['message'] )
				? $decoded['message']
				: $this->error_message( $response['body'] );
			// Total mismatch (409) returns the refreshed cart so the storefront
			// can re-render — preserve the data, unlike other rejections.
			// The error DATA shape is flat (status + message + cart) so the
			// REST envelope serializes it without a nested data/data key.
			$data = array(
				'status'  => $response['status'] >= 500 ? 502 : $response['status'],
				'message' => $message,
			);
			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				$data['cart'] = $decoded['data'];
			}
			return new WP_Error(
				'astro_checkout_rejected',
				$message,
				$data
			);
		}

		return $this->decode_cart_body( $response['body'] );
	}

	/**
	 * Update the checkout customer — addresses are validated and the cart
	 * totals/shipping rates recalculated by Woo (Store API cart/update-customer).
	 *
	 * @param string               $token Cart token.
	 * @param string               $nonce Store API nonce.
	 * @param array<string, mixed> $body  billing_address / shipping_address.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_customer( string $token, string $nonce, array $body ) {
		$response = $this->request( 'POST', '/cart/update-customer', $body, $token, $nonce );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			return new WP_Error(
				'astro_checkout_customer_rejected',
				$this->error_message( $response['body'] ),
				array( 'status' => $response['status'] >= 500 ? 502 : $response['status'] )
			);
		}

		return $this->decode_cart_body( $response['body'] );
	}

	/**
	 * Select a shipping rate for a package (Store API cart/select-shipping-rate).
	 *
	 * @param string $token      Cart token.
	 * @param string $nonce      Store API nonce.
	 * @param string $package_id Shipping package id.
	 * @param string $rate_id    Selected rate id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function select_shipping_rate( string $token, string $nonce, string $package_id, string $rate_id ) {
		$response = $this->request(
			'POST',
			'/cart/select-shipping-rate',
			array(
				'package_id' => $package_id,
				'rate_id'    => $rate_id,
			),
			$token,
			$nonce
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			return new WP_Error(
				'astro_checkout_rate_rejected',
				$this->error_message( $response['body'] ),
				array( 'status' => $response['status'] >= 500 ? 502 : $response['status'] )
			);
		}

		return $this->decode_cart_body( $response['body'] );
	}

	/**
	 * A cart mutation: POST the body, surface Woo's error notices instead of
	 * failing the whole sync when the request is rejected (a dead variation
	 * or an invalid coupon should not block the handoff).
	 *
	 * @param string               $path  Store API path.
	 * @param string               $token Cart token.
	 * @param array<string, mixed> $body  Request body.
	 * @return array{ok: bool, cart: array<string, mixed>, notices: array<int, array{type: string, text: string}>}|WP_Error
	 */
	private function cart_mutation( string $path, string $token, array $body ) {
		$response = $this->request( 'POST', $path, $body, $token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$notices = array();
		if ( $response['status'] >= 400 ) {
			$notices[] = array(
				'type' => 'error',
				'text' => $this->error_message( $response['body'] ),
			);
		}

		return array(
			'ok'      => $response['status'] < 400,
			'cart'    => $this->decode_cart_body( $response['body'] ),
			'notices' => $notices,
		);
	}

	/**
	 * One Store API HTTP round-trip.
	 *
	 * @param string                    $method     HTTP method.
	 * @param string                    $path       Store API path (starts with /).
	 * @param array<string, mixed>|null $body       JSON body (POST only).
	 * @param string                    $cart_token Cart token header (empty = none).
	 * @param string                    $nonce      Store API nonce header (empty = none).
	 * @return array{status: int, headers: array<string, mixed>, body: string}|WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null, string $cart_token = '', string $nonce = '' ) {
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'accept'       => 'application/json',
				'content-type' => 'application/json',
			),
		);
		if ( '' !== $cart_token ) {
			$args['headers']['Cart-Token'] = $cart_token;
		}
		if ( '' !== $nonce ) {
			// Woo Blocks sends X-WC-Store-API-Nonce; the official curl examples
			// use `Nonce`. Sending both is accepted by the Store API validator.
			$args['headers']['Nonce']                = $nonce;
			$args['headers']['X-WC-Store-API-Nonce'] = $nonce;
		}
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		/**
		 * Test seam — a canned Store API response skips real HTTP entirely.
		 *
		 * The resolved request args ride along (additive, Proposal 110) so
		 * tests can assert the nonce/token headers and the outbound body;
		 * existing 3-arg callbacks are unaffected.
		 *
		 * @param array{status: int, headers: array<string, mixed>, body: string}|null $pre Canned response, or null to continue.
		 * @param string               $method HTTP method.
		 * @param string               $path   Store API path.
		 * @param array<string, mixed> $args   Resolved wp_remote_request args.
		 */
		$pre = apply_filters( 'funiq_bridge_store_api_pre_response', null, $method, $path, $args );
		if ( is_array( $pre ) && isset( $pre['status'], $pre['body'] ) ) {
			return array(
				'status'  => (int) $pre['status'],
				'headers' => isset( $pre['headers'] ) && is_array( $pre['headers'] ) ? $pre['headers'] : array(),
				'body'    => (string) $pre['body'],
			);
		}

		$url = home_url( self::BASE_PATH . $path );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => wp_remote_retrieve_headers( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Decode a Store API body into a cart-state array.
	 *
	 * @param string $raw Raw response body.
	 * @return array<string, mixed>
	 */
	private function decode_cart_body( string $raw ): array {
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Pull a human-readable message out of a Store API error body.
	 *
	 * @param string $raw Raw response body.
	 * @return string
	 */
	private function error_message( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			return $decoded['message'];
		}
		return 'The WooCommerce Store API rejected the request.';
	}

	/**
	 * Transient key for a client cart-session id.
	 *
	 * @param string $session Client-generated session id.
	 * @return string
	 */
	private function token_key( string $session ): string {
		return self::TOKEN_TRANSIENT . md5( $session );
	}
}
