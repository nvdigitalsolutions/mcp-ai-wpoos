<?php
/**
 * Tests for HMAC-SHA256 signing in WP_MCP_AI_Pro_Schedule_Manager::fire_webhook_callback().
 *
 * Three canonical cases:
 *  1. No secret → no X-WP-MCP-AI-Signature header in the outgoing request.
 *  2. With secret → X-WP-MCP-AI-Signature header present and hash verifies correctly.
 *  3. Body tampered after signing → verification fails (demonstrates why the header matters).
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Webhook_Hmac
 */
class Test_Pro_Schedule_Webhook_Hmac extends WP_UnitTestCase {

	/**
	 * Captured pre_http_request arguments.
	 *
	 * @var array|null
	 */
	private $captured_request = null;

	/**
	 * Skip the whole class if Schedule Manager is unavailable.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not available.' );
		}
	}

	/**
	 * Register the HTTP intercept filter before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->captured_request = null;

		// Intercept wp_remote_post so no real HTTP request is made.
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	/**
	 * Remove the filter after each test.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ) );
		parent::tearDown();
	}

	/**
	 * Capture the outgoing request and return a fake 200 response.
	 *
	 * @param false|array $preempt  The preempt value.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 * @return array Fake HTTP response.
	 */
	public function intercept_http( $preempt, $args, $url ) {
		$this->captured_request = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Invoke fire_webhook_callback() via Reflection — it is protected.
	 *
	 * @param string $callback_url URL to POST to.
	 * @param string $schedule_id  Schedule identifier.
	 * @param array  $schedule     Schedule data array (may include callback_secret).
	 * @param bool   $success      Whether the run succeeded.
	 */
	private function invoke_fire_webhook( $callback_url, $schedule_id, array $schedule, $success = true ) {
		$ref    = new ReflectionClass( 'WP_MCP_AI_Pro_Schedule_Manager' );
		$method = $ref->getMethod( 'fire_webhook_callback' );
		$method->setAccessible( true );
		$method->invoke(
			null, // static method.
			$callback_url,
			$schedule_id,
			$schedule,
			$success,
			1.23,    // duration.
			'',      // error_msg.
			array()  // action_log.
		);
	}

	// -----------------------------------------------------------------------
	// Test 1 — No secret → no signature header.
	// -----------------------------------------------------------------------

	/**
	 * When callback_secret is absent, no signature header is sent.
	 */
	public function test_no_secret_produces_no_signature_header() {
		$schedule = array(
			'name'          => 'Test Schedule',
			'schedule_type' => 'task',
		// No callback_secret key.
		);

		$this->invoke_fire_webhook( 'https://example.com/hook', 'sched_abc', $schedule );

		$this->assertNotNull( $this->captured_request, 'HTTP request was not intercepted.' );
		$headers = $this->captured_request['args']['headers'] ?? array();

		$this->assertArrayNotHasKey(
			'X-WP-MCP-AI-Signature',
			$headers,
			'No Signature header should be present when callback_secret is empty.'
		);
		$this->assertArrayNotHasKey(
			'X-WP-MCP-AI-Timestamp',
			$headers,
			'No Timestamp header should be present when callback_secret is empty.'
		);
	}

	// -----------------------------------------------------------------------
	// Test 2 — With secret → signature header present and verifies.
	// -----------------------------------------------------------------------

	/**
	 * When callback_secret is set, the signature header is present and valid.
	 */
	public function test_with_secret_signature_header_is_correct() {
		$secret   = 'my-test-secret-key';
		$schedule = array(
			'name'            => 'Signed Schedule',
			'schedule_type'   => 'task',
			'callback_secret' => $secret,
		);

		$this->invoke_fire_webhook( 'https://example.com/hook', 'sched_def', $schedule );

		$this->assertNotNull( $this->captured_request, 'HTTP request was not intercepted.' );

		$headers = $this->captured_request['args']['headers'] ?? array();
		$body    = $this->captured_request['args']['body'] ?? '';

		$this->assertArrayHasKey( 'X-WP-MCP-AI-Signature', $headers, 'Signature header must be present.' );
		$this->assertArrayHasKey( 'X-WP-MCP-AI-Timestamp', $headers, 'Timestamp header must be present.' );

		$sig       = $headers['X-WP-MCP-AI-Signature'];
		$timestamp = $headers['X-WP-MCP-AI-Timestamp'];

		// Format: "sha256=<hex>".
		$this->assertStringStartsWith( 'sha256=', $sig, 'Signature must start with "sha256=".' );

		$expected_hex = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		$this->assertEquals( 'sha256=' . $expected_hex, $sig, 'HMAC value must match sha256(timestamp.body, secret).' );
	}

	// -----------------------------------------------------------------------
	// Test 3 — Body tamper → hash mismatch (demonstrates integrity protection).
	// -----------------------------------------------------------------------

	/**
	 * If the body is altered after signing, re-computing the HMAC produces a
	 * different value — demonstrates that the signature protects body integrity.
	 */
	public function test_tampered_body_does_not_match_signature() {
		$secret   = 'another-secret';
		$schedule = array(
			'name'            => 'Integrity Test',
			'schedule_type'   => 'task',
			'callback_secret' => $secret,
		);

		$this->invoke_fire_webhook( 'https://example.com/hook', 'sched_ghi', $schedule );

		$this->assertNotNull( $this->captured_request );

		$headers   = $this->captured_request['args']['headers'] ?? array();
		$body      = $this->captured_request['args']['body'] ?? '';
		$timestamp = $headers['X-WP-MCP-AI-Timestamp'] ?? '';
		$sig       = $headers['X-WP-MCP-AI-Signature'] ?? '';

		// Tamper: append an extra character to the body.
		$tampered_body = $body . 'X';
		$tampered_hex  = hash_hmac( 'sha256', $timestamp . '.' . $tampered_body, $secret );

		$this->assertNotEquals(
			$sig,
			'sha256=' . $tampered_hex,
			'HMAC over tampered body must differ from the original signature.'
		);
	}
}
