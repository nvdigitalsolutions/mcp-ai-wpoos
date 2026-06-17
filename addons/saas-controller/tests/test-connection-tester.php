<?php
/**
 * Tests for NVOOS_SaaS_Controller_Connection_Tester.
 *
 * Stubs `wp_remote_get` via the `pre_http_request` filter so the tests
 * never make real network calls.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for connection testing across all providers.
 *
 * @covers NVOOS_SaaS_Controller_Connection_Tester
 */
class Test_NVOOS_SaaS_Controller_Connection_Tester extends WP_UnitTestCase {

	/**
	 * Recorded outbound requests for assertions.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $captured = array();

	/**
	 * Canned responses by URL substring.
	 *
	 * @var array<string,array|WP_Error>
	 */
	private $canned = array();

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->captured = array();
		$this->canned   = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tearDown();
	}

	/**
	 * `pre_http_request` interceptor.
	 *
	 * @param mixed  $preempt Existing short-circuit (false to continue).
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->captured[] = array(
			'url'  => $url,
			'args' => $args,
		);
		foreach ( $this->canned as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}
		return new WP_Error( 'no_canned_response', 'No canned response for ' . $url );
	}

	/**
	 * Build an OK HTTP response with a given body and status.
	 *
	 * @param string $body   Response body.
	 * @param int    $status HTTP status code.
	 * @return array HTTP response array.
	 */
	private function ok_response( $body = '{}', $status = 200 ) {
		return array(
			'response' => array(
				'code' => $status,
				'message' => 'OK',
			),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	/**
	 * Test that Cloudflare connection test succeeds.
	 *
	 * @return void
	 */
	public function test_cloudflare_success() {
		$this->canned['api.cloudflare.com'] = $this->ok_response( wp_json_encode( array( 'success' => true ) ) );
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_cloudflare( 'abcdef0123456789abcdef0123456789', 'token-with-enough-chars' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'OK', $result['message'] );
		$this->assertNotEmpty( $this->captured );
		$this->assertStringContainsString(
			'/user/tokens/verify',
			$this->captured[0]['url'],
			'Preflight must use the token-verification endpoint, not an account-scoped URL.'
		);
		$this->assertSame(
			'Bearer token-with-enough-chars',
			$this->captured[0]['args']['headers']['Authorization']
		);
	}

	/**
	 * Test that Cloudflare invalid account ID short-circuits.
	 *
	 * @return void
	 */
	public function test_cloudflare_invalid_account_id_short_circuits() {
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_cloudflare( 'not-hex', 'whatever' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertEmpty( $this->captured, 'No HTTP request should fire for invalid account ID.' );
	}

	/**
	 * Test that Cloudflare 4xx extracts the provider message.
	 *
	 * @return void
	 */
	public function test_cloudflare_4xx_extracts_provider_message() {
		$this->canned['api.cloudflare.com'] = $this->ok_response(
			wp_json_encode(
				array(
					'success' => false,
					'errors'  => array(
						array(
							'code' => 9109,
							'message' => 'Invalid access token',
						),
					),
				)
			),
			403
		);
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_cloudflare( 'abcdef0123456789abcdef0123456789', 'bad-token-with-enough-chars' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 403, $result['status'] );
		$this->assertStringContainsString( 'Invalid access token', $result['message'] );
		$this->assertStringContainsString( 'HTTP 403', $result['message'] );
	}

	/**
	 * Test that Stripe connection test succeeds.
	 *
	 * @return void
	 */
	public function test_stripe_success() {
		$this->canned['api.stripe.com'] = $this->ok_response( wp_json_encode( array( 'id' => 'acct_123' ) ) );
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_stripe( 'sk_test_abcdef1234567890' );

		$this->assertTrue( $result['ok'] );
		$auth = $this->captured[0]['args']['headers']['Authorization'];
		$this->assertSame( 0, strpos( $auth, 'Basic ' ) );
		$decoded = base64_decode( substr( $auth, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( 'sk_test_abcdef1234567890:', $decoded );
	}

	/**
	 * Test that Stripe rejects a bad key prefix.
	 *
	 * @return void
	 */
	public function test_stripe_rejects_bad_prefix() {
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_stripe( 'pk_test_should_not_work' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * Test that Stripe extracts the error message.
	 *
	 * @return void
	 */
	public function test_stripe_extracts_error_message() {
		$this->canned['api.stripe.com'] = $this->ok_response(
			wp_json_encode( array( 'error' => array( 'message' => 'Invalid API Key provided.' ) ) ),
			401
		);
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_stripe( 'sk_test_invalidkey0987654321' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );
		$this->assertStringContainsString( 'Invalid API Key', $result['message'] );
	}

	/**
	 * Test that OpenRouter connection test succeeds.
	 *
	 * @return void
	 */
	public function test_openrouter_success() {
		$this->canned['openrouter.ai'] = $this->ok_response( wp_json_encode( array( 'data' => array( 'label' => 'My Key' ) ) ) );
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_openrouter( 'sk-or-v1-1234567890abcdefghijk' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			'Bearer sk-or-v1-1234567890abcdefghijk',
			$this->captured[0]['args']['headers']['Authorization']
		);
	}

	/**
	 * Test that network failure returns a WP_Error message.
	 *
	 * @return void
	 */
	public function test_network_failure_returns_wp_error_message() {
		$this->canned['openrouter.ai'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_openrouter( 'sk-or-v1-validkey0987654321zzz' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertStringContainsString( 'cURL error 28', $result['message'] );
	}

	/**
	 * Test that secrets never appear in the message.
	 *
	 * @return void
	 */
	public function test_secrets_never_appear_in_message() {
		$secret = 'sk_live_TOPSECRETKEY12345abcdef';
		$this->canned['api.stripe.com'] = $this->ok_response(
			wp_json_encode( array( 'error' => array( 'message' => 'Authentication required.' ) ) ),
			401
		);
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_stripe( $secret );

		$this->assertFalse( $result['ok'] );
		$this->assertStringNotContainsString( $secret, $result['message'] );
		$this->assertStringNotContainsString( 'TOPSECRETKEY', $result['message'] );
	}

	/**
	 * Test that test_all falls back to stored credentials.
	 *
	 * @return void
	 */
	public function test_test_all_falls_back_to_stored_credentials() {
		// Pre-populate the store.
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set(
			array(
				'cloudflare_account_id' => 'abcdef0123456789abcdef0123456789',
				'cloudflare_api_token'  => 'stored-token-with-enough-chars',
				'stripe_secret_key'     => 'sk_test_storedkey1234567890',
				'stripe_webhook_secret' => 'whsec_storedhook1234567890',
				'openrouter_api_key'    => 'sk-or-v1-storedkey1234567890',
			)
		);

		$this->canned['api.cloudflare.com'] = $this->ok_response();
		$this->canned['api.stripe.com']     = $this->ok_response();
		$this->canned['openrouter.ai']      = $this->ok_response();

		$tester  = new NVOOS_SaaS_Controller_Connection_Tester();
		$results = $tester->test_all( array() );

		$this->assertTrue( $results['cloudflare']['ok'] );
		$this->assertTrue( $results['stripe']['ok'] );
		$this->assertTrue( $results['openrouter']['ok'] );

		$cf_call = null;
		foreach ( $this->captured as $call ) {
			if ( false !== strpos( $call['url'], 'api.cloudflare.com' ) ) {
				$cf_call = $call;
				break;
			}
		}
		$this->assertNotNull( $cf_call );
		$this->assertStringContainsString( '/user/tokens/verify', $cf_call['url'] );
		$this->assertSame(
			'Bearer stored-token-with-enough-chars',
			$cf_call['args']['headers']['Authorization']
		);

		$store->clear_all();
	}

	/**
	 * Test that a supplied value overrides the stored value.
	 *
	 * @return void
	 */
	public function test_supplied_value_overrides_stored() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set( array( 'openrouter_api_key' => 'sk-or-v1-storedkey1234567890' ) );

		$this->canned['openrouter.ai'] = $this->ok_response();

		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$tester->test_all( array( 'openrouter_api_key' => 'sk-or-v1-supplied99999999999' ) );

		$or_call = null;
		foreach ( $this->captured as $call ) {
			if ( false !== strpos( $call['url'], 'openrouter.ai' ) ) {
				$or_call = $call;
				break;
			}
		}
		$this->assertNotNull( $or_call );
		$this->assertSame(
			'Bearer sk-or-v1-supplied99999999999',
			$or_call['args']['headers']['Authorization']
		);

		$store->clear_all();
	}

	/**
	 * Test that an oversized response body is truncated.
	 *
	 * @return void
	 */
	public function test_oversized_response_body_is_truncated() {
		$big = str_repeat( 'A', 100000 );
		$this->canned['api.cloudflare.com'] = $this->ok_response(
			wp_json_encode( array( 'errors' => array( array( 'message' => $big ) ) ) ),
			500
		);
		$tester = new NVOOS_SaaS_Controller_Connection_Tester();
		$result = $tester->test_cloudflare( 'abcdef0123456789abcdef0123456789', 'tok-with-enough-chars-here' );

		$this->assertFalse( $result['ok'] );
		// sanitize_message truncates to 280 chars + "HTTP 500 — " prefix.
		$this->assertLessThan( 320, strlen( $result['message'] ) );
	}
}
