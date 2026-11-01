<?php
/**
 * Rebuild webhook tests — debounce, dedupe, HMAC signing, and the outbound
 * POST contract (via the pre_http_request interception seam).
 *
 * @package NvoosForgeBridge
 */

use NvoosForgeBridge\Rebuild_Webhook;
use NvoosForgeBridge\Settings;

/**
 * Webhook suite.
 */
class Test_Forge_Bridge_Webhook extends WP_UnitTestCase {

	/**
	 * Captured outbound requests.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $captured = array();

	/**
	 * Reset options, debounce, and the HTTP capture between tests.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( Settings::option_keys() as $option ) {
			delete_option( $option );
		}
		delete_transient( 'nvoos_forge_bridge_webhook_sent' );
		Rebuild_Webhook::reset_pending();
		$this->captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->captured[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 204, 'message' => 'No Content' ),
				);
			},
			10,
			3
		);
	}

	/**
	 * Teardown.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Helper — configure a fully enabled webhook.
	 *
	 * @return void
	 */
	private function configure(): void {
		update_option( Settings::OPTION_REBUILD_ENABLED, '1' );
		update_option( Settings::OPTION_REBUILD_URL, 'https://api.github.com/repos/o/r/dispatches' );
		update_option( Settings::OPTION_REBUILD_TOKEN, 'gh-token' );
		update_option( Settings::OPTION_REBUILD_SECRET, 'shared-secret' );
	}

	/**
	 * Unconfigured = hooks no-op and nothing fires.
	 */
	public function test_noop_when_unconfigured(): void {
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::send_pending();
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * Disabled (but otherwise configured) = still a no-op.
	 */
	public function test_noop_when_disabled(): void {
		update_option( Settings::OPTION_REBUILD_URL, 'https://api.github.com/repos/o/r/dispatches' );
		update_option( Settings::OPTION_REBUILD_TOKEN, 'gh-token' );
		update_option( Settings::OPTION_REBUILD_SECRET, 'shared-secret' );
		update_option( Settings::OPTION_REBUILD_ENABLED, '0' );

		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::send_pending();
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * One content change produces one signed dispatch POST.
	 */
	public function test_sends_signed_dispatch(): void {
		$this->configure();
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::send_pending();

		$this->assertCount( 1, $this->captured );
		$request = $this->captured[0];
		$this->assertSame( 'https://api.github.com/repos/o/r/dispatches', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer gh-token', $request['args']['headers']['Authorization'] );

		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( 'nvoos-forge-content-changed', $body['event_type'] );
		$this->assertArrayHasKey( 'site', $body['client_payload'] );
		$this->assertSame( 'content', $body['client_payload']['type'] );

		// The signature is a valid HMAC over the exact body.
		$signature = $request['args']['headers']['X-Forge-Signature'];
		$this->assertStringStartsWith( 'sha256=', $signature );
		$this->assertTrue( Rebuild_Webhook::verify( $request['args']['body'], $signature ) );

		// The payload carries its own proof (Phase 3.3 contract): HMAC over
		// "<time>.<nonce>" — GitHub's dispatch delivery re-serializes the body,
		// so the generated pipeline re-verifies this value.
		$payload = $body['client_payload'];
		$this->assertArrayHasKey( 'time', $payload );
		$this->assertArrayHasKey( 'nonce', $payload );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}$/', $payload['nonce'] );
		$this->assertSame(
			hash_hmac( 'sha256', $payload['time'] . '.' . $payload['nonce'], 'shared-secret' ),
			$payload['signature']
		);
	}

	/**
	 * Multiple change hooks in one request = exactly one send (dedupe).
	 */
	public function test_in_request_dedupe(): void {
		$this->configure();
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::send_pending();
		$this->assertCount( 1, $this->captured );
	}

	/**
	 * The debounce transient blocks a second send within the window.
	 */
	public function test_debounce_transient_blocks_resend(): void {
		$this->configure();
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::send_pending();
		$this->assertCount( 1, $this->captured );

		// A second change inside the window schedules but must not send.
		Rebuild_Webhook::on_content_changed();
		Rebuild_Webhook::send_pending();
		$this->assertCount( 1, $this->captured );
		$this->assertNotEmpty( get_transient( 'nvoos_forge_bridge_webhook_sent' ) );
	}

	/**
	 * send_pending without a pending flag never fires.
	 */
	public function test_send_pending_without_schedule(): void {
		$this->configure();
		Rebuild_Webhook::send_pending();
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * HMAC verification fails closed on tampered payloads and junk.
	 */
	public function test_verify_fails_closed(): void {
		update_option( Settings::OPTION_REBUILD_SECRET, 'shared-secret' );
		$payload   = '{"x":1}';
		$signature = Rebuild_Webhook::sign( $payload );

		$this->assertTrue( Rebuild_Webhook::verify( $payload, $signature ) );
		$this->assertFalse( Rebuild_Webhook::verify( '{"x":2}', $signature ) );
		$this->assertFalse( Rebuild_Webhook::verify( $payload, 'sha256=' . str_repeat( '0', 64 ) ) );
		$this->assertFalse( Rebuild_Webhook::verify( $payload, '' ) );
		$this->assertFalse( Rebuild_Webhook::verify( $payload, 'garbage' ) );
	}

	/**
	 * The payload signature is the documented canonical string and fails
	 * closed without a secret.
	 */
	public function test_payload_signature_contract(): void {
		update_option( Settings::OPTION_REBUILD_SECRET, 'shared-secret' );
		$this->assertSame(
			hash_hmac( 'sha256', '1700000000.deadbeef', 'shared-secret' ),
			Rebuild_Webhook::payload_signature( '1700000000', 'deadbeef' )
		);
		delete_option( Settings::OPTION_REBUILD_SECRET );
		$this->assertSame( '', Rebuild_Webhook::payload_signature( '1700000000', 'deadbeef' ) );
	}

	/**
	 * Without a secret, sign() is empty and everything fails closed.
	 */
	public function test_sign_empty_without_secret(): void {
		$this->assertSame( '', Rebuild_Webhook::sign( 'payload' ) );
		$this->assertFalse( Rebuild_Webhook::verify( 'payload', '' ) );
	}
}
