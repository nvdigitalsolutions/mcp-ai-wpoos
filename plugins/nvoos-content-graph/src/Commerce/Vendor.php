<?php
declare(strict_types=1);

namespace NvoosContentGraph\Commerce;

use WP_Error;

use function is_wp_error;
use function json_decode;
use function sanitize_text_field;
use function trailingslashit;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Thin client for the vendor checkout API.
 *
 * The vendor (NV Digital Solutions) hosts this API on its own server,
 * which is where the Stripe secret key lives. This plugin never touches
 * Stripe credentials — it only calls two endpoints:
 *
 *   POST {base}/session — create a payment session (client_secret + publishable key).
 *   POST {base}/verify  — verify a completed payment (license + signed download URL).
 *
 * See docs/commerce-vendor-api.md for the full contract.
 *
 * @since 1.0.4
 */
class Vendor {

	/** @var string Vendor API base URL, no trailing slash. */
	private string $baseUrl;

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl Vendor API base URL.
	 */
	public function __construct( string $baseUrl ) {
		$this->baseUrl = trailingslashit( $baseUrl );
	}

	/**
	 * Create a checkout session for this site.
	 *
	 * @since 1.0.4
	 *
	 * @return array<string,mixed>|WP_Error
	 *   array{client_secret: string, publishable_key: string, amount: int, currency: string, test_mode: bool}
	 */
	public function createSession() {
		return $this->post( 'session', Payments::purchasePayload() );
	}

	/**
	 * Verify a completed payment and obtain the license + download URL.
	 *
	 * @since 1.0.4
	 *
	 * @param string $paymentIntentId Stripe PaymentIntent ID (pi_…).
	 * @return array<string,mixed>|WP_Error
	 *   array{license_key: string, download_url: string, addon_version: string, amount: int, currency: string}
	 */
	public function verify( string $paymentIntentId ) {
		$payload                   = Payments::purchasePayload();
		$payload['payment_intent'] = $paymentIntentId;
		return $this->post( 'verify', $payload );
	}

	/**
	 * POST JSON to the vendor API and decode the response.
	 *
	 * @param string              $route   Route name relative to the base URL.
	 * @param array<string,mixed> $payload Request payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function post( string $route, array $payload ) {
		$response = wp_remote_post(
			$this->baseUrl . $route,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'nvoos_content_graph_vendor_unreachable',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach the checkout service: %s', 'nvoos-content-graph' ),
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
					__( 'The checkout service returned an error (HTTP %d).', 'nvoos-content-graph' ),
					$code
				);

			return new WP_Error(
				'nvoos_content_graph_vendor_error',
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
