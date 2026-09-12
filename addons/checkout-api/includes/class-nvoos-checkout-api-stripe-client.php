<?php
/**
 * Stripe API client (payment intents + webhook signature verification).
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Stripe client.
 *
 * Talks to the Stripe REST API over the WordPress HTTP API — no SDK.
 * Only what the checkout flow needs: create/retrieve PaymentIntents and
 * verify webhook signatures (Stripe's official algorithm).
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Stripe_Client {

	public const API_BASE = 'https://api.stripe.com/v1';

	/** Pinned Stripe API version. */
	public const API_VERSION = '2024-06-20';

	/**
	 * Stripe secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Stripe secret key.
	 */
	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * Create a PaymentIntent.
	 *
	 * @param array<string,mixed> $params Intent parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_payment_intent( array $params ) {
		$response = wp_remote_post(
			self::API_BASE . '/payment_intents',
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => self::stringify_booleans( $params ),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Retrieve a PaymentIntent by ID.
	 *
	 * @param string $intent_id PaymentIntent ID (pi_…).
	 * @return array<string,mixed>|WP_Error
	 */
	public function retrieve_payment_intent( string $intent_id ) {
		$response = wp_remote_get(
			self::API_BASE . '/payment_intents/' . rawurlencode( $intent_id ),
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Test the secret key against the Stripe API.
	 *
	 * Reads the account balance — the cheapest authenticated read that works
	 * for both live and test keys, and mutates nothing. Used by the admin
	 * page's "Test connection" button.
	 *
	 * @return array{ok: bool, livemode: bool, balance_cents: int, balance_currency: string, message: string}
	 */
	public function test_connection(): array {
		$response = wp_remote_get(
			self::API_BASE . '/balance',
			array(
				'timeout' => 15,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'               => false,
				'livemode'         => false,
				'balance_cents'    => 0,
				'balance_currency' => '',
				'message'          => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error']['message'] )
				? sanitize_text_field( (string) $data['error']['message'] )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Stripe request failed (HTTP %d).', 'nvoos-checkout-api' ),
					$code
				);

			return array(
				'ok'               => false,
				'livemode'         => false,
				'balance_cents'    => 0,
				'balance_currency' => '',
				'message'          => $message,
			);
		}

		$balance_cents    = 0;
		$balance_currency = '';
		foreach ( (array) ( $data['available'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['amount'], $entry['currency'] ) && is_numeric( $entry['amount'] ) ) {
				$balance_cents    = (int) $entry['amount'];
				$balance_currency = strtolower( (string) $entry['currency'] );
				break;
			}
		}

		return array(
			'ok'               => true,
			'livemode'         => ! empty( $data['livemode'] ),
			'balance_cents'    => $balance_cents,
			'balance_currency' => $balance_currency,
			'message'          => '',
		);
	}

	/**
	 * Create a Stripe Product (type: service) for reporting/tax metadata.
	 *
	 * The product is referenced by the Price below and recorded on payment
	 * intents as metadata — it does not change how payments are charged.
	 *
	 * @param string $name Product display name.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_product( string $name ) {
		$response = wp_remote_post(
			self::API_BASE . '/products',
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => self::stringify_booleans(
					array(
						'name'     => $name,
						'type'     => 'service',
						'metadata' => array(
							'source' => 'nvoos-checkout-api',
						),
					)
				),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Retrieve a Stripe Product by ID.
	 *
	 * Used by the admin page to verify that a stored Product ID still
	 * exists in the account identified by the current secret key — after
	 * the storefront switches Stripe accounts, stored IDs belong to the
	 * previous account and Stripe answers 404 (`resource_missing`).
	 *
	 * @param string $product_id Stripe Product ID (prod_…).
	 * @return array<string,mixed>|WP_Error
	 */
	public function retrieve_product( string $product_id ) {
		$response = wp_remote_get(
			self::API_BASE . '/products/' . rawurlencode( $product_id ),
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Create a one-time Stripe Price for a product.
	 *
	 * Mirrors the storefront's configured price/currency so reporting and
	 * tax tooling line up with what buyers actually pay.
	 *
	 * @param string $product_id Stripe Product ID.
	 * @param int    $amount_cents Amount in the smallest currency unit.
	 * @param string $currency Three-letter ISO currency code.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_price( string $product_id, int $amount_cents, string $currency ) {
		$response = wp_remote_post(
			self::API_BASE . '/prices',
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
				'body'    => self::stringify_booleans(
					array(
						'currency'    => $currency,
						'product'     => $product_id,
						'unit_amount' => max( 50, $amount_cents ),
						'metadata'    => array(
							'source' => 'nvoos-checkout-api',
						),
					)
				),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Retrieve a Stripe Price by ID.
	 *
	 * Mirrors retrieve_product(): after an account switch, a stored Price
	 * ID resolves to 404 in the current account (or belongs to a different
	 * Product), so the admin page can detect it and recreate.
	 *
	 * @param string $price_id Stripe Price ID (price_…).
	 * @return array<string,mixed>|WP_Error
	 */
	public function retrieve_price( string $price_id ) {
		$response = wp_remote_get(
			self::API_BASE . '/prices/' . rawurlencode( $price_id ),
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * Mirrors Stripe's reference algorithm: parse `Stripe-Signature`
	 * (t=…,v1=…), recompute HMAC-SHA256 over "{timestamp}.{payload}" and
	 * accept when any v1 value matches in constant time within tolerance.
	 *
	 * @param string $payload        Raw request body.
	 * @param string $sig_header     Stripe-Signature header value.
	 * @param string $webhook_secret Webhook signing secret (whsec_…).
	 * @param int    $tolerance      Allowed clock skew in seconds.
	 * @return array{ok: bool, reason: string, timestamp: int, event_id: string, event_type: string}
	 */
	public static function verify_webhook_signature( string $payload, string $sig_header, string $webhook_secret, int $tolerance = 300 ): array {
		$event_id   = '';
		$event_type = '';
		$decoded    = json_decode( $payload, true );
		if ( is_array( $decoded ) ) {
			$event_id   = (string) ( $decoded['id'] ?? '' );
			$event_type = (string) ( $decoded['type'] ?? '' );
		}

		if ( '' === $webhook_secret ) {
			return array(
				'ok'         => false,
				'reason'     => 'missing_secret',
				'timestamp'  => 0,
				'event_id'   => $event_id,
				'event_type' => $event_type,
			);
		}

		$timestamp  = 0;
		$signatures = array();

		foreach ( explode( ',', $sig_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && is_numeric( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			}
			if ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( 0 === $timestamp || empty( $signatures ) ) {
			return array(
				'ok'         => false,
				'reason'     => 'malformed_header',
				'timestamp'  => $timestamp,
				'event_id'   => $event_id,
				'event_type' => $event_type,
			);
		}

		if ( abs( time() - $timestamp ) > $tolerance ) {
			return array(
				'ok'         => false,
				'reason'     => 'stale_timestamp',
				'timestamp'  => $timestamp,
				'event_id'   => $event_id,
				'event_type' => $event_type,
			);
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $webhook_secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return array(
					'ok'         => true,
					'reason'     => 'signature_match',
					'timestamp'  => $timestamp,
					'event_id'   => $event_id,
					'event_type' => $event_type,
				);
			}
		}

		return array(
			'ok'         => false,
			'reason'     => 'signature_mismatch',
			'timestamp'  => $timestamp,
			'event_id'   => $event_id,
			'event_type' => $event_type,
		);
	}

	/**
	 * Request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		);
	}

	/**
	 * Convert booleans to the literal strings Stripe expects.
	 *
	 * The bodies are sent form-encoded, and PHP's form serialization
	 * turns `true` into "1" — Stripe rejects that with
	 * `Invalid boolean: 1` (observed live on `automatic_payment_methods
	 * [enabled]`). Recursively stringify booleans to 'true'/'false' so
	 * the serializer passes the literal tokens through.
	 *
	 * @param array<string,mixed> $params Request body.
	 * @return array<string,mixed>
	 */
	private static function stringify_booleans( array $params ): array {
		foreach ( $params as $key => $value ) {
			if ( is_bool( $value ) ) {
				$params[ $key ] = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$params[ $key ] = self::stringify_booleans( $value );
			}
		}
		return $params;
	}

	/**
	 * Decode a WP HTTP response into a Stripe object or WP_Error.
	 *
	 * Error status mapping contract (mirrored by the customer-side
	 * Content Graph modal):
	 *   - Stripe 4xx: the REQUEST was rejected (bad key, invalid
	 *     parameters, account restrictions) -> WP_Error with status 424
	 *     and Stripe's own message, so buyers see the real reason
	 *     instead of a generic gateway failure.
	 *   - Transport failure or Stripe 5xx: checkout is genuinely
	 *     unavailable -> status 502, which customer sites may treat as
	 *     "unreachable" and fall back on.
	 *
	 * @param array<mixed>|WP_Error $response Raw response.
	 * @return array<string,mixed>|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			// Transport-level failure (this server cannot reach Stripe):
			// keep the cURL message but pin the status to 502 so customer
			// sites classify it as checkout-unavailable, never as a Stripe
			// rejection of the purchase.
			$response->add_data( array( 'status' => 502 ) );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error']['message'] )
				? sanitize_text_field( (string) $data['error']['message'] )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Stripe request failed (HTTP %d).', 'nvoos-checkout-api' ),
					$code
				);

			$status      = ( $code >= 400 && $code < 500 ) ? 424 : 502;
			$stripe_code = is_array( $data ) && isset( $data['error']['code'] )
				? (string) $data['error']['code']
				: '';

			return new WP_Error(
				'nvoos_checkout_stripe_http_error',
				$message,
				array(
					'status'      => $status,
					'http_status' => $code,
					'stripe_code' => $stripe_code,
				)
			);
		}

		return $data;
	}
}
