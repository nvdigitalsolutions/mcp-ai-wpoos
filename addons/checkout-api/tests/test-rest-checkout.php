<?php
/**
 * Tests for the checkout REST endpoints.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

/**
 * REST controller tests (Stripe short-circuited via pre_http_request).
 */
class Test_Checkout_Api_Rest extends WP_UnitTestCase {

	/** @var NVOOS_Checkout_API_Rest_Controller */
	private $controller;

	/**
	 * Set up the controller + table + settings.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->controller = new NVOOS_Checkout_API_Rest_Controller();
		NVOOS_Checkout_API_License_Store::install_table();

		update_option(
			NVOOS_Checkout_API_Settings::OPTION,
			array(
				'stripe_secret_key'      => 'sk_test_abc',
				'stripe_publishable_key' => 'pk_test_abc',
				'price_cents'            => 4900,
				'currency'               => 'usd',
				'test_mode'              => 1,
				'addon_version'          => '1.0.4',
			)
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( NVOOS_Checkout_API_Settings::OPTION );
		parent::tearDown();
	}

	/**
	 * Stub the Stripe API.
	 *
	 * @param array<int,array<string,mixed>> $responses Response arrays in call order.
	 * @return void
	 */
	private function stub_stripe( array $responses ): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls, $responses ) {
				$index = min( $calls, count( $responses ) - 1 );
				$calls++;
				return $responses[ $index ];
			},
			10,
			0
		);
	}

	/**
	 * Build a session request.
	 *
	 * @return WP_REST_Request
	 */
	private function session_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/session' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'addon_version', '1.0.4' );
		return $request;
	}

	/**
	 * A configured store returns a session payload.
	 *
	 * @return void
	 */
	public function test_session_returns_client_secret(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => 'pi_1', 'client_secret' => 'pi_1_secret' ) ),
				),
			)
		);

		$response = $this->controller->create_session( $this->session_request() );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertSame( 'pi_1_secret', $data['client_secret'] );
		$this->assertSame( 'pk_test_abc', $data['publishable_key'] );
		$this->assertTrue( $data['test_mode'] );
	}

	/**
	 * An unconfigured store returns 424.
	 *
	 * @return void
	 */
	public function test_session_unconfigured(): void {
		delete_option( NVOOS_Checkout_API_Settings::OPTION );

		$response = $this->controller->create_session( $this->session_request() );

		$this->assertWPError( $response );
		$this->assertSame( 424, $response->get_error_data()['status'] );
	}

	/**
	 * A valid paid intent issues a license with a signed download URL.
	 *
	 * @return void
	 */
	public function test_verify_issues_license(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_paid',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'customer'        => 'cus_1',
							'metadata'        => array(
								'product'  => 'nvoos-content-graph-ai',
								'site_url' => 'https://customer.example',
							),
						)
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_paid' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['license_key'] );
		$this->assertStringContainsString( 'nvoos_checkout_download=1', $data['download_url'] );

		$license = NVOOS_Checkout_API_License_Store::get_by_key( $data['license_key'] );
		$this->assertNotNull( $license );
	}

	/**
	 * Re-verification of the same intent is idempotent (same license key).
	 *
	 * @return void
	 */
	public function test_verify_is_idempotent(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_paid_2',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'metadata'        => array(
								'product'  => 'nvoos-content-graph-ai',
								'site_url' => 'https://customer.example',
							),
						)
					),
				),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_paid_2',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'metadata'        => array(
								'product'  => 'nvoos-content-graph-ai',
								'site_url' => 'https://customer.example',
							),
						)
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_paid_2' );

		$first  = $this->controller->verify_payment( $request );
		$second = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( $first->get_data()['license_key'], $second->get_data()['license_key'] );
		$this->assertSame( 1, NVOOS_Checkout_API_License_Store::count() );
	}

	/**
	 * A payment bound to another site is rejected.
	 *
	 * @return void
	 */
	public function test_verify_rejects_foreign_site(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_foreign',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'metadata'        => array(
								'product'  => 'nvoos-content-graph-ai',
								'site_url' => 'https://someone-else.example',
							),
						)
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_foreign' );

		$response = $this->controller->verify_payment( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'nvoos_checkout_site_mismatch', $response->get_error_code() );
		$this->assertSame( 0, NVOOS_Checkout_API_License_Store::count() );
	}

	/**
	 * A webhook with a valid signature revokes the matching license.
	 *
	 * @return void
	 */
	public function test_webhook_revokes_license(): void {
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'revoke-me',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_refunded',
				'amount'                => 4900,
			)
		);

		update_option(
			NVOOS_Checkout_API_Settings::OPTION,
			array_merge(
				get_option( NVOOS_Checkout_API_Settings::OPTION, array() ),
				array( 'stripe_webhook_secret' => 'whsec_test' )
			)
		);

		$payload = wp_json_encode(
			array(
				'id'   => 'evt_refund',
				'type' => 'charge.refunded',
				'data' => array( 'object' => array( 'payment_intent' => 'pi_refunded' ) ),
			)
		);

		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, 'whsec_test' );

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/webhooks/stripe' );
		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . $signature );
		$request->set_body( $payload );

		$response = $this->controller->handle_webhook( $request );

		$this->assertNotWPError( $response );
		$this->assertTrue( $response->get_data()['received'] );

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'revoke-me' );
		$this->assertSame( NVOOS_Checkout_API_License_Store::STATUS_REVOKED, $license['status'] );
	}

	/**
	 * payment_intent.succeeded issues the license server-side (interrupted
	 * browser recovery) and is idempotent across Stripe retries.
	 *
	 * @return void
	 */
	public function test_webhook_issues_license_on_intent_succeeded(): void {
		update_option(
			NVOOS_Checkout_API_Settings::OPTION,
			array_merge(
				get_option( NVOOS_Checkout_API_Settings::OPTION, array() ),
				array( 'stripe_webhook_secret' => 'whsec_test' )
			)
		);

		$payload = wp_json_encode(
			array(
				'id'   => 'evt_intent_ok',
				'type' => 'payment_intent.succeeded',
				'data' => array(
					'object' => array(
						'id'              => 'pi_orphan',
						'status'          => 'succeeded',
						'amount_received' => 4900,
						'currency'        => 'usd',
						'customer'        => 'cus_orphan',
						'metadata'        => array(
							'product'  => 'nvoos-content-graph-ai',
							'site_url' => 'https://customer.example',
						),
					),
				),
			)
		);

		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, 'whsec_test' );

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/webhooks/stripe' );
		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . $signature );
		$request->set_body( $payload );

		$response = $this->controller->handle_webhook( $request );

		$this->assertNotWPError( $response );
		$this->assertTrue( $response->get_data()['received'] );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_orphan' );
		$this->assertNotNull( $license );
		$this->assertSame( NVOOS_Checkout_API_License_Store::STATUS_ACTIVE, $license['status'] );
		$this->assertSame( 1, NVOOS_Checkout_API_License_Store::count() );

		// A retried delivery of the same event must not duplicate the license.
		$retry = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/webhooks/stripe' );
		$retry->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . $signature );
		$retry->set_body( $payload );

		$retry_response = $this->controller->handle_webhook( $retry );

		$this->assertNotWPError( $retry_response );
		$this->assertTrue( $retry_response->get_data()['duplicate'] );
		$this->assertSame( 1, NVOOS_Checkout_API_License_Store::count() );
	}

	/**
	 * A subsequent /verify for a webhook-issued license returns it with a
	 * fresh signed download URL (the interrupted buyer's recovery path).
	 *
	 * @return void
	 */
	public function test_verify_picks_up_webhook_issued_license(): void {
		$this->test_webhook_issues_license_on_intent_succeeded();

		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_orphan',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'metadata'        => array(
								'product'  => 'nvoos-content-graph-ai',
								'site_url' => 'https://customer.example',
							),
						)
					),
				)
			)
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_orphan' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertSame( NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_orphan' )['license_key'], $data['license_key'] );
		$this->assertStringContainsString( 'nvoos_checkout_download=1', $data['download_url'] );
		$this->assertSame( 1, NVOOS_Checkout_API_License_Store::count() );
	}
}
