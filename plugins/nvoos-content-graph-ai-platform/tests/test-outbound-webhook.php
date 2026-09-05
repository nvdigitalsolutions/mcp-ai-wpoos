<?php
/**
 * Outbound webhook port tests (Wave E2).
 *
 * Characterization suite for the ported `OutboundWebhook`:
 * byte-identical option key, subscription lifecycle (`whk_*` IDs,
 * sanitized URL/events, enabled flag), dispatch filtering (specific
 * events, wildcard, disabled subscriptions), the signed non-blocking
 * HTTP POST contract (JSON body, event/delivery/signature headers),
 * inbound signature verification (hash_equals, with and without a
 * secret), and the four core event listeners. HTTP is intercepted via
 * `pre_http_request` so no real network traffic occurs.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\OutboundWebhook;

/**
 * @group queues
 */
class Test_Outbound_Webhook extends \WP_UnitTestCase {

	/**
	 * Captured wp_remote_post requests.
	 *
	 * @var array
	 */
	private $captured_requests = array();

	/**
	 * Singleton fixture with clean per-test state.
	 *
	 * @return OutboundWebhook
	 */
	protected function fresh_instance() {
		$ref = new \ReflectionProperty( OutboundWebhook::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		return OutboundWebhook::get_instance();
	}

	public function setUp(): void {
		parent::setUp();

		delete_option( OutboundWebhook::OPTION_KEY );
		$this->captured_requests = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->captured_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );

		delete_option( OutboundWebhook::OPTION_KEY );

		parent::tearDown();
	}

