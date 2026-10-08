<?php
/**
 * NV oOS Docs Hub — Checkout API Client
 *
 * Thin server-to-server client for the vendor checkout API. Mirrors the
 * Content Graph vendor client
 * (plugins/nvoos-content-graph/src/Commerce/Vendor.php) with docs-hub
 * naming.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for the vendor checkout API.
 *
 * The vendor (NV Digital Solutions) hosts this API on its own server,
 * which is where the Stripe secret key lives. This plugin never touches
 * Stripe credentials — it only calls three endpoints:
 *
 *   POST {base}/session — create a payment session (client_secret + publishable key).
 *   POST {base}/verify  — verify a completed payment (license + signed download URL).
 *   GET  {base}/health  — public connectivity probe.
 *
 * @since 0.5.2
 */
class NV_oOS_Docs_Hub_Checkout_Client {

	/**
	 * Vendor API base URL, no trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @since 0.5.2
	 *
	 * @param string $base_url Vendor API base URL.
	 */
	public function __construct( $base_url ) {
		$this->base_url = trailingslashit( $base_url );
	}

	/**
	 * Create a checkout session for this site.
	 *
	 * @since 0.5.2
	 *
	 * @return array<string,mixed>|WP_Error
	 *   array{client_secret: string, publishable_key: string, amount: int, currency: string, test_mode: bool, terms_url: string, refund_policy_url: string}
	 */
	public function create_session() {
		return $this->post( 'session', NV_oOS_Docs_Hub_Checkout::purchase_payload() );
	}

	/**
	 * Ping the vendor's public health endpoint.
	 *
	 * Cheap connectivity probe used by the diagnostics route
	 * (`GET /payments/health`): it never creates a Stripe intent and
	 * never consumes a session/verify throttle token on either side, so
	 * admins can poll it while debugging without triggering the
	 * "Too many checkout attempts" lockout.
	 *
	 * @since 0.5.2
	 *
	 * @return array<string,mixed>|WP_Error
	 *   array{status: string, service: string, version: string, configured: bool, server_time: int}
	 */
	public function health() {
		$response = wp_remote_get(
			$this->base_url . 'health',
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_vendor_unreachable',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach the checkout service: %s', 'nvoos-docs-hub' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_vendor_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The checkout service returned an error (HTTP %d).', 'nvoos-docs-hub' ),
					$code
				),
				array(
					'status' => $code >= 400 && $code < 500 ? $code : 502,
					'vendor' => true,
				)
			);
		}

		return $data;
	}

	/**
	 * Verify a completed payment and obtain the license + download URL.
	 *
	 * @since 0.5.2
	 *
	 * @param string $payment_intent_id Stripe PaymentIntent ID (pi_…).
	 * @param int    $terms_agreed_at   Unix timestamp of the buyer's consent
	 *                                  to the Terms of Service (0 = absent).
	 * @param string $buyer_email       Buyer's receipt/refund email ('' = absent).
	 * @param string $buyer_country     Buyer's ISO country code ('' = absent).
	 * @return array<string,mixed>|WP_Error
	 *   array{license_key: string, download_url: string, addon_version: string, amount: int, currency: string}
	 */
	public function verify( $payment_intent_id, $terms_agreed_at = 0, $buyer_email = '', $buyer_country = '' ) {
		$payload                   = NV_oOS_Docs_Hub_Checkout::purchase_payload();
		$payload['payment_intent'] = $payment_intent_id;
		if ( $terms_agreed_at > 0 ) {
			$payload['terms_agreed_at'] = $terms_agreed_at;
		}
		if ( '' !== $buyer_email ) {
			$payload['buyer_email'] = sanitize_email( $buyer_email );
		}
		if ( '' !== $buyer_country ) {
			$payload['buyer_country'] = strtoupper( sanitize_text_field( $buyer_country ) );
		}
		return $this->post( 'verify', $payload );
	}

	/**
	 * POST JSON to the vendor API and decode the response.
	 *
	 * @since 0.5.2
	 *
	 * @param string              $route   Route name relative to the base URL.
	 * @param array<string,mixed> $payload Request payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function post( $route, $payload ) {
		$response = wp_remote_post(
			$this->base_url . $route,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_vendor_unreachable',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach the checkout service: %s', 'nvoos-docs-hub' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? sanitize_text_field( (string) $data['message'] )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The checkout service returned an error (HTTP %d).', 'nvoos-docs-hub' ),
					$code
				);

			return new WP_Error(
				'nvoos_docs_hub_checkout_vendor_error',
				$message,
				array(
					'status' => $code >= 400 && $code < 500 ? $code : 502,
					'vendor' => true,
				)
			);
		}

		return $data;
	}
}
