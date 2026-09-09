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
	 * The create_payment_intent method returns the decoded intent.
	 *
	 * @return void
	 */
	public function test_create_payment_intent(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'            => 'pi_test_1',
							'client_secret' => 'pi_test_1_secret',
						)
					),
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
	 * The create_product method sends a service-typed product to Stripe.
	 *
	 * @return void
	 */
	public function test_create_product(): void {
		$captured_url  = '';
		$captured_body = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $url;
				$captured_body = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'prod_test_1' ) ),
				);
			},
			10,
			3
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->create_product( 'NV oOS Complete' );

		$this->assertIsArray( $result );
		$this->assertSame( 'prod_test_1', $result['id'] );
		$this->assertStringContainsString( '/v1/products', $captured_url );
		$this->assertSame( 'NV oOS Complete', $captured_body['name'] );
		$this->assertSame( 'service', $captured_body['type'] );
	}

	/**
	 * The create_price method sends a one-time unit price to Stripe.
	 *
	 * @return void
	 */
	public function test_create_price(): void {
		$captured_url  = '';
		$captured_body = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $url;
				$captured_body = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'price_test_1' ) ),
				);
			},
			10,
			3
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->create_price( 'prod_test_1', 4900, 'usd' );

		$this->assertIsArray( $result );
		$this->assertSame( 'price_test_1', $result['id'] );
		$this->assertStringContainsString( '/v1/prices', $captured_url );
		$this->assertSame( 'prod_test_1', $captured_body['product'] );
		$this->assertSame( 4900, $captured_body['unit_amount'] );
		$this->assertSame( 'usd', $captured_body['currency'] );
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
