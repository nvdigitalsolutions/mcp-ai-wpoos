<?php
/**
 * Tests for NVOOS_SaaS_Controller_Stripe_Webhook_Verifier (Phase 7).
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for Stripe webhook signature verification.
 *
 * @covers NVOOS_SaaS_Controller_Stripe_Webhook_Verifier
 */
class Test_NVOOS_SaaS_Controller_Stripe_Webhook_Verifier extends WP_UnitTestCase {

	const SECRET = 'whsec_test_supersecret_value_1234567890';

	/**
	 * Build a valid Stripe-Signature header for a given body and timestamp.
	 *
	 * @param string $body   The webhook body.
	 * @param int    $ts     The timestamp.
	 * @param string $secret The webhook secret.
	 * @return string The signature header.
	 */
	private function sign( $body, $ts, $secret = self::SECRET ) {
		$payload = $ts . '.' . $body;
		$v1      = hash_hmac( 'sha256', $payload, $secret );
		return 't=' . $ts . ',v1=' . $v1;
	}

	/**
	 * Build a valid webhook event body.
	 *
	 * @param string $event_id The event ID.
	 * @param string $type     The event type.
	 * @return string The JSON-encoded body.
	 */
	private function valid_body( $event_id = 'evt_test_1', $type = 'invoice.paid' ) {
		return wp_json_encode(
			array(
				'id'   => $event_id,
				'type' => $type,
				'data' => array( 'object' => array( 'foo' => 'bar' ) ),
			)
		);
	}

	/**
	 * Test that verify returns ok for valid signature and timestamp.
	 *
	 * @return void
	 */
	public function test_verify_returns_ok_for_valid_signature_and_timestamp() {
		$now  = 1700000000;
		$body = $this->valid_body( 'evt_ok_1', 'customer.subscription.created' );
		$sig  = $this->sign( $body, $now );

		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $sig, self::SECRET, 300, $now );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'verified', $result['reason'] );
		$this->assertSame( $now, $result['timestamp'] );
		$this->assertSame( 'evt_ok_1', $result['event_id'] );
		$this->assertSame( 'customer.subscription.created', $result['event_type'] );
	}

	/**
	 * Test that verify rejects a missing secret.
	 *
	 * @return void
	 */
	public function test_verify_rejects_missing_secret() {
		$body   = $this->valid_body();
		$sig    = $this->sign( $body, 1700000000 );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $sig, '', 300, 1700000000 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'missing_secret', $result['reason'] );
	}

	/**
	 * Test that verify rejects a missing signature header.
	 *
	 * @return void
	 */
	public function test_verify_rejects_missing_signature_header() {
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $this->valid_body(), '', self::SECRET, 300, 1700000000 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'missing_signature', $result['reason'] );
	}

	/**
	 * Test that verify rejects an empty body.
	 *
	 * @return void
	 */
	public function test_verify_rejects_empty_body() {
		$sig    = $this->sign( '', 1700000000 );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( '', $sig, self::SECRET, 300, 1700000000 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty_body', $result['reason'] );
	}

	/**
	 * Test that verify rejects a malformed header with no pairs.
	 *
	 * @return void
	 */
	public function test_verify_rejects_malformed_header_no_pairs() {
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $this->valid_body(), 'not-a-stripe-signature', self::SECRET, 300, 1700000000 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'malformed_signature', $result['reason'] );
	}

	/**
	 * Test that verify rejects a malformed header with non-numeric timestamp.
	 *
	 * @return void
	 */
	public function test_verify_rejects_malformed_header_non_numeric_timestamp() {
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $this->valid_body(), 't=notanumber,v1=' . str_repeat( 'a', 64 ), self::SECRET, 300, 1700000000 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'malformed_signature', $result['reason'] );
	}

	/**
	 * Test that verify rejects a header missing v1.
	 *
	 * @return void
	 */
	public function test_verify_rejects_header_missing_v1() {
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $this->valid_body(), 't=1700000000', self::SECRET, 300, 1700000000 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'malformed_signature', $result['reason'] );
	}

	/**
	 * Test that verify rejects a timestamp outside tolerance.
	 *
	 * @return void
	 */
	public function test_verify_rejects_timestamp_outside_tolerance() {
		$now    = 1700000000;
		$old    = $now - 600; // 10 min old, default tolerance 5 min
		$body   = $this->valid_body();
		$sig    = $this->sign( $body, $old );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $sig, self::SECRET, 300, $now );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'timestamp_outside_tolerance', $result['reason'] );
		$this->assertSame( $old, $result['timestamp'] );
	}

	/**
	 * Test that verify accepts within a custom tolerance.
	 *
	 * @return void
	 */
	public function test_verify_accepts_within_custom_tolerance() {
		$now    = 1700000000;
		$old    = $now - 600;
		$body   = $this->valid_body();
		$sig    = $this->sign( $body, $old );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $sig, self::SECRET, 1200, $now );
		$this->assertTrue( $result['ok'] );
	}

	/**
	 * Test that verify rejects a signature mismatch.
	 *
	 * @return void
	 */
	public function test_verify_rejects_signature_mismatch() {
		$now    = 1700000000;
		$body   = $this->valid_body();
		$sig    = $this->sign( $body, $now, 'whsec_wrong_secret' );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $sig, self::SECRET, 300, $now );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'signature_mismatch', $result['reason'] );
	}

	/**
	 * Test that verify rejects a modified body.
	 *
	 * @return void
	 */
	public function test_verify_rejects_modified_body() {
		$now    = 1700000000;
		$body   = $this->valid_body();
		$sig    = $this->sign( $body, $now );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body . '_tampered', $sig, self::SECRET, 300, $now );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'signature_mismatch', $result['reason'] );
	}

	/**
	 * Test that verify accepts during secret rotation with multiple v1 values.
	 *
	 * @return void
	 */
	public function test_verify_accepts_during_secret_rotation_with_multiple_v1() {
		// Two v1= values: one matches the old (wrong) secret, one matches the
		// real secret. Stripe ships both during rotation; we should accept.
		$now     = 1700000000;
		$body    = $this->valid_body();
		$payload = $now . '.' . $body;
		$wrong   = hash_hmac( 'sha256', $payload, 'whsec_old_rotated_out' );
		$right   = hash_hmac( 'sha256', $payload, self::SECRET );
		$header  = 't=' . $now . ',v1=' . $wrong . ',v1=' . $right;
		$result  = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $header, self::SECRET, 300, $now );
		$this->assertTrue( $result['ok'] );
	}

	/**
	 * Test that verify ignores unrelated signature schemes.
	 *
	 * @return void
	 */
	public function test_verify_ignores_unrelated_signature_schemes() {
		// `v0=` is a legacy / unrelated scheme — must not be matched against.
		$now     = 1700000000;
		$body    = $this->valid_body();
		$payload = $now . '.' . $body;
		$right   = hash_hmac( 'sha256', $payload, self::SECRET );
		$header  = 't=' . $now . ',v0=' . str_repeat( 'a', 64 ) . ',v1=' . $right;
		$result  = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $header, self::SECRET, 300, $now );
		$this->assertTrue( $result['ok'] );
	}

	/**
	 * Test that verify rejects an invalid JSON body.
	 *
	 * @return void
	 */
	public function test_verify_rejects_invalid_json_body() {
		$now    = 1700000000;
		$body   = 'not-json-at-all';
		$sig    = $this->sign( $body, $now );
		$result = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $body, $sig, self::SECRET, 300, $now );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_json', $result['reason'] );
	}
}
