<?php
/**
 * Checkout controller — the storefront's server-side Woo checkout proxy
 * (Proposal 110 Phase A: the embedded checkout surface).
 *
 * The storefront never talks to the Store API directly and never renders
 * raw card fields: this controller relays the shopper session's cart
 * (Cart-Token, keyed by the client `X-Nvoos-Cart-Session` header) into the
 * Store API checkout endpoints with a bridge-minted nonce, so address
 * validation, shipping rates, tax, coupons and payment processing all run
 * Woo-side through the configured gateway — exactly like the Blocks
 * checkout. Card data never touches this process: the storefront collects
 * it inside the gateway's hosted fields (Stripe Elements) and passes only
 * the payment-method token in `payment_data`.
 *
 * Routes (both namespaces):
 *   GET  /checkout              — draft order + addresses + totals + rates
 *   POST /checkout/customer     — validate addresses, recalc totals/rates
 *   POST /checkout/shipping-rate— select a shipping rate
 *   POST /checkout/coupon       — apply a coupon code
 *   POST /checkout              — process the order and payment
 *
 * The routes are public (customer surface) — every mutation is bound to the
 * presented session token. POST /checkout additionally rate-limits per
 * session and per IP (it relays payment intents — a different trust class
 * from the cart sync).
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
 * Handles the /checkout routes.
 */
class CheckoutController extends BaseController {

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
	protected $rest_base = 'checkout';

	/**
	 * Rate-limit budget for POST /checkout (per session id, 10 minutes).
	 */
	private const RATE_SESSION_MAX = 10;

