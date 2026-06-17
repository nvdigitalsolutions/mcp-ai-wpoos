<?php
/**
 * Tests for WP_MCP_AI_Outbound_Webhook.
 *
 * @package WP_MCP_AI
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test suite for the outbound webhook subsystem.
 *
 * @since 1.6.0
 */
class Test_Outbound_Webhook extends WP_UnitTestCase {

	/**
	 * Clean up the option before/after each test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Outbound_Webhook' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Outbound_Webhook not loaded.' );
		}
		delete_option( 'wp_mcp_ai_outbound_webhooks' );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_outbound_webhooks' );
		parent::tearDown();
	}

	/**
	 * Get a fresh instance (reset singleton).
	 *
	 * @return WP_MCP_AI_Outbound_Webhook
	 */
	protected function get_instance() {
		$ref = new ReflectionProperty( WP_MCP_AI_Outbound_Webhook::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		return WP_MCP_AI_Outbound_Webhook::get_instance();
	}

	/**
	 * Test subscribe stores a subscription.
	 */
	public function test_subscribe_stores_subscription() {
		$wh = $this->get_instance();
		$id = $wh->subscribe( 'https://example.com/hook', array( 'workflow.completed' ), 'secret123' );
		$this->assertIsString( $id );
		$this->assertNotEmpty( $id );

		$subs = $wh->list_subscriptions();
		$this->assertCount( 1, $subs );
		$this->assertSame( 'https://example.com/hook', $subs[0]['url'] );
	}

	/**
	 * Test unsubscribe removes the subscription.
	 */
	public function test_unsubscribe_removes_subscription() {
		$wh = $this->get_instance();
		$id = $wh->subscribe( 'https://example.com/hook', array( 'workflow.completed' ) );
		$result = $wh->unsubscribe( $id );
		$this->assertTrue( $result );
		$this->assertEmpty( $wh->list_subscriptions() );
	}

	/**
	 * Test unsubscribe returns false for unknown ID.
	 */
	public function test_unsubscribe_returns_false_for_unknown() {
		$wh = $this->get_instance();
		$result = $wh->unsubscribe( 'nonexistent_id_xyz' );
		$this->assertFalse( $result );
	}

	/**
	 * Test list_subscriptions returns empty array when none registered.
	 */
	public function test_list_subscriptions_empty_by_default() {
		$wh = $this->get_instance();
		$this->assertSame( array(), $wh->list_subscriptions() );
	}

	/**
	 * Test verify_signature matches expected HMAC.
	 */
	public function test_verify_signature() {
		$wh      = $this->get_instance();
		$payload = '{"event":"workflow.completed"}';
		$secret  = 'my_webhook_secret';
		$sig     = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

		$this->assertTrue( $wh->verify_signature( $payload, $sig, $secret ) );
		$this->assertFalse( $wh->verify_signature( $payload, 'sha256=bad', $secret ) );
	}

	/**
	 * Test dispatch sends to matching subscriptions (via wp_remote_post mock).
	 */
	public function test_dispatch_sends_to_matching_subscriptions() {
		$wh = $this->get_instance();
		$wh->subscribe( 'https://example.com/hook', array( 'workflow.completed' ), 'secret' );
		$wh->subscribe( 'https://other.com/hook', array( 'workflow.failed' ), 'secret2' );

		// Mock wp_remote_post — capture calls.
		$calls = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				$calls[] = $url;
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ) );
			},
			10,
			3
		);

		$count = $wh->dispatch( 'workflow.completed', array( 'run_id' => 1 ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $count );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://example.com/hook', $calls[0] );
	}

	/**
	 * Test dispatch returns 0 when no matching subscriptions.
	 */
	public function test_dispatch_returns_zero_when_no_match() {
		$wh = $this->get_instance();
		$wh->subscribe( 'https://example.com/hook', array( 'workflow.failed' ) );
		$count = $wh->dispatch( 'workflow.completed', array() );
		$this->assertSame( 0, $count );
	}
}
