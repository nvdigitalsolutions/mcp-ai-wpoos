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
	 * Booleans are sent as literal 'true'/'false', not PHP's "1"/"".
	 *
	 * Stripe rejects form-encoded booleans serialized by PHP
	 * (`automatic_payment_methods[enabled]=1` -> "Invalid boolean: 1"
	 * observed live). The client must stringify them before sending.
	 *
	 * @return void
	 */
	public function test_create_payment_intent_stringifies_booleans(): void {
		$captured_body = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured_body ) {
				$captured_body = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'pi_ok' ) ),
				);
			},
			10,
			2
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$client->create_payment_intent(
			array(
				'amount'                    => 4900,
				'currency'                  => 'usd',
				'automatic_payment_methods' => array( 'enabled' => true ),
				'metadata'                  => array( 'flag' => false ),
			)
		);

		$this->assertSame( 'true', $captured_body['automatic_payment_methods']['enabled'] );
		$this->assertSame( 'false', $captured_body['metadata']['flag'] );
		$this->assertSame( 4900, $captured_body['amount'] );
	}

	/**
	 * Stripe 4xx rejections surface as WP_Error with status 424 and the
	 * real Stripe message (so customer sites show it instead of falling
	 * back on the product page).
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
		$this->assertSame( 424, $result->get_error_data()['status'] );
	}

	/**
	 * An invalid API key surfaces as a 424 with Stripe's message.
	 *
	 * @return void
	 */
	public function test_create_payment_intent_invalid_key_maps_to_424(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Invalid API Key provided' ) ) ),
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_bad' );
		$result = $client->create_payment_intent( array( 'amount' => 4900 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'Invalid API Key provided', $result->get_error_message() );
		$this->assertSame( 424, $result->get_error_data()['status'] );
	}

	/**
	 * Stripe 5xx responses map to 502 (checkout unavailable).
	 *
	 * @return void
	 */
	public function test_create_payment_intent_stripe_5xx_maps_to_502(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => 'upstream exploded',
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->create_payment_intent( array( 'amount' => 4900 ) );

		$this->assertWPError( $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * Transport failures keep the cURL message but pin status 502.
	 *
	 * @return void
	 */
	public function test_create_payment_intent_transport_error_maps_to_502(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->create_payment_intent( array( 'amount' => 4900 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'cURL error 28: timeout', $result->get_error_message() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
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
	 * The retrieve_product method GETs the product by ID.
	 *
	 * @return void
	 */
	public function test_retrieve_product(): void {
		$captured_url = '';
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'prod_test_1' ) ),
				);
			},
			10,
			3
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->retrieve_product( 'prod_test_1' );

		$this->assertIsArray( $result );
		$this->assertSame( 'prod_test_1', $result['id'] );
		$this->assertStringContainsString( '/v1/products/prod_test_1', $captured_url );
	}

	/**
	 * The retrieve_price method GETs the price by ID.
	 *
	 * @return void
	 */
	public function test_retrieve_price(): void {
		$captured_url = '';
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'      => 'price_test_1',
							'product' => 'prod_test_1',
						)
					),
				);
			},
			10,
			3
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->retrieve_price( 'price_test_1' );

		$this->assertIsArray( $result );
		$this->assertSame( 'price_test_1', $result['id'] );
		$this->assertSame( 'prod_test_1', $result['product'] );
		$this->assertStringContainsString( '/v1/prices/price_test_1', $captured_url );
	}

	/**
	 * A missing resource (404 resource_missing) carries the HTTP status and
	 * Stripe error code in the WP_Error data so callers can tell a switched
	 * Stripe account apart from a bad key or transport failure.
	 *
	 * @return void
	 */
	public function test_missing_resource_error_carries_http_status_and_stripe_code(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'code'    => 'resource_missing',
								'message' => 'No such product: prod_ghost',
							),
						)
					),
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->retrieve_product( 'prod_ghost' );

		$this->assertWPError( $result );
		$this->assertSame( 424, $result->get_error_data()['status'] );
		$this->assertSame( 404, $result->get_error_data()['http_status'] );
		$this->assertSame( 'resource_missing', $result->get_error_data()['stripe_code'] );
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

	/**
	 * The connection test reports a live balance.
	 *
	 * @return void
	 */
	public function test_test_connection_live_balance(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'object'    => 'balance',
							'livemode'  => true,
							'available' => array(
								array(
									'amount'   => 123456,
									'currency' => 'usd',
								),
							),
						)
					),
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_live_abc' );
		$result = $client->test_connection();

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['livemode'] );
		$this->assertSame( 123456, $result['balance_cents'] );
		$this->assertSame( 'usd', $result['balance_currency'] );
	}

	/**
	 * An invalid key fails the connection test with Stripe's message.
	 *
	 * @return void
	 */
	public function test_test_connection_invalid_key(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Invalid API Key provided' ) ) ),
				);
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_bad' );
		$result = $client->test_connection();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Invalid API Key provided', $result['message'] );
	}

	/**
	 * Transport failures surface as a failed connection test.
	 *
	 * @return void
	 */
	public function test_test_connection_transport_error(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
			},
			10,
			0
		);

		$client = new NVOOS_Checkout_API_Stripe_Client( 'sk_test_abc' );
		$result = $client->test_connection();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'cURL error 28: timeout', $result['message'] );
	}
}
