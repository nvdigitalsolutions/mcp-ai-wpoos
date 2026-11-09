<?php
/**
 * Shop globals REST controller — checkout handoff + currency display hints
 * (Proposal 106 §1.3).
 *
 * GET is public; PATCH is gated by the manage capability. Same short cache
 * TTL (s-maxage=60) as the theme policy doc.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles GET/PATCH /funiq/v1/globals/shop.
 */
class ShopGlobalsController extends BaseController {

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
	protected $rest_base = 'globals/shop';

	/**
	 * PATCHable fields — anything else is rejected with a typed 400.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = array( 'checkoutUrl', 'cartUrl', 'returnUrl', 'currency', 'currencySymbol' );

	/**
	 * URL fields — all three ride the 108 H1 same-site/allowlist gate.
	 *
	 * @var array<int, string>
	 */
	private const URL_FIELDS = array( 'checkoutUrl', 'cartUrl', 'returnUrl' );

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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Short cache TTL (60s) for this route — priority 20 so it overrides
		// the B1 filter (priority 10) after rest_post_dispatch.
		add_filter(
			'rest_post_dispatch',
			array( BaseController::class, 'set_short_cache_headers_on_public_gets' ),
			20,
			3
		);
	}

	/**
	 * GET /funiq/v1/globals/shop — the normalized shop doc.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$stored = get_option( Schema::OPTION_SHOP, Schema::shop_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::shop_defaults();

		return rest_ensure_response( $this->normalize_doc( $stored ) );
	}

	/**
	 * PATCH /funiq/v1/globals/shop — partial update, full doc back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'astro_shop_invalid_body',
				'A JSON object body is required.',
				array( 'status' => 400 )
			);
		}

		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, self::FIELDS, true ) ) {
				return new WP_Error(
					'astro_shop_invalid_field',
					sprintf( 'Unknown field "%s".', sanitize_text_field( (string) $key ) ),
					array( 'status' => 400 )
				);
			}
		}

		foreach ( self::URL_FIELDS as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}
			$snake = strtolower( (string) preg_replace( '/([a-z])([A-Z])/', '$1_$2', $field ) );
			if ( ! $this->is_valid_http_url( $data[ $field ] ) ) {
				return new WP_Error(
					'astro_shop_invalid_' . $snake,
					sprintf( '%s must be an absolute http(s) URL.', $field ),
					array( 'status' => 400 )
				);
			}
			if ( ! $this->is_allowed_shop_url( $data[ $field ] ) ) {
				return new WP_Error(
					'astro_shop_' . $snake . '_not_allowed',
					sprintf( '%s must point at this site or an allowlisted host.', $field ),
					array( 'status' => 400 )
				);
			}
		}
		if ( isset( $data['currency'] ) && ( ! is_string( $data['currency'] ) || 1 !== preg_match( '/^[A-Za-z]{3}$/', $data['currency'] ) ) ) {
			return new WP_Error(
				'astro_shop_invalid_currency',
				'currency must be a 3-letter ISO code.',
				array( 'status' => 400 )
			);
		}
		if ( isset( $data['currencySymbol'] ) && ( ! is_string( $data['currencySymbol'] ) || '' === $data['currencySymbol'] || strlen( $data['currencySymbol'] ) > 8 ) ) {
			return new WP_Error(
				'astro_shop_invalid_currency_symbol',
				'currencySymbol must be a short non-empty string.',
				array( 'status' => 400 )
			);
		}

		$stored = get_option( Schema::OPTION_SHOP, Schema::shop_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::shop_defaults();
		$doc    = $this->normalize_doc( $stored );

		if ( isset( $data['checkoutUrl'] ) ) {
			$doc['checkoutUrl'] = esc_url_raw( $data['checkoutUrl'] );
		}
		if ( isset( $data['cartUrl'] ) ) {
			$doc['cartUrl'] = esc_url_raw( $data['cartUrl'] );
		}
		if ( isset( $data['returnUrl'] ) ) {
			$doc['returnUrl'] = esc_url_raw( $data['returnUrl'] );
		}
		if ( isset( $data['currency'] ) ) {
			$doc['currency'] = strtoupper( sanitize_text_field( $data['currency'] ) );
		}
		if ( isset( $data['currencySymbol'] ) ) {
			$doc['currencySymbol'] = sanitize_text_field( $data['currencySymbol'] );
		}
		$doc['updatedAt'] = gmdate( 'c' );

		update_option( Schema::OPTION_SHOP, $doc );

		return $this->get_item( $request );
	}

	/**
	 * Permission check for PATCH.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			return new WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Validate an absolute http(s) URL without DNS resolution.
	 *
	 * `wp_http_validate_url()` resolves the host, which fails for intranet
	 * hosts and in CI/test environments without DNS (the Pro schedule
	 * manager documents the same gotcha) — filter_var + scheme check is the
	 * repo's canonical substitute.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_valid_http_url( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		return false !== filter_var( $value, FILTER_VALIDATE_URL )
			&& 1 === preg_match( '#^https?://#i', $value );
	}

	/**
	 * Whether a shop URL may be a checkout/cart handoff target (108 H1).
	 *
	 * Open-redirect hardening: same-site by default; external hosts must be
	 * explicitly allowlisted via the `funiq_bridge_checkout_url_allowlist`
	 * filter. userinfo and non-standard ports are rejected outright.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_allowed_shop_url( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
			return false;
		}

		$host        = strtolower( (string) $parts['host'] );
		$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$public_host = strtolower( (string) wp_parse_url( Schema::public_origin(), PHP_URL_HOST ) );

		/**
		 * Extra hosts allowed as checkout/cart handoff targets.
		 *
		 * @param array<int, string> $hosts Lowercase hostnames.
		 */
		$allowlist = apply_filters( 'funiq_bridge_checkout_url_allowlist', array() );
		$allowlist = is_array( $allowlist ) ? array_map( 'strtolower', array_filter( $allowlist, 'is_string' ) ) : array();

		// Proposal 135: the configured public origin is a first-class same-site
		// host — with the CMS on a subdomain, `home_url()` alone would reject
		// every legitimate frontend checkout URL.
		return $host === $site_host || $host === $public_host || in_array( $host, $allowlist, true );
	}

	/**
	 * Normalize a stored doc — every field coerced/fallbacked to the
	 * contract shape. Read-only extension fields (108 §2–3): the product
	 * source mode and the enabled payment-method list.
	 *
	 * @param array<string, mixed> $doc Stored document.
	 * @return array{checkoutUrl: string, cartUrl: string, returnUrl: string, currency: string, currencySymbol: string, updatedAt: string, productsSource: string, paymentMethods: array<int, array{id: string, title: string}>}
	 */
	private function normalize_doc( array $doc ): array {
		$defaults = Schema::shop_defaults();

		$url = static function ( $value, string $fallback ): string {
			if ( ! is_string( $value ) ) {
				return $fallback;
			}
			$clean = esc_url_raw( $value );
			return '' !== $clean ? $clean : $fallback;
		};

		return array(
			'checkoutUrl'          => $url( $doc['checkoutUrl'] ?? null, $defaults['checkoutUrl'] ),
			'cartUrl'              => $url( $doc['cartUrl'] ?? null, $defaults['cartUrl'] ),
			'returnUrl'            => $url( $doc['returnUrl'] ?? null, $defaults['returnUrl'] ),
			'currency'             => ( is_string( $doc['currency'] ?? null ) && 1 === preg_match( '/^[A-Za-z]{3}$/', $doc['currency'] ) )
				? strtoupper( $doc['currency'] )
				: $defaults['currency'],
			'currencySymbol'       => ( is_string( $doc['currencySymbol'] ?? null ) && '' !== $doc['currencySymbol'] )
				? $doc['currencySymbol']
				: $defaults['currencySymbol'],
			'updatedAt'            => ( is_string( $doc['updatedAt'] ?? null ) && '' !== $doc['updatedAt'] )
				? $doc['updatedAt']
				: gmdate( 'c' ),
			'productsSource'       => $this->resolve_products_source(),
			'paymentMethods'       => $this->payment_methods(),
			// Proposal 110 read-only extensions: the Stripe publishable key (a
			// PUBLIC key by design — safe to expose; empty when Stripe is not
			// the active gateway) and Woo's guest-checkout switch.
			'stripePublishableKey' => $this->stripe_publishable_key(),
			'guestCheckout'        => $this->guest_checkout_enabled(),
		);
	}

	/**
	 * The effective product source mode (option, filter-overridable).
	 *
	 * @return string
	 */
	private function resolve_products_source(): string {
		$source = get_option( Schema::OPTION_PRODUCT_SOURCE, Schema::SOURCE_NATIVE );
		if ( ! is_string( $source ) ) {
			$source = Schema::SOURCE_NATIVE;
		}

		/**
		 * Per-request product source override.
		 *
		 * @param string $source Current source mode.
		 */
		$source = apply_filters( 'funiq_bridge_products_source', $source );

		return in_array( $source, array( Schema::SOURCE_NATIVE, 'woo', 'both' ), true ) ? $source : Schema::SOURCE_NATIVE;
	}

	/**
	 * Enabled payment-method list (read-only, 60 s cached) — the storefront
	 * may SHOW the methods, never drive them (INV-1). Empty without Woo.
	 *
	 * @return array<int, array{id: string, title: string}>
	 */
	private function payment_methods(): array {
		$cached = get_transient( 'funiq_bridge_payment_methods' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$methods = array();
		if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && is_callable( array( WC(), 'payment_gateways' ) ) ) {
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();
			if ( is_array( $gateways ) ) {
				foreach ( $gateways as $id => $gateway ) {
					$methods[] = array(
						'id'    => sanitize_key( (string) $id ),
						'title' => sanitize_text_field( (string) ( $gateway->title ?? $id ) ),
					);
				}
			}
		}

		/**
		 * Override the payment-method list (tests, headless gateways).
		 *
		 * @param array<int, array{id: string, title: string}> $methods Methods.
		 */
		$methods = apply_filters( 'funiq_bridge_payment_methods', $methods );
		$methods = is_array( $methods ) ? $methods : array();

		set_transient( 'funiq_bridge_payment_methods', $methods, MINUTE_IN_SECONDS );

		return $methods;
	}

	/**
	 * The Stripe publishable key when the Stripe gateway is enabled
	 * (Proposal 110) — the storefront loads Stripe.js and renders the
	 * gateway's hosted Payment Element with it. Publishable keys are public
	 * by design (Stripe documents them as safe to ship to the browser); the
	 * SECRET key never leaves this option.
	 *
	 * @return string Empty when Stripe is not the active gateway.
	 */
	private function stripe_publishable_key(): string {
		$key = '';

		$settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
			$testmode  = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
			$candidate = $testmode
				? ( $settings['test_publishable_key'] ?? '' )
				: ( $settings['publishable_key'] ?? '' );
			if ( is_string( $candidate ) ) {
				$key = $candidate;
			}
		}

		/**
		 * Override the Stripe publishable key (tests, custom gateways).
		 *
		 * @param string $key Current key (empty when Stripe is inactive).
		 */
		$key = apply_filters( 'funiq_bridge_stripe_publishable_key', $key );

		return is_string( $key ) ? $key : '';
	}

	/**
	 * Woo's guest-checkout switch (Proposal 110) — the storefront hides the
	 * account-creation option when guests cannot check out.
	 *
	 * @return bool
	 */
	private function guest_checkout_enabled(): bool {
		$enabled = false;
		if ( class_exists( 'WooCommerce' ) ) {
			$enabled = 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' );
		}

		/**
		 * Override the guest-checkout flag (tests).
		 *
		 * @param bool $enabled Current flag.
		 */
		$enabled = apply_filters( 'funiq_bridge_guest_checkout', $enabled );

		return (bool) $enabled;
	}
}
