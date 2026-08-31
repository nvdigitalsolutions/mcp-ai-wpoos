<?php
/**
 * Tests for the Stripe client and webhook signature verification.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

/**
 * Stripe client tests (network short-circuited via pre_http_request).
 */
class Test_Checkout_Api_Stripe_Client extends WP_UnitTestCase {

	/**
	 * Clean up HTTP stubs.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * create_payment_intent returns the decoded intent.
	 *
	 * @return void
	 */
	public function test_create_payment_intent(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'pi_test_1', 'client_secret' => 'pi_test_1_secret' ) ),
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->create_payment_intent( array( 'amount' => 4900 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'pi_test_1_secret', $result['client_secret'] );
	}

	/**
	 * Stripe errors surface as WP_Error.
	 *
	 * @return void
	 */
	public function test_create_payment_intent_error(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 402 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Card declined' ) ) ),
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->create_payment_intent( array( 'amount' => 4900 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'Card declined', $result->get_error_message() );
	}

	/**
	 * A valid signature passes verification.
	 *
	 * @return void
	 */
	public function test_webhook_signature_valid(): void {
		$secret  = 'whsec_test_secret';
		$payload = '{"id":"evt_1","type":"charge.refunded","data":{"object":{"payment_intent":"pi_1"}}}';

		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		$header    = 't=' . $timestamp . ',v1=' . $signature;

		$verdict = NVOOS_Checkout_API_Stripe_Client::verify_webhook_signature( $payload, $header, $secret );

		$this->assertTrue( $verdict['ok'] );
		$this->assertSame( 'evt_1', $verdict['event_id'] );
		$this->assertSame( 'charge.refunded', $verdict['event_type'] );
	}

	/**
	 * A mismatched signature is rejected.
	 *
	 * @return void
	 */
	public function test_webhook_signature_mismatch(): void {
		$payload = '{"id":"evt_2","type":"charge.refunded"}';
		$header  = 't=' . time() . ',v1=deadbeef';

		$verdict = NVOOS_Checkout_API_Stripe_Client::verify_webhook_signature( $payload, $header, 'whsec_test_secret' );

		$this->assertFalse( $verdict['ok'] );
		$this->assertSame( 'signature_mismatch', $verdict['reason'] );
	}

	/**
	 * A stale timestamp is rejected.
	 *
	 * @return void
	 */
	public function test_webhook_signature_stale(): void {
		$secret    = 'whsec_test_secret';
		$payload   = '{"id":"evt_3","type":"charge.refunded"}';
		$timestamp = time() - 3600;
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$verdict = NVOOS_Checkout_API_Stripe_Client::verify_webhook_signature(
			$payload,
			't=' . $timestamp . ',v1=' . $signature,
			$secret
		);

		$this->assertFalse( $verdict['ok'] );
		$this->assertSame( 'stale_timestamp', $verdict['reason'] );
	}

	/**
	 * A missing secret is reported.
	 *
	 * @return void
	 */
	public function test_webhook_signature_missing_secret(): void {
		$verdict = NVOOS_Checkout_API_Stripe_Client::verify_webhook_signature( '{}', '', '' );

		$this->assertFalse( $verdict['ok'] );
		$this->assertSame( 'missing_secret', $verdict['reason'] );
	}
}
