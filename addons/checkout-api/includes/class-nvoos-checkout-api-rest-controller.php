<?php
/**
 * REST controller: session, verify, and Stripe webhook.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout REST endpoints under /wp-json/nvoos-checkout/v1/.
 *
 * `/session` and `/verify` are called server-to-server by customer sites
 * running the free base plugin. They are public (no WP auth) because no
 * shared secret can be shipped in a public plugin; they are protected by
 * per-IP rate limiting, input validation, and — decisively — by Stripe:
 * a license is only ever issued for a real, paid PaymentIntent whose
 * metadata matches the requested product and site URL.
 *
 * `/webhooks/stripe` is public but signature-gated with Stripe's official
 * HMAC algorithm.
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Rest_Controller {

	public const REST_NAMESPACE = 'nvoos-checkout/v1';

	/**
	 * Products this checkout sells.
	 *
	 * @var string[]
	 */
	public const PRODUCTS = array( 'nvoos-content-graph-ai' );

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => '__return_true', // Public by design — see class docblock.
				'args'                => array(
					'product'       => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && in_array( $value, self::PRODUCTS, true );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'site_url'      => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_site_url' ),
						'sanitize_callback' => array( $this, 'sanitize_site_url' ),
					),
					'addon_version' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_payment' ),
				'permission_callback' => '__return_true', // Public by design — see class docblock.
				'args'                => array(
					'product'        => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && in_array( $value, self::PRODUCTS, true );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'site_url'       => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_site_url' ),
						'sanitize_callback' => array( $this, 'sanitize_site_url' ),
					),
					'payment_intent' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && 1 === preg_match( '/^pi_[A-Za-z0-9]{8,}$/', $value );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/webhooks/stripe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true', // Signature-gated inside the handler.
			)
		);
	}

	/**
	 * POST /session — create a Stripe PaymentIntent for a product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_session( WP_REST_Request $request ) {
		if ( ! NVOOS_Checkout_API_Rate_Limiter::check( 'session', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'nvoos_checkout_rate_limited', __( 'Too many requests. Please slow down.', 'nvoos-checkout-api' ), array( 'status' => 429 ) );
		}

		if ( ! NVOOS_Checkout_API_Settings::is_configured() ) {
			return new WP_Error( 'nvoos_checkout_unconfigured', __( 'Checkout is not configured on this store.', 'nvoos-checkout-api' ), array( 'status' => 424 ) );
		}

		$client = new NVOOS_Checkout_API_Stripe_Client( NVOOS_Checkout_API_Settings::stripe_secret_key() );

		$intent = $client->create_payment_intent(
			array(
				'amount'                    => NVOOS_Checkout_API_Settings::price_cents(),
				'currency'                  => NVOOS_Checkout_API_Settings::currency(),
				'description'               => 'NV oOS ' . $request['product'] . ' license',
				'automatic_payment_methods' => array( 'enabled' => true ),
				'metadata'                  => array(
					'product'       => (string) $request['product'],
					'site_url'      => (string) $request['site_url'],
					'addon_version' => (string) ( $request['addon_version'] ?? '' ),
				),
			)
		);

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		if ( empty( $intent['client_secret'] ) ) {
			return new WP_Error( 'nvoos_checkout_session_failed', __( 'Stripe did not return a payment session.', 'nvoos-checkout-api' ), array( 'status' => 502 ) );
		}

		return rest_ensure_response(
			array(
				'client_secret'   => sanitize_text_field( (string) $intent['client_secret'] ),
				'publishable_key' => NVOOS_Checkout_API_Settings::stripe_publishable_key(),
				'amount'          => NVOOS_Checkout_API_Settings::price_cents(),
				'currency'        => NVOOS_Checkout_API_Settings::currency(),
				'test_mode'       => NVOOS_Checkout_API_Settings::is_test_mode(),
			)
		);
	}

	/**
	 * POST /verify — verify a paid intent and issue a license.
	 *
	 * Idempotent per payment intent: re-verification returns the existing
	 * license with a fresh signed download URL instead of issuing twice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_payment( WP_REST_Request $request ) {
		if ( ! NVOOS_Checkout_API_Rate_Limiter::check( 'verify', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'nvoos_checkout_rate_limited', __( 'Too many requests. Please slow down.', 'nvoos-checkout-api' ), array( 'status' => 429 ) );
		}

		if ( ! NVOOS_Checkout_API_Settings::is_configured() ) {
			return new WP_Error( 'nvoos_checkout_unconfigured', __( 'Checkout is not configured on this store.', 'nvoos-checkout-api' ), array( 'status' => 424 ) );
		}

		$client = new NVOOS_Checkout_API_Stripe_Client( NVOOS_Checkout_API_Settings::stripe_secret_key() );
		$intent = $client->retrieve_payment_intent( (string) $request['payment_intent'] );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		// ─── Server-side verification — the only gate that matters ───
		if ( 'succeeded' !== ( $intent['status'] ?? '' ) ) {
			return new WP_Error( 'nvoos_checkout_not_completed', __( 'This payment has not completed yet.', 'nvoos-checkout-api' ), array( 'status' => 402 ) );
		}

		$price_cents     = NVOOS_Checkout_API_Settings::price_cents();
		$store_currency  = NVOOS_Checkout_API_Settings::currency();
		$amount_received = (int) ( $intent['amount_received'] ?? 0 );
		$intent_currency = (string) ( $intent['currency'] ?? '' );

		if ( $amount_received < $price_cents ) {
			return new WP_Error( 'nvoos_checkout_amount_mismatch', __( 'The amount paid does not match the current price.', 'nvoos-checkout-api' ), array( 'status' => 402 ) );
		}

		if ( $intent_currency !== $store_currency ) {
			return new WP_Error( 'nvoos_checkout_currency_mismatch', __( 'The payment currency does not match the store currency.', 'nvoos-checkout-api' ), array( 'status' => 400 ) );
		}

		$metadata        = $intent['metadata'] ?? array();
		$request_product = (string) $request['product'];
		$request_site    = (string) $request['site_url'];
		$intent_product  = (string) ( $metadata['product'] ?? '' );
		$intent_site     = (string) ( $metadata['site_url'] ?? '' );

		if ( $request_product !== $intent_product ) {
			return new WP_Error( 'nvoos_checkout_product_mismatch', __( 'This payment was not made for the requested product.', 'nvoos-checkout-api' ), array( 'status' => 400 ) );
		}

		if ( $request_site !== $intent_site ) {
			return new WP_Error( 'nvoos_checkout_site_mismatch', __( 'This payment was created for a different site.', 'nvoos-checkout-api' ), array( 'status' => 400 ) );
		}

		// ─── Idempotent license issuance ────────────────────────────
		$existing = NVOOS_Checkout_API_License_Store::get_by_payment_intent( (string) $request['payment_intent'] );
		if ( null !== $existing ) {
			if ( NVOOS_Checkout_API_License_Store::STATUS_ACTIVE !== ( $existing['status'] ?? '' ) ) {
				return new WP_Error( 'nvoos_checkout_license_revoked', __( 'This license has been revoked. Please contact support.', 'nvoos-checkout-api' ), array( 'status' => 402 ) );
			}
			return rest_ensure_response( $this->license_response( $existing ) );
		}

		$license = NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => bin2hex( random_bytes( 20 ) ),
				'product'               => (string) $request['product'],
				'site_url'              => (string) $request['site_url'],
				'stripe_payment_intent' => (string) $request['payment_intent'],
				'stripe_customer'       => isset( $intent['customer'] ) ? sanitize_text_field( (string) $intent['customer'] ) : '',
				'amount'                => (int) $intent['amount_received'],
				'currency'              => NVOOS_Checkout_API_Settings::currency(),
				'addon_version'         => NVOOS_Checkout_API_Settings::addon_version(),
			)
		);

		if ( is_wp_error( $license ) ) {
			return new WP_Error( 'nvoos_checkout_license_failed', __( 'Could not issue a license. Please contact support.', 'nvoos-checkout-api' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->license_response( $license ) );
	}

	/**
	 * POST /webhooks/stripe — signature-gated, idempotent webhook receiver.
	 *
	 * `payment_intent.succeeded` issues the license server-side, so a buyer
	 * whose browser died right after paying still gets their license (their
	 * site's /verify call finds it later — idempotent per intent).
	 * `charge.refunded` / `charge.dispute.created` revoke the matching
	 * license. First delivery of an event returns 200 quickly; Stripe
	 * retries are acknowledged (200) without reprocessing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig     = (string) ( $request->get_header( 'stripe_signature' ) ?? '' );

		$verdict = NVOOS_Checkout_API_Stripe_Client::verify_webhook_signature(
			$payload,
			$sig,
			NVOOS_Checkout_API_Settings::stripe_webhook_secret()
		);

		if ( ! $verdict['ok'] ) {
			return new WP_Error( 'nvoos_checkout_webhook_invalid', __( 'Invalid webhook signature.', 'nvoos-checkout-api' ), array( 'status' => 401 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) ) {
			return new WP_Error( 'nvoos_checkout_webhook_malformed', __( 'Malformed webhook payload.', 'nvoos-checkout-api' ), array( 'status' => 400 ) );
		}

		if ( self::is_event_processed( (string) $event['id'] ) ) {
			return rest_ensure_response(
				array(
					'received'  => true,
					'duplicate' => true,
				)
			);
		}

		self::record_event( (string) $event['id'] );

		$type = (string) ( $event['type'] ?? '' );

		// A payment succeeded — issue the license server-side so an
		// interrupted browser flow never strands a paid buyer.
		if ( 'payment_intent.succeeded' === $type ) {
			$intent = $event['data']['object'] ?? array();
			if ( is_array( $intent ) ) {
				$this->issue_license_for_intent( $intent );
			}
			return rest_ensure_response( array( 'received' => true ) );
		}

		if ( in_array( $type, array( 'charge.refunded', 'charge.dispute.created' ), true ) ) {
			$charge = $event['data']['object'] ?? array();
			if ( is_array( $charge ) && ! empty( $charge['payment_intent'] ) ) {
				$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( (string) $charge['payment_intent'] );
				if ( null !== $license ) {
					NVOOS_Checkout_API_License_Store::revoke( (string) $license['license_key'] );
				}
			}
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Issue a license from a webhook-delivered PaymentIntent.
	 *
	 * Idempotent per payment intent and gated by the same server-side
	 * checks as the verify endpoint: succeeded status, amount, product
	 * and site binding from the intent metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,mixed> $intent PaymentIntent object from the event.
	 * @return string Outcome: 'issued', 'exists', 'not_succeeded', 'invalid_metadata', 'amount_mismatch', or 'insert_failed'.
	 */
	private function issue_license_for_intent( array $intent ): string {
		if ( 'succeeded' !== ( $intent['status'] ?? '' ) ) {
			return 'not_succeeded';
		}

		$metadata  = $intent['metadata'] ?? array();
		$product   = (string) ( $metadata['product'] ?? '' );
		$site_url  = (string) ( $metadata['site_url'] ?? '' );
		$intent_id = (string) ( $intent['id'] ?? '' );

		if ( '' === $intent_id || ! in_array( $product, self::PRODUCTS, true ) || '' === $site_url ) {
			return 'invalid_metadata';
		}

		if ( (int) ( $intent['amount_received'] ?? 0 ) < NVOOS_Checkout_API_Settings::price_cents() ) {
			return 'amount_mismatch';
		}

		if ( null !== NVOOS_Checkout_API_License_Store::get_by_payment_intent( $intent_id ) ) {
			return 'exists';
		}

		$created = NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => bin2hex( random_bytes( 20 ) ),
				'product'               => $product,
				'site_url'              => $site_url,
				'stripe_payment_intent' => $intent_id,
				'stripe_customer'       => isset( $intent['customer'] ) ? sanitize_text_field( (string) $intent['customer'] ) : '',
				'amount'                => (int) $intent['amount_received'],
				'currency'              => NVOOS_Checkout_API_Settings::currency(),
				'addon_version'         => NVOOS_Checkout_API_Settings::addon_version(),
			)
		);

		return is_wp_error( $created ) ? 'insert_failed' : 'issued';
	}

	/**
	 * Shape the verify/license response payload.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array<string,mixed>
	 */
	private function license_response( array $license ): array {
		return array(
			'license_key'   => (string) $license['license_key'],
			'download_url'  => NVOOS_Checkout_API_Token::download_url( (string) $license['license_key'] ),
			'addon_version' => (string) $license['addon_version'],
			'amount'        => (int) $license['amount'],
			'currency'      => (string) $license['currency'],
		);
	}

	/**
	 * Validate a site URL (http/https with a host).
	 *
	 * @param mixed $value Candidate URL.
	 * @return bool
	 */
	public function validate_site_url( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Normalize a site URL to scheme + host (drops credentials/paths).
	 *
	 * @param mixed $value Raw URL.
	 * @return string
	 */
	public function sanitize_site_url( $value ): string {
		$value  = (string) $value;
		$parts  = wp_parse_url( $value );
		$scheme = ( 'https' === ( $parts['scheme'] ?? '' ) ) ? 'https' : 'http';
		return $scheme . '://' . (string) ( $parts['host'] ?? '' );
	}

	/**
	 * Whether a webhook event was already processed.
	 *
	 * @param string $event_id Stripe event ID.
	 * @return bool
	 */
	private static function is_event_processed( string $event_id ): bool {
		$seen = get_option( 'nvoos_checkout_processed_events', array() );
		return is_array( $seen ) && in_array( $event_id, $seen, true );
	}

	/**
	 * Record a processed webhook event (ring buffer of 200).
	 *
	 * @param string $event_id Stripe event ID.
	 * @return void
	 */
	private static function record_event( string $event_id ): void {
		$seen   = get_option( 'nvoos_checkout_processed_events', array() );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $event_id;
		if ( count( $seen ) > 200 ) {
			$seen = array_slice( $seen, -200 );
		}
		update_option( 'nvoos_checkout_processed_events', $seen, false );
	}
}