	// ─── Constants + singleton ──────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_outbound_webhooks', OutboundWebhook::OPTION_KEY );
	}

	public function test_get_instance_is_singleton(): void {
		$first = $this->fresh_instance();

		$this->assertSame( $first, OutboundWebhook::get_instance() );
	}

	// ─── Subscription lifecycle ─────────────────────────────────────

	public function test_subscribe_and_list(): void {
		$instance = $this->fresh_instance();

		$id = $instance->subscribe(
			'https://example.com/hook',
			array( 'workflow.completed', 'approval.requested' ),
			'shared-secret'
		);

		$this->assertMatchesRegularExpression( '/^whk_[a-z0-9]{16}$/', $id );

		$subscriptions = $instance->list_subscriptions();
		$this->assertCount( 1, $subscriptions );

		$sub = $subscriptions[0];
		$this->assertSame( $id, $sub['id'] );
		$this->assertSame( 'https://example.com/hook', $sub['url'] );
		$this->assertSame( array( 'workflow.completed', 'approval.requested' ), $sub['events'] );
		$this->assertSame( 'shared-secret', $sub['secret'] );
		$this->assertTrue( $sub['enabled'] );
		$this->assertGreaterThan( 0, $sub['created_at'] );
	}

	public function test_subscribe_sanitises_url_and_events(): void {
		$instance = $this->fresh_instance();

		$id = $instance->subscribe(
			'https://example.com/hook?q=<script>',
			array( 'workflow.completed<script>' ),
			'<b>secret</b>'
		);

		$sub = $instance->list_subscriptions()[0];
		$this->assertStringNotContainsString( '<script>', $sub['url'] );
		$this->assertSame( array( 'workflow.completed' ), $sub['events'] );
		$this->assertSame( 'secret', $sub['secret'] );
	}

	public function test_unsubscribe(): void {
		$instance = $this->fresh_instance();

		$id = $instance->subscribe( 'https://example.com/hook', array( '*' ), '' );

		$this->assertTrue( $instance->unsubscribe( $id ) );
		$this->assertSame( array(), $instance->list_subscriptions() );
		$this->assertFalse( $instance->unsubscribe( $id ) );
	}

	// ─── Dispatch ───────────────────────────────────────────────────

	public function test_dispatch_matches_specific_and_wildcard_subscriptions(): void {
		$instance = $this->fresh_instance();

		$instance->subscribe( 'https://a.example/hook', array( 'workflow.completed' ), 'secret-a' );
		$instance->subscribe( 'https://b.example/hook', array( '*' ), '' );
		$instance->subscribe( 'https://c.example/hook', array( 'workflow.failed' ), '' );

		$count = $instance->dispatch( 'workflow.completed', array( 'run_id' => 42 ) );

		$this->assertSame( 2, $count );
		$this->assertCount( 2, $this->captured_requests );

		$urls = array_column( $this->captured_requests, 'url' );
		$this->assertContains( 'https://a.example/hook', $urls );
		$this->assertContains( 'https://b.example/hook', $urls );
		$this->assertNotContains( 'https://c.example/hook', $urls );
	}

	public function test_dispatch_skips_disabled_subscriptions(): void {
		$instance = $this->fresh_instance();

		$id = $instance->subscribe( 'https://a.example/hook', array( '*' ), '' );

		// Disable the subscription through the storage seam.
		$subscriptions = get_option( OutboundWebhook::OPTION_KEY, array() );

		if ( isset( $subscriptions[ $id ] ) ) {
			$subscriptions[ $id ]['enabled'] = false;
		}

		update_option( OutboundWebhook::OPTION_KEY, $subscriptions, false );

		$this->assertSame( 0, $instance->dispatch( 'workflow.completed', array() ) );
		$this->assertSame( array(), $this->captured_requests );
	}

	public function test_dispatch_post_contract(): void {
		$instance = $this->fresh_instance();

		$instance->subscribe( 'https://a.example/hook', array( 'workflow.completed' ), 'secret-a' );

		$instance->dispatch(
			'workflow.completed',
			array(
				'run_id'  => 7,
				'details' => array( 'k' => 'v' ),
			)
		);

		$this->assertCount( 1, $this->captured_requests );

		$request = $this->captured_requests[0];
		$this->assertSame( 'https://a.example/hook', $request['url'] );
		$this->assertSame( 5, $request['args']['timeout'] );
		$this->assertFalse( $request['args']['blocking'] );

		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( 'workflow.completed', $body['event'] );
		$this->assertSame( 7, $body['payload']['run_id'] );
		$this->assertSame( array( 'k' => 'v' ), $body['payload']['details'] );
		$this->assertGreaterThan( 0, $body['dispatched'] );

		$headers = $request['args']['headers'];
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'workflow.completed', $headers['X-WP-MCP-AI-Event'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{12}$/', $headers['X-WP-MCP-AI-Delivery'] );

		// The signature verifies against the stored secret.
		$signature = $headers['X-WP-MCP-AI-Signature-256'];
		$this->assertStringStartsWith( 'sha256=', $signature );
		$this->assertTrue(
			$instance->verify_signature( $request['args']['body'], $signature, 'secret-a' )
		);
	}

	// ─── Signature verification ─────────────────────────────────────

	public function test_verify_signature_with_secret(): void {
		$instance = $this->fresh_instance();

		$payload   = '{"event":"workflow.completed"}';
		$signature = 'sha256=' . hash_hmac( 'sha256', $payload, 'top-secret' );

		$this->assertTrue( $instance->verify_signature( $payload, $signature, 'top-secret' ) );
		$this->assertFalse( $instance->verify_signature( $payload, $signature, 'wrong-secret' ) );
		$this->assertFalse( $instance->verify_signature( $payload . 'tampered', $signature, 'top-secret' ) );
	}

	public function test_verify_signature_without_secret(): void {
		$instance = $this->fresh_instance();

		$payload   = '{"event":"workflow.completed"}';
		$signature = 'sha256=' . hash( 'sha256', $payload );

		$this->assertTrue( $instance->verify_signature( $payload, $signature, '' ) );
	}

	// ─── Event listeners ────────────────────────────────────────────

	public function test_event_listeners_register_and_dispatch(): void {
		$instance = $this->fresh_instance();

		$this->assertSame(
			10,
			has_action( 'wp_mcp_ai_workflow_run_completed', array( $instance, 'on_workflow_completed' ) )
		);
		$this->assertSame(
			10,
			has_action( 'wp_mcp_ai_workflow_run_failed', array( $instance, 'on_workflow_failed' ) )
		);
		$this->assertSame(
			10,
			has_action( 'wp_mcp_ai_workflow_run_paused', array( $instance, 'on_workflow_paused' ) )
		);
		$this->assertSame(
			10,
			has_action( 'wp_mcp_ai_approval_requested', array( $instance, 'on_approval_requested' ) )
		);

		$instance->subscribe( 'https://events.example/hook', array( '*' ), '' );

		$instance->on_workflow_completed( 11, array( 'a' => 1 ) );
		$instance->on_workflow_failed( 12, array( 'a' => 2 ) );
		$instance->on_workflow_paused( 13, array( 'a' => 3 ) );
		$instance->on_approval_requested( 14, array( 'a' => 4 ) );

		$this->assertCount( 4, $this->captured_requests );

		$events = array();
		foreach ( $this->captured_requests as $request ) {
			$body     = json_decode( $request['args']['body'], true );
			$events[] = $body['event'];
		}

		$this->assertSame(
			array( 'workflow.completed', 'workflow.failed', 'workflow.paused', 'approval.requested' ),
			$events
		);
	}
}
