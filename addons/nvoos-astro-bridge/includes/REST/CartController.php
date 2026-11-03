<?php
/**
 * Cart sync controller — the storefront's server-side Woo cart proxy
 * (Proposal 108 Phase 2).
 *
 * POST /nvoos-commerce/v1/cart/sync reconciles the storefront's local cart
 * into the WooCommerce session cart via the Store API, so the hosted
 * checkout receives EVERY line — variants included — without the legacy
 * `?add-to-cart=` URL form. Guest carts ride the Store API cart token,
 * keyed by a client-generated `X-Nvoos-Cart-Session` header (unguessable
 * UUID; the same trust level as the Store API's own token).
 *
 * The route is public (customer surface) — it mutates only the cart bound
 * to the presented session token, never the store.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Woo\StoreApiClient;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles POST /nvoos-commerce/v1/cart/sync.
 */
class CartController extends BaseController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'cart/sync';

	/**
	 * The Store API client.
	 *
	 * @var StoreApiClient
	 */
	private $client;

	/**
	 * Wire the client.
	 */
	public function __construct() {
		$this->client = new StoreApiClient();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_cart' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'lines' => array(
							'required'          => true,
							'type'              => 'array',
							'validate_callback' => array( $this, 'validate_lines' ),
						),
					),
				),
			)
		);
	}

	/**
	 * POST /cart/sync — reconcile the local cart into Woo's session cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_cart( $request ) {
		$session = $this->read_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! $this->client->is_available() ) {
			return new WP_Error(
				'astro_cart_woo_missing',
				'The server-side cart requires WooCommerce with the Store API.',
				array( 'status' => 501 )
			);
		}

		$source = get_option( Schema::OPTION_PRODUCT_SOURCE, 'native' );
		if ( 'native' === $source ) {
			return new WP_Error(
				'astro_cart_native_mode',
				'The storefront cart proxy is disabled in native product mode — use the checkout URL handoff.',
				array( 'status' => 400 )
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['lines'] ) || ! is_array( $body['lines'] ) ) {
			return new WP_Error(
				'astro_cart_invalid_body',
				'A JSON body with a lines array is required.',
				array( 'status' => 400 )
			);
		}

		$token = $this->client->get_cart_token( $session );
		if ( '' === $token ) {
			$bootstrapped = $this->client->bootstrap_cart();
			if ( is_wp_error( $bootstrapped ) ) {
				return $bootstrapped;
			}
			$token = $bootstrapped['token'];
			$this->client->set_cart_token( $session, $token );
		}

		$notices = array();
		foreach ( $body['lines'] as $line ) {
			$added = $this->client->add_item(
				$token,
				(int) $line['productId'],
				(int) $line['qty'],
				$this->variation_from_attributes( $line['attributes'] ?? array() )
			);
			if ( is_wp_error( $added ) ) {
				return $added;
			}
			$notices = array_merge( $notices, $added['notices'] );
		}

		$coupon = isset( $body['coupon'] ) ? sanitize_text_field( (string) $body['coupon'] ) : '';
		if ( '' !== $coupon ) {
			$applied = $this->client->apply_coupon( $token, $coupon );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$notices = array_merge( $notices, $applied['notices'] );
		}

		$cart = $this->client->get_cart( $token );
		if ( is_wp_error( $cart ) ) {
			return $cart;
		}

		return rest_ensure_response(
			array(
				'session' => $session,
				'items'   => $this->normalize_items( $cart['items'] ?? array() ),
				'totals'  => $this->normalize_totals( $cart['totals'] ?? array() ),
				'notices' => $notices,
			)
		);
	}

	/**
	 * Validate the lines argument shape.
	 *
	 * @param mixed $lines Raw lines value.
	 * @return bool
	 */
	public function validate_lines( $lines ): bool {
		if ( ! is_array( $lines ) || count( $lines ) > 100 ) {
			return false;
		}
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				return false;
			}
			if ( ! isset( $line['productId'] ) || ! is_int( $line['productId'] ) || $line['productId'] < 1 ) {
				return false;
			}
			if ( ! isset( $line['qty'] ) || ! is_int( $line['qty'] ) || $line['qty'] < 1 || $line['qty'] > 99 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read and validate the client cart-session header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private function read_session( $request ) {
		$session = $request->get_header( 'x-nvoos-cart-session' );
		if ( ! is_string( $session ) || '' === $session ) {
			return new WP_Error(
				'astro_cart_invalid_session',
				'The X-Nvoos-Cart-Session header is required.',
				array( 'status' => 400 )
			);
		}
		$session = sanitize_text_field( $session );
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,64}$/', $session ) ) {
			return new WP_Error(
				'astro_cart_invalid_session',
				'The cart session id is malformed.',
				array( 'status' => 400 )
			);
		}
		return $session;
	}

	/**
	 * Map the app's ordered attributes onto the Store API variation shape.
	 *
	 * @param array<int, mixed> $attributes App-side attributes (name/value).
	 * @return array<int, array{attribute: string, value: string}>
	 */
	private function variation_from_attributes( array $attributes ): array {
		$variation = array();
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$name  = sanitize_text_field( (string) ( $attribute['name'] ?? '' ) );
			$value = sanitize_text_field( (string) ( $attribute['value'] ?? '' ) );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$variation[] = array(
				'attribute' => $name,
				'value'     => $value,
			);
		}
		return $variation;
	}

	/**
	 * Normalize Store API items to the app cart-item shape.
	 *
	 * @param array<int, mixed> $items Store API items.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_items( array $items ): array {
		$normalized = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$prices     = isset( $item['prices'] ) && is_array( $item['prices'] ) ? $item['prices'] : array();
			$normalized[] = array(
				'key'       => isset( $item['key'] ) ? sanitize_text_field( (string) $item['key'] ) : '',
				'productId' => (int) ( $item['id'] ?? 0 ),
				'name'      => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'qty'       => (int) ( $item['quantity'] ?? 0 ),
				'price'     => (float) ( $prices['price'] ?? 0 ),
			);
		}
		return $normalized;
	}

	/**
	 * Normalize Store API totals (strings in Woo's minor-unit form — passed
	 * through for display hints, never trusted for payment).
	 *
	 * @param array<string, mixed> $totals Store API totals.
	 * @return array{totalItems: int, totalPrice: string}
	 */
	private function normalize_totals( array $totals ): array {
		return array(
			'totalItems' => (int) ( $totals['total_items'] ?? 0 ),
			'totalPrice' => (string) ( $totals['total_price'] ?? '' ),
		);
	}
}
