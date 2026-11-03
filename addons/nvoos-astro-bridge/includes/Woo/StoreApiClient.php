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
	 * @return array{status: int, headers: array<string, mixed>, body: string}|WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null, string $cart_token = '' ) {
		/**
		 * Test seam — a canned Store API response skips real HTTP entirely.
		 *
		 * @param array{status: int, headers: array<string, mixed>, body: string}|null $pre Canned response, or null to continue.
		 * @param string $method HTTP method.
		 * @param string $path   Store API path.
		 */
		$pre = apply_filters( 'funiq_bridge_store_api_pre_response', null, $method, $path );
		if ( is_array( $pre ) && isset( $pre['status'], $pre['body'] ) ) {
			return array(
				'status'  => (int) $pre['status'],
				'headers' => isset( $pre['headers'] ) && is_array( $pre['headers'] ) ? $pre['headers'] : array(),
				'body'    => (string) $pre['body'],
			);
		}

		$url = home_url( self::BASE_PATH . $path );

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
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

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
