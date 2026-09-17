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

	/** Controller under test.
	 *
	 * @var NVOOS_Checkout_API_Rest_Controller
	 */
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

		// No mail transport exists in the test env — short-circuit wp_mail
		// so license-email sends can never attempt a real delivery.
		add_filter( 'pre_wp_mail', '__return_true' );

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
		remove_all_filters( 'pre_wp_mail' );
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
					'body'     => wp_json_encode(
						array(
							'id'            => 'pi_1',
							'client_secret' => 'pi_1_secret',
						)
					),
				),
			)
		);

		$response = $this->controller->create_session( $this->session_request() );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertSame( 'pi_1_secret', $data['client_secret'] );
		$this->assertSame( 'pk_test_abc', $data['publishable_key'] );
		$this->assertTrue( $data['test_mode'] );
		$this->assertSame( 'https://nvdigitalsolutions.com/terms-of-service', $data['terms_url'] );
		$this->assertSame( 'https://nvdigitalsolutions.com/refund-policy', $data['refund_policy_url'] );
	}

	/**
	 * The NV oOS Complete product id passes validation and reaches Stripe.
	 *
	 * @return void
	 */
	public function test_session_accepts_complete_product(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'            => 'pi_complete',
							'client_secret' => 'pi_complete_secret',
						)
					),
				),
			)
		);

		$request = $this->session_request();
		$request->set_param( 'product', 'nvoos-oos-complete' );
		$request->set_param( 'addon_version', '1.1.74' );

		$response = $this->controller->create_session( $request );

		$this->assertNotWPError( $response );
		$this->assertSame( 'pi_complete_secret', $response->get_data()['client_secret'] );
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
	 * A Stripe 4xx rejection surfaces as a 424 with Stripe's message.
	 *
	 * Customer sites treat 424 as a showable rejection (in-modal error)
	 * instead of checkout-unavailable, so the buyer sees the real reason
	 * the session could not be created.
	 *
	 * @return void
	 */
	public function test_session_surfaces_stripe_rejection_with_424(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message' => 'Invalid boolean: 1',
							),
						)
					),
				),
			)
		);

		$response = $this->controller->create_session( $this->session_request() );

		$this->assertWPError( $response );
		$this->assertSame( 'nvoos_checkout_stripe_http_error', $response->get_error_code() );
		$this->assertSame( 'Invalid boolean: 1', $response->get_error_message() );
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
	 * Verify emails the buyer once and marks the send on the license row.
	 *
	 * @return void
	 */
	public function test_verify_sends_license_email_once(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_mail',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'receipt_email'   => 'buyer@example.com',
							'metadata'        => array(
								'product'  => 'nvoos-oos-complete',
								'site_url' => 'https://customer.example',
							),
						)
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-oos-complete' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_mail' );

		$response = $this->controller->verify_payment( $request );
		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_mail' );
		$this->assertNotNull( $license );
		$this->assertNotEmpty( $license['email_sent_at'], 'The license email must be recorded as sent after verify.' );

		// Re-verification is idempotent: the send timestamp never moves.
		$this->controller->verify_payment( $request );
		$rechecked = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_mail' );
		$this->assertSame( $license['email_sent_at'], $rechecked['email_sent_at'] );
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
	 * The consent-timestamp validator accepts plausible timestamps only.
	 *
	 * @return void
	 */
	public function test_terms_agreed_at_validation(): void {
		$this->assertTrue( $this->controller->validate_terms_agreed_at( time() ) );
		$this->assertTrue( $this->controller->validate_terms_agreed_at( (string) ( time() - 60 ) ) );
		$this->assertFalse( $this->controller->validate_terms_agreed_at( 0 ) );
		$this->assertFalse( $this->controller->validate_terms_agreed_at( time() - 8 * DAY_IN_SECONDS ) );
		$this->assertFalse( $this->controller->validate_terms_agreed_at( time() + 3600 ) );
		$this->assertFalse( $this->controller->validate_terms_agreed_at( 'not-a-timestamp' ) );
	}

	/**
	 * Statement descriptor suffixes are sanitized strictly; values that are
	 * too short, too long, or contain no letter drop out.
	 *
	 * @return void
	 */
	public function test_statement_descriptor_sanitization(): void {
		$clean = NVOOS_Checkout_API_Settings::sanitize( array( 'statement_descriptor' => '  nv oos* complete!! ' ) );
		$this->assertSame( 'NV OOS* COMPLETE', $clean['statement_descriptor'] );

		$short = NVOOS_Checkout_API_Settings::sanitize( array( 'statement_descriptor' => 'A' ) );
		$this->assertSame( '', $short['statement_descriptor'] );

		$numbers = NVOOS_Checkout_API_Settings::sanitize( array( 'statement_descriptor' => '12345' ) );
		$this->assertSame( '', $numbers['statement_descriptor'] );

		$long = NVOOS_Checkout_API_Settings::sanitize( array( 'statement_descriptor' => str_repeat( 'A', 30 ) ) );
		$this->assertSame( '', $long['statement_descriptor'] );
	}

	/**
	 * /session attaches the descriptor and Stripe product/price metadata.
	 *
	 * @return void
	 */
	public function test_session_includes_descriptor_and_product_metadata(): void {
		update_option(
			NVOOS_Checkout_API_Settings::OPTION,
			array_merge(
				get_option( NVOOS_Checkout_API_Settings::OPTION, array() ),
				array(
					'statement_descriptor' => 'NV OOS COMPLETE',
					'product_id'           => 'prod_test_1',
					'price_id'             => 'price_test_1',
				)
			)
		);

		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'            => 'pi_descriptor',
							'client_secret' => 'pi_descriptor_secret',
						)
					),
				);
			},
			10,
			2
		);

		$response = $this->controller->create_session( $this->session_request() );

		$this->assertNotWPError( $response );
		$this->assertIsArray( $captured );
		$this->assertSame( 'NV OOS COMPLETE', $captured['statement_descriptor_suffix'] );
		$this->assertArrayNotHasKey( 'statement_descriptor', $captured );
		$this->assertSame( 'prod_test_1', $captured['metadata']['stripe_product_id'] );
		$this->assertSame( 'price_test_1', $captured['metadata']['stripe_price_id'] );
	}

	/**
	 * /verify records the buyer's consent timestamp on a fresh license.
	 *
	 * @return void
	 */
	public function test_verify_records_terms_consent(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_consent',
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

		$consent_at = time();

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_consent' );
		$request->set_param( 'terms_agreed_at', $consent_at );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_consent' );
		$this->assertNotNull( $license );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $consent_at ), $license['terms_agreed_at'] );
	}

	/**
	 * /verify attaches consent to a license the webhook issued first.
	 *
	 * The payment webhook usually creates the row before the browser's
	 * /verify arrives; the later call must fill — never overwrite — the
	 * consent timestamp.
	 *
	 * @return void
	 */
	public function test_verify_attaches_consent_to_webhook_issued_license(): void {
		// Simulate the webhook path: license exists without consent.
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'webhook-first',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_webhook_first',
				'amount'                => 4900,
			)
		);

		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_webhook_first',
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

		$consent_at = time();

		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/verify' );
		$request->set_param( 'product', 'nvoos-content-graph-ai' );
		$request->set_param( 'site_url', 'https://customer.example' );
		$request->set_param( 'payment_intent', 'pi_webhook_first' );
		$request->set_param( 'terms_agreed_at', $consent_at );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_webhook_first' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $consent_at ), $license['terms_agreed_at'] );

		// A later /verify must never overwrite the recorded timestamp.
		$request->set_param( 'terms_agreed_at', $consent_at + 500 );
		$this->controller->verify_payment( $request );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_webhook_first' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $consent_at ), $license['terms_agreed_at'] );
		$this->assertSame( 1, NVOOS_Checkout_API_License_Store::count() );
	}

	/**
	 * /verify records the buyer's email on a fresh license.
	 *
	 * @return void
	 */
	public function test_verify_records_buyer_email(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_email',
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
		$request->set_param( 'payment_intent', 'pi_email' );
		$request->set_param( 'buyer_email', 'buyer@example.com' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_email' );
		$this->assertNotNull( $license );
		$this->assertSame( 'buyer@example.com', $license['buyer_email'] );
	}

	/**
	 * The intent's receipt_email (set by Stripe) wins over the request param.
	 *
	 * @return void
	 */
	public function test_verify_prefers_intent_receipt_email(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_receipt',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => 'usd',
							'receipt_email'   => 'stripe@example.com',
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
		$request->set_param( 'payment_intent', 'pi_receipt' );
		$request->set_param( 'buyer_email', 'request@example.com' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_receipt' );
		$this->assertNotNull( $license );
		$this->assertSame( 'stripe@example.com', $license['buyer_email'] );
	}

	/**
	 * /verify attaches the buyer email to a license the webhook issued first.
	 *
	 * @return void
	 */
	public function test_verify_attaches_buyer_email_to_webhook_issued_license(): void {
		// Simulate the webhook path: license exists without an email.
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'webhook-email',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_webhook_email',
				'amount'                => 4900,
			)
		);

		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_webhook_email',
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
		$request->set_param( 'payment_intent', 'pi_webhook_email' );
		$request->set_param( 'buyer_email', 'late@example.com' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_webhook_email' );
		$this->assertSame( 'late@example.com', $license['buyer_email'] );

		// A later /verify must never overwrite the recorded email.
		$request->set_param( 'buyer_email', 'other@example.com' );
		$this->controller->verify_payment( $request );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_webhook_email' );
		$this->assertSame( 'late@example.com', $license['buyer_email'] );
	}

	/**
	 * /verify records the buyer's country code on a fresh license.
	 *
	 * @return void
	 */
	public function test_verify_records_buyer_country(): void {
		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_country',
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
		$request->set_param( 'payment_intent', 'pi_country' );
		$request->set_param( 'buyer_country', 'DE' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_country' );
		$this->assertNotNull( $license );
		$this->assertSame( 'DE', $license['buyer_country'] );
	}

	/**
	 * /verify attaches the country to a webhook-issued license, fill-once.
	 *
	 * @return void
	 */
	public function test_verify_attaches_buyer_country_to_webhook_issued_license(): void {
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'webhook-country',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_webhook_country',
				'amount'                => 4900,
			)
		);

		$this->stub_stripe(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'              => 'pi_webhook_country',
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
		$request->set_param( 'payment_intent', 'pi_webhook_country' );
		$request->set_param( 'buyer_country', 'FR' );

		$response = $this->controller->verify_payment( $request );

		$this->assertNotWPError( $response );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_webhook_country' );
		$this->assertSame( 'FR', $license['buyer_country'] );

		// A later /verify must never overwrite the recorded country.
		$request->set_param( 'buyer_country', 'ES' );
		$this->controller->verify_payment( $request );

		$license = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_webhook_country' );
		$this->assertSame( 'FR', $license['buyer_country'] );
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
	 * The payment_intent.succeeded webhook issues the license server-side
	 * (interrupted browser recovery) and is idempotent across Stripe retries.
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
						'receipt_email'   => 'webhook-buyer@example.com',
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
		$this->assertSame( 'webhook-buyer@example.com', $license['buyer_email'] );
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
				),
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

	/**
	 * The health probe returns a stable payload without touching Stripe.
	 *
	 * Customer sites use this endpoint to confirm the checkout API is
	 * reachable before starting a payment session, so it must never
	 * require Stripe credentials or spend a rate-limit token.
	 *
	 * @return void
	 */
	public function test_health_returns_ok_payload(): void {
		$response = $this->controller->health();

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'nvoos-checkout', $data['service'] );
		$this->assertIsString( $data['version'] );
		$this->assertTrue( $data['configured'] );
		$this->assertIsInt( $data['server_time'] );
	}

	/**
	 * The health probe works — and reports configured=false — even when
	 * the store has no Stripe credentials yet, so connectivity can be
	 * diagnosed before the storefront is set up.
	 *
	 * @return void
	 */
	public function test_health_reports_unconfigured_store(): void {
		delete_option( NVOOS_Checkout_API_Settings::OPTION );

		$response = $this->controller->health();

		$this->assertNotWPError( $response );
		$this->assertFalse( $response->get_data()['configured'] );
	}

	/**
	 * The health probe is never rate-limited, no matter how many times a
	 * customer site polls it while debugging connectivity.
	 *
	 * @return void
	 */
	public function test_health_is_not_rate_limited(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			$response = $this->controller->health();
			$this->assertNotWPError( $response );
		}
	}
}