	/**
	 * Rate-limit budget for POST /checkout (per IP, 1 hour).
	 */
	private const RATE_IP_MAX = 30;

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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_checkout' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'place_order' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'payment_method' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_payment_method' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customer',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_customer' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/shipping-rate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'select_shipping_rate' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/coupon',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_coupon' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Per-session surfaces must never be marked public-cacheable — the B1
		// filter (priority 10) is excluded for /checkout (see BaseController),
		// and this filter (priority 30) pins the private/no-store headers.
		add_filter(
			'rest_post_dispatch',
			array( $this, 'set_no_store_headers' ),
			30,
			3
		);
	}

	/**
	 * GET /checkout — the merged checkout doc: Store API draft order
	 * (addresses, payment method) + cart state (items, totals, shipping
	 * rates, coupons). One app-facing round-trip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkout( $request ) {
		$session = $this->read_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$gates = $this->apply_gates();
		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		$resolved = $this->resolve_token( $session );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $token, $nonce ) = $resolved;

		$checkout = $this->client->get_checkout( $token, $nonce );
		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		$cart = $this->client->get_cart( $token );
		if ( is_wp_error( $cart ) ) {
			return $cart;
		}

		return rest_ensure_response( $this->merge_doc( $session, $checkout, $cart ) );
	}

	/**
	 * POST /checkout/customer — validate the addresses Woo-side; totals and
	 * shipping rates recalculate in the same call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_customer( $request ) {
		$session = $this->read_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$gates = $this->apply_gates();
		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		$resolved = $this->resolve_token( $session );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $token, $nonce ) = $resolved;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'astro_checkout_invalid_body',
				'A JSON object body is required.',
				array( 'status' => 400 )
			);
		}

		$payload = array();
		if ( isset( $body['billingAddress'] ) ) {
			$address = $this->normalize_address( $body['billingAddress'] );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			$payload['billing_address'] = $address;
		}
		if ( isset( $body['shippingAddress'] ) ) {
			$address = $this->normalize_address( $body['shippingAddress'] );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			$payload['shipping_address'] = $address;
		}
		if ( empty( $payload ) ) {
			return new WP_Error(
				'astro_checkout_invalid_body',
				'At least one of billingAddress / shippingAddress is required.',
				array( 'status' => 400 )
			);
		}

		$result = $this->client->update_customer( $token, $nonce, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$checkout = $this->client->get_checkout( $token, $nonce );
		if ( is_wp_error( $checkout ) ) {
			$checkout = array();
		}

		return rest_ensure_response( $this->merge_doc( $session, $checkout, $result ) );
	}

	/**
	 * POST /checkout/shipping-rate — select one rate for one package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function select_shipping_rate( $request ) {
		$session = $this->read_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$gates = $this->apply_gates();
		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		$resolved = $this->resolve_token( $session );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $token, $nonce ) = $resolved;

		$body       = $request->get_json_params();
		$package_id = isset( $body['packageId'] ) ? sanitize_text_field( (string) $body['packageId'] ) : '';
		$rate_id    = isset( $body['rateId'] ) ? sanitize_text_field( (string) $body['rateId'] ) : '';
		if ( '' === $package_id || '' === $rate_id ) {
			return new WP_Error(
				'astro_checkout_invalid_rate',
				'Both packageId and rateId are required.',
				array( 'status' => 400 )
			);
		}

		$result = $this->client->select_shipping_rate( $token, $nonce, $package_id, $rate_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$checkout = $this->client->get_checkout( $token, $nonce );
		if ( is_wp_error( $checkout ) ) {
			$checkout = array();
		}

		return rest_ensure_response( $this->merge_doc( $session, $checkout, $result ) );
	}

	/**
	 * POST /checkout/coupon — apply a coupon to the session cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_coupon( $request ) {
		$session = $this->read_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$gates = $this->apply_gates();
		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		$resolved = $this->resolve_token( $session );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $token, $nonce ) = $resolved;

		$body = $request->get_json_params();
		$code = isset( $body['code'] ) ? sanitize_text_field( (string) $body['code'] ) : '';
		if ( '' === $code ) {
			return new WP_Error(
				'astro_checkout_invalid_coupon',
				'A coupon code is required.',
				array( 'status' => 400 )
			);
		}

		$applied = $this->client->apply_coupon( $token, $code );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$checkout = $this->client->get_checkout( $token, $nonce );
		if ( is_wp_error( $checkout ) ) {
			$checkout = array();
		}

		$doc            = $this->merge_doc( $session, $checkout, $applied['cart'] ?? array() );
		$doc['notices'] = array_merge( $doc['notices'], $applied['notices'] ?? array() );

		return rest_ensure_response( $doc );
	}

	/**
	 * POST /checkout — process the order and payment through the configured
	 * gateway, then return the order id/key and the payment result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function place_order( $request ) {
		$session = $this->read_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$gates = $this->apply_gates();
		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		$limited = $this->rate_limit( $request, $session );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$resolved = $this->resolve_token( $session );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $token, $nonce ) = $resolved;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'astro_checkout_invalid_body',
				'A JSON object body is required.',
				array( 'status' => 400 )
			);
		}

		$payload = array(
			'payment_method' => $request->get_param( 'payment_method' ),
		);

		foreach ( array( 'billingAddress', 'shippingAddress' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || ! is_array( $body[ $field ] ) ) {
				return new WP_Error(
					'astro_checkout_invalid_' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $field ) ),
					sprintf( '%s is required.', $field ),
					array( 'status' => 400 )
				);
			}
			$address = $this->normalize_address( $body[ $field ] );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			$key             = 'billingAddress' === $field ? 'billing_address' : 'shipping_address';
			$payload[ $key ] = $address;
		}

		if ( isset( $body['paymentData'] ) ) {
			$payment_data = $this->normalize_payment_data( $body['paymentData'] );
			if ( is_wp_error( $payment_data ) ) {
				return $payment_data;
			}
			$payload['payment_data'] = $payment_data;
		}

		if ( isset( $body['customerNote'] ) ) {
			$payload['customer_note'] = sanitize_textarea_field( (string) $body['customerNote'] );
		}
		if ( ! empty( $body['createAccount'] ) ) {
			$payload['create_account'] = true;
		}
		if ( isset( $body['customerPassword'] ) && '' !== (string) $body['customerPassword'] ) {
			$payload['customer_password'] = (string) $body['customerPassword'];
		}
		// expected_total (minor units) is the tamper-proofing anchor: the Store
		// API rejects the order with 409 when the server-side total drifts from
		// what the shopper confirmed (currency-switch/price-change protection).
		if ( isset( $body['expectedTotal'] ) && is_string( $body['expectedTotal'] ) && '' !== $body['expectedTotal'] ) {
			$payload['expected_total'] = $body['expectedTotal'];
		}

		$result = $this->client->checkout( $token, $nonce, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payment_result = isset( $result['payment_result'] ) && is_array( $result['payment_result'] )
			? $result['payment_result']
			: array();

		$doc = array(
			'orderId'       => isset( $result['order_id'] ) ? (int) $result['order_id'] : 0,
			'orderKey'      => isset( $result['order_key'] ) ? sanitize_text_field( (string) $result['order_key'] ) : '',
			'status'        => isset( $result['status'] ) ? sanitize_text_field( (string) $result['status'] ) : '',
			'paymentResult' => array(
				'paymentStatus'  => isset( $payment_result['payment_status'] ) ? sanitize_text_field( (string) $payment_result['payment_status'] ) : '',
				'redirectUrl'    => isset( $payment_result['redirect_url'] ) ? esc_url_raw( (string) $payment_result['redirect_url'] ) : '',
				'paymentDetails' => isset( $payment_result['payment_details'] ) && is_array( $payment_result['payment_details'] ) ? $payment_result['payment_details'] : array(),
			),
			'notices'       => array(),
		);

		// The order is placed — the Woo session cart is consumed. Invalidate
		// the cached token so the next sync bootstraps a fresh cart.
		$this->client->set_cart_token( $session, '' );

		return rest_ensure_response( $doc );
	}

	/**
	 * The fail-closed gates shared by every checkout route (the cart-sync
	 * gates, Proposal 108 Phase 2).
	 *
	 * @return true|WP_Error
	 */
	private function apply_gates() {
		if ( ! $this->client->is_available() ) {
			return new WP_Error(
				'astro_checkout_woo_missing',
				'The embedded checkout requires WooCommerce with the Store API.',
				array( 'status' => 501 )
			);
		}

		$source = get_option( Schema::OPTION_PRODUCT_SOURCE, 'native' );
		if ( 'native' === $source ) {
			return new WP_Error(
				'astro_checkout_native_mode',
				'The embedded checkout is disabled in native product mode — use the checkout URL handoff.',
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Resolve (and bootstrap) the session cart token + nonce.
	 *
	 * @param string $session Client cart-session id.
	 * @return array{string, string}|WP_Error
	 */
	private function resolve_token( string $session ) {
		$token = $this->client->get_cart_token( $session );
		if ( '' === $token ) {
			$bootstrapped = $this->client->bootstrap_cart();
			if ( is_wp_error( $bootstrapped ) ) {
				return $bootstrapped;
			}
			$token = $bootstrapped['token'];
			$this->client->set_cart_token( $session, $token );
		}

		return array( $token, $this->client->mint_nonce() );
	}

	/**
	 * Merge the Store API checkout doc + cart state into the app-facing doc.
	 *
	 * @param string               $session  Client cart-session id.
	 * @param array<string, mixed> $checkout Store API checkout doc (may be empty).
	 * @param array<string, mixed> $cart     Store API cart state.
	 * @return array<string, mixed>
	 */
	private function merge_doc( string $session, array $checkout, array $cart ): array {
		$totals = isset( $cart['totals'] ) && is_array( $cart['totals'] ) ? $cart['totals'] : array();

		$items = array();
		if ( isset( $cart['items'] ) && is_array( $cart['items'] ) ) {
			foreach ( $cart['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$prices  = isset( $item['prices'] ) && is_array( $item['prices'] ) ? $item['prices'] : array();
				$items[] = array(
					'key'       => isset( $item['key'] ) ? sanitize_text_field( (string) $item['key'] ) : '',
					'productId' => (int) ( $item['id'] ?? 0 ),
					'name'      => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
					'qty'       => (int) ( $item['quantity'] ?? 0 ),
					'price'     => (float) ( $prices['price'] ?? 0 ),
				);
			}
		}

		$rates = array();
		if ( isset( $cart['shipping_rates'] ) && is_array( $cart['shipping_rates'] ) ) {
			foreach ( $cart['shipping_rates'] as $package ) {
				if ( ! is_array( $package ) ) {
					continue;
				}
				$normalized = array(
					'packageId' => isset( $package['package_id'] ) ? sanitize_text_field( (string) $package['package_id'] ) : '',
					'name'      => sanitize_text_field( (string) ( $package['name'] ?? '' ) ),
					'rates'     => array(),
				);
				if ( isset( $package['shipping_rates'] ) && is_array( $package['shipping_rates'] ) ) {
					foreach ( $package['shipping_rates'] as $rate ) {
						if ( ! is_array( $rate ) ) {
							continue;
						}
						$normalized['rates'][] = array(
							'rateId'      => isset( $rate['rate_id'] ) ? sanitize_text_field( (string) $rate['rate_id'] ) : '',
							'name'        => sanitize_text_field( (string) ( $rate['name'] ?? '' ) ),
							'description' => sanitize_text_field( (string) ( $rate['description'] ?? '' ) ),
							'price'       => (string) ( $rate['price'] ?? '0' ),
							'selected'    => ! empty( $rate['selected'] ),
						);
					}
				}
				$rates[] = $normalized;
			}
		}

		$coupons = array();
		if ( isset( $cart['coupons'] ) && is_array( $cart['coupons'] ) ) {
			foreach ( $cart['coupons'] as $coupon ) {
				if ( ! is_array( $coupon ) ) {
					continue;
				}
				$coupons[] = array(
					'code'     => sanitize_text_field( (string) ( $coupon['code'] ?? '' ) ),
					'discount' => (string) ( $coupon['totals']['total_discount'] ?? '0' ),
				);
			}
		}

		$billing  = isset( $checkout['billing_address'] ) && is_array( $checkout['billing_address'] ) ? $this->denormalize_address( $checkout['billing_address'] ) : null;
		$shipping = isset( $checkout['shipping_address'] ) && is_array( $checkout['shipping_address'] ) ? $this->denormalize_address( $checkout['shipping_address'] ) : null;

		return array(
			'session'         => $session,
			'orderId'         => isset( $checkout['order_id'] ) ? (int) $checkout['order_id'] : 0,
			'orderStatus'     => isset( $checkout['status'] ) ? sanitize_text_field( (string) $checkout['status'] ) : 'checkout-draft',
			'items'           => $items,
			'totals'          => array(
				'totalItems'    => (int) ( $totals['total_items'] ?? 0 ),
				'totalPrice'    => (string) ( $totals['total_price'] ?? '0' ),
				'shippingTotal' => (string) ( $totals['total_shipping'] ?? '' ),
				'taxTotal'      => (string) ( $totals['total_tax'] ?? '' ),
			),
			'billingAddress'  => $billing,
			'shippingAddress' => $shipping,
			'paymentMethod'   => isset( $checkout['payment_method'] ) ? sanitize_text_field( (string) $checkout['payment_method'] ) : '',
			'shippingRates'   => $rates,
			'needsShipping'   => ! empty( $cart['needs_shipping'] ),
			'coupons'         => $coupons,
			'notices'         => array(),
		);
	}

	/**
	 * Normalize an app-facing (camelCase) address into the Store API
	 * snake_case address — string coercion, no length policing (Woo validates).
	 *
	 * @param mixed $raw Raw address value.
	 * @return array<string, string>|WP_Error
	 */
	private function normalize_address( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'astro_checkout_invalid_address',
				'Address fields must be an object.',
				array( 'status' => 400 )
			);
		}

		$map = array(
			'firstName' => 'first_name',
			'lastName'  => 'last_name',
			'company'   => 'company',
			'address1'  => 'address_1',
			'address2'  => 'address_2',
			'city'      => 'city',
			'state'     => 'state',
			'postcode'  => 'postcode',
			'country'   => 'country',
			'phone'     => 'phone',
			'email'     => 'email',
		);

		$address = array();
		foreach ( $map as $app => $store ) {
			$address[ $store ] = isset( $raw[ $app ] ) ? sanitize_text_field( (string) $raw[ $app ] ) : '';
		}
		if ( '' !== $address['country'] ) {
			$address['country'] = strtoupper( $address['country'] );
		}

		return $address;
	}

	/**
	 * Denormalize a Store API snake_case address into the app camelCase shape.
	 *
	 * @param array<string, mixed> $address Store API address.
	 * @return array<string, string>
	 */
	private function denormalize_address( array $address ): array {
		$map = array(
			'first_name' => 'firstName',
			'last_name'  => 'lastName',
			'company'    => 'company',
			'address_1'  => 'address1',
			'address_2'  => 'address2',
			'city'       => 'city',
			'state'      => 'state',
			'postcode'   => 'postcode',
			'country'    => 'country',
			'phone'      => 'phone',
			'email'      => 'email',
		);

		$out = array();
		foreach ( $map as $store => $app ) {
			$out[ $app ] = isset( $address[ $store ] ) ? sanitize_text_field( (string) $address[ $store ] ) : '';
		}
		return $out;
	}

	/**
	 * Normalize the payment_data passthrough — key/value pairs with string
	 * values only (gateway tokens ride here, never card numbers).
	 *
	 * @param mixed $raw Raw payment_data value.
	 * @return array<int, array{key: string, value: string}>|WP_Error
	 */
	private function normalize_payment_data( $raw ) {
		if ( ! is_array( $raw ) || count( $raw ) > 64 ) {
			return new WP_Error(
				'astro_checkout_invalid_payment_data',
				'paymentData must be an array of {key, value} pairs.',
				array( 'status' => 400 )
			);
		}
		$data = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key'] ) || ! isset( $entry['value'] ) ) {
				return new WP_Error(
					'astro_checkout_invalid_payment_data',
					'Each paymentData entry needs key and value.',
					array( 'status' => 400 )
				);
			}
			if ( ! is_scalar( $entry['value'] ) && null !== $entry['value'] ) {
				return new WP_Error(
					'astro_checkout_invalid_payment_data',
					'paymentData values must be scalar.',
					array( 'status' => 400 )
				);
			}
			// Booleans ride through as JSON booleans (gateways read them with
			// wc_string_to_bool); everything else is a string.
			$data[] = array(
				'key'   => sanitize_text_field( (string) $entry['key'] ),
				'value' => is_bool( $entry['value'] ) ? $entry['value'] : (string) $entry['value'],
			);
		}
		return $data;
	}

	/**
	 * Validate the payment_method argument — a short, non-empty slug.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_payment_method( $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 64;
	}

	/**
	 * Read and validate the client cart-session header (the cart-sync rule).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private function read_session( $request ) {
		$session = $request->get_header( 'x-nvoos-cart-session' );
		if ( ! is_string( $session ) || '' === $session ) {
			return new WP_Error(
				'astro_checkout_invalid_session',
				'The X-Nvoos-Cart-Session header is required.',
				array( 'status' => 400 )
			);
		}
		$session = sanitize_text_field( $session );
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,64}$/', $session ) ) {
			return new WP_Error(
				'astro_checkout_invalid_session',
				'The cart session id is malformed.',
				array( 'status' => 400 )
			);
		}
		return $session;
	}

	/**
	 * Rate-limit POST /checkout: per-session (10 / 10 min) and per-IP
	 * (30 / hour) transient budgets. Payment-relaying surface — a runaway
	 * client must 429 before it can probe the gateway.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $session Client cart-session id.
	 * @return true|WP_Error
	 */
	private function rate_limit( $request, string $session ) {
		$now = time();

		$session_key   = 'funiq_bridge_checkout_rate_' . md5( $session );
		$session_count = (int) get_transient( $session_key );
		if ( $session_count >= self::RATE_SESSION_MAX ) {
			return $this->rate_limited();
		}
		set_transient( $session_key, $session_count + 1, 10 * MINUTE_IN_SECONDS );

		$ip = $request->get_header( 'x_forwarded_for' );
		if ( ! is_string( $ip ) || '' === $ip ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		}
		$ip = substr( (string) $ip, 0, 64 );
		if ( '' !== $ip ) {
			$ip_key   = 'funiq_bridge_checkout_rate_ip_' . md5( $ip );
			$ip_count = (int) get_transient( $ip_key );
			if ( $ip_count >= self::RATE_IP_MAX ) {
				return $this->rate_limited();
			}
			set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );
		}

		return true;
	}

	/**
	 * The typed 429 for an exhausted budget.
	 *
	 * @return WP_Error
	 */
	private function rate_limited(): WP_Error {
		return new WP_Error(
			'astro_checkout_rate_limited',
			'Too many checkout attempts — please wait before trying again.',
			array( 'status' => 429 )
		);
	}

	/**
	 * Never mark checkout responses cacheable (B1 already excludes the route;
	 * this pins the headers against future filter drift).
	 *
	 * @param WP_REST_Response $response REST response (possibly not ours).
	 * @param WP_REST_Server   $server   REST server instance.
	 * @param WP_REST_Request  $request  Original request.
	 * @return WP_REST_Response
	 */
	public function set_no_store_headers( $response, $server, $request ) {
		$route = $request->get_route();
		$ours  = false;
		foreach ( array( Schema::REST_NAMESPACE, Schema::REST_NAMESPACE_NV ) as $namespace ) {
			if ( 0 === strpos( $route, '/' . $namespace . '/checkout' ) ) {
				$ours = true;
				break;
			}
		}
		if ( ! $ours || ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
