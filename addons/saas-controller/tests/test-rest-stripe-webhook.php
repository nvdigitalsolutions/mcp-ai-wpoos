<?php
/**
 * REST integration tests for the Stripe webhook receiver (Phase 7).
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * REST integration tests for the Stripe webhook route.
 *
 * @covers NVOOS_SaaS_Controller_REST::route_stripe_webhook
 * @covers NVOOS_SaaS_Controller_REST::route_get_webhook_events
 * @covers NVOOS_SaaS_Controller_REST::route_clear_webhook_events
 */
class Test_NVOOS_SaaS_Controller_REST_Stripe_Webhook extends WP_Test_REST_TestCase {

	const SECRET = 'whsec_test_supersecret_value_1234567890';

	/**
	 * Admin user for capability-gated routes.
	 *
	 * @var int
	 */
	protected static $admin_id = 0;

	/**
	 * Set up before the test class.
	 *
	 * @param WP_UnitTest_Factory_For_Thing $factory The test factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		// Re-register the routes for each test (rest server is rebuilt by
		// WP_Test_REST_TestCase between cases). Registration must go through
		// the rest_api_init action: WP 6.x raises a _doing_it_wrong notice
		// (which wp-phpunit turns into a test failure) when
		// register_rest_route() is called outside it, and in the CLI test
		// environment rest_api_init never fires at bootstrap.
		do_action( 'rest_api_init' );

		delete_option( NVOOS_SaaS_Controller_Webhook_Event_Store::OPTION );
		NVOOS_SaaS_Controller_Webhook_Event_Store::reset_for_tests();
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();

		// Seed the credential store with a known webhook secret.
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set( array( 'stripe_webhook_secret' => self::SECRET ) );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( NVOOS_SaaS_Controller_Webhook_Event_Store::OPTION );
		NVOOS_SaaS_Controller_Webhook_Event_Store::reset_for_tests();
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
		parent::tear_down();
	}

	/**
	 * Build a valid Stripe-Signature header.
	 *
	 * @param string $body   The webhook body.
	 * @param int    $ts     The timestamp.
	 * @param string $secret The webhook secret.
	 * @return string The signature header.
	 */
	private function sign( $body, $ts, $secret = self::SECRET ) {
		$payload = $ts . '.' . $body;
		return 't=' . $ts . ',v1=' . hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Build a REST request for the webhook endpoint.
	 *
	 * @param string      $body      The request body.
	 * @param string|null $signature The Stripe-Signature header value.
	 * @return WP_REST_Request
	 */
	private function build_request( $body, $signature ) {
		$request = new WP_REST_Request( 'POST', '/nvoos-saas/v1/webhooks/stripe' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		if ( null !== $signature ) {
			$request->set_header( 'stripe-signature', $signature );
		}
		return $request;
	}

	/**
	 * Build a valid webhook event body.
	 *
	 * @param string $event_id The event ID.
	 * @param string $type     The event type.
	 * @return string JSON-encoded event body.
	 */
	private function event_body( $event_id = 'evt_rest_1', $type = 'invoice.paid' ) {
		return wp_json_encode(
			array(
				'id'   => $event_id,
				'type' => $type,
				'data' => array( 'object' => array( 'id' => 'in_test_1' ) ),
			)
		);
	}

	/**
	 * Test that a valid webhook returns 200 and records the event.
	 *
	 * @return void
	 */
	public function test_valid_webhook_returns_200_and_records_event() {
		$body     = $this->event_body( 'evt_rest_ok', 'customer.subscription.updated' );
		$now      = time();
		$request  = $this->build_request( $body, $this->sign( $body, $now ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'evt_rest_ok', $data['event_id'] );
		$this->assertSame( 'customer.subscription.updated', $data['event_type'] );
		$this->assertFalse( $data['duplicate'] );
		$this->assertTrue( $data['recorded'] );

		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$this->assertSame( 1, $store->count() );
	}

	/**
	 * Test that a duplicate delivery is idempotent.
	 *
	 * @return void
	 */
	public function test_duplicate_delivery_is_idempotent() {
		$body    = $this->event_body( 'evt_dup', 'invoice.paid' );
		$now     = time();
		$sig     = $this->sign( $body, $now );

		$first = rest_get_server()->dispatch( $this->build_request( $body, $sig ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertFalse( $first->get_data()['duplicate'] );

		$second = rest_get_server()->dispatch( $this->build_request( $body, $sig ) );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['duplicate'] );

		$this->assertSame( 1, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );

		// Audit log: only one `webhook_received` ok entry should appear
		// despite two deliveries (the duplicate must not flood the log).
		$audit = NVOOS_SaaS_Controller_Audit_Log::instance();
		$received_ok = 0;
		foreach ( $audit->get_recent( 50 ) as $row ) {
			if ( 'webhook_received' === $row['action'] && 'ok' === $row['status'] ) {
				$received_ok++;
			}
		}
		$this->assertSame( 1, $received_ok );
	}

	/**
	 * Test that a missing signature returns 401.
	 *
	 * @return void
	 */
	public function test_missing_signature_returns_401() {
		$body     = $this->event_body();
		$request  = $this->build_request( $body, null );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );
	}

	/**
	 * Test that a signature mismatch returns 401.
	 *
	 * @return void
	 */
	public function test_signature_mismatch_returns_401() {
		$body    = $this->event_body();
		$now     = time();
		$bad_sig = $this->sign( $body, $now, 'whsec_wrong' );
		$request = $this->build_request( $body, $bad_sig );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );
	}

	/**
	 * Test that a replay outside tolerance returns 401.
	 *
	 * @return void
	 */
	public function test_replay_outside_tolerance_returns_401() {
		$body    = $this->event_body();
		$old     = time() - 3600;
		$sig     = $this->sign( $body, $old );
		$request = $this->build_request( $body, $sig );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );
	}

	/**
	 * Test that an invalid JSON body returns 400.
	 *
	 * @return void
	 */
	public function test_invalid_json_body_returns_400() {
		$body    = 'not-json';
		$now     = time();
		$sig     = $this->sign( $body, $now );
		$request = $this->build_request( $body, $sig );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );
	}

