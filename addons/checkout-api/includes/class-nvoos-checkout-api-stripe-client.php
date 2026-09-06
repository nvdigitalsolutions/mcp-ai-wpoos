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
				'body'    => $params,
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
	 * Decode a WP HTTP response into a Stripe object or WP_Error.
	 *
	 * @param array<mixed>|WP_Error $response Raw response.
	 * @return array<string,mixed>|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
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

			return new WP_Error(
				'nvoos_checkout_stripe_http_error',
				$message,
				array( 'status' => 502 )
			);
		}

		return $data;
	}
}