	/**
	 * Test that a missing secret returns 412.
	 *
	 * @return void
	 */
	public function test_missing_secret_returns_412() {
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
		$body    = $this->event_body();
		$now     = time();
		$sig     = $this->sign( $body, $now );
		$request = $this->build_request( $body, $sig );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 412, $response->get_status() );
	}

	/**
	 * Test that get_webhook_events requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_get_webhook_events_requires_manage_options() {
		// Anonymous user — must be denied.
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/nvoos-saas/v1/webhooks/events' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	/**
	 * Test that get_webhook_events returns recorded entries for admin.
	 *
	 * @return void
	 */
	public function test_get_webhook_events_returns_recorded_entries_for_admin() {
		// Record a couple of events first via the public route.
		$now = time();
		foreach ( array( 'evt_a', 'evt_b' ) as $id ) {
			$body = $this->event_body( $id, 'invoice.paid' );
			rest_get_server()->dispatch( $this->build_request( $body, $this->sign( $body, $now ) ) );
		}

		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/nvoos-saas/v1/webhooks/events' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 2, $data['total'] );
		$this->assertCount( 2, $data['entries'] );
		// Newest first.
		$this->assertSame( 'evt_b', $data['entries'][0]['event_id'] );
	}

	/**
	 * Test that delete_webhook_events requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_delete_webhook_events_requires_manage_options() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'DELETE', '/nvoos-saas/v1/webhooks/events' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	/**
	 * Test that delete_webhook_events clears the store for admin.
	 *
	 * @return void
	 */
	public function test_delete_webhook_events_clears_store_for_admin() {
		// Record one.
		$body = $this->event_body( 'evt_to_clear' );
		$now  = time();
		rest_get_server()->dispatch( $this->build_request( $body, $this->sign( $body, $now ) ) );
		$this->assertSame( 1, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );

		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'DELETE', '/nvoos-saas/v1/webhooks/events' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->count() );
	}
}
