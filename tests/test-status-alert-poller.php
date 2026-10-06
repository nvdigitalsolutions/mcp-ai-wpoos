<?php
/**
 * Tests for WP_MCP_AI_Status_Alert_Poller (pull-diff fleet alerting).
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the status alert poller.
 */
class Test_Status_Alert_Poller extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-worker-config.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-status-heartbeat.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-status-alert-poller.php';
		delete_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED );
		delete_option( 'wp_mcp_ai_media_worker_url' );
		delete_option( WP_MCP_AI_Status_Alert_Poller::OPTION_SNAPSHOT );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'pre_http_request' );
		delete_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED );
		delete_option( 'wp_mcp_ai_media_worker_url' );
		delete_option( WP_MCP_AI_Status_Alert_Poller::OPTION_SNAPSHOT );
	}

	/**
	 * The poller is a no-op when the heartbeat feature is disabled.
	 */
	public function test_poll_noop_when_disabled() {
		$this->assertFalse( WP_MCP_AI_Status_Alert_Poller::maybe_poll() );
	}

	/**
	 * First poll establishes the snapshot. Sites already in an alert-worthy
	 * state at enrollment DO fire once (the operator learns immediately);
	 * unchanged subsequent polls fire nothing.
	 */
	public function test_first_poll_establishes_snapshot() {
		update_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED, true );
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$summary = array(
			'overall_status' => 'major_outage',
			'sites'          => array(
				array(
					'slug'   => 'site-b',
					'status' => 'major_outage',
				),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $summary ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $summary ),
				);
			},
			10,
			3
		);

		$events = array();
		add_action(
			'wp_mcp_ai_site_status_event',
			function ( $slug, $event, $data ) use ( &$events ) {
				unset( $data );
				$events[] = array( $slug, $event );
			},
			10,
			3
		);

		$this->assertTrue( WP_MCP_AI_Status_Alert_Poller::maybe_poll() );
		$this->assertSame( array( array( 'site-b', 'site.down' ) ), $events, 'A site already down at enrollment alerts once.' );

		// Unchanged state: no new events.
		WP_MCP_AI_Status_Alert_Poller::maybe_poll();
		$this->assertCount( 1, $events, 'Unchanged polls must not re-fire transition events.' );

		$snapshot = get_option( WP_MCP_AI_Status_Alert_Poller::OPTION_SNAPSHOT, array() );
		$this->assertSame( 'major_outage', $snapshot['site-b'] );
	}

	/**
	 * Status transitions dispatch the expected events exactly once.
	 */
	public function test_transitions_dispatch_events_once() {
		update_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED, true );
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$state = array(
			'overall_status' => 'operational',
			'sites'          => array(
				array(
					'slug'   => 'site-a',
					'status' => 'operational',
				),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( &$state ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $state ),
				);
			},
			10,
			3
		);

		$events = array();
		add_action(
			'wp_mcp_ai_site_status_event',
			function ( $slug, $event, $data ) use ( &$events ) {
				unset( $data );
				$events[] = array( $slug, $event );
			},
			10,
			3
		);

		// Baseline.
		WP_MCP_AI_Status_Alert_Poller::maybe_poll();
		$this->assertSame( array(), $events );

		// Down transition.
		$state['sites'][0]['status'] = 'major_outage';
		$state['overall_status']     = 'major_outage';
		WP_MCP_AI_Status_Alert_Poller::maybe_poll();
		$this->assertSame( array( array( 'site-a', 'site.down' ) ), $events );

		// No-change poll fires nothing new.
		WP_MCP_AI_Status_Alert_Poller::maybe_poll();
		$this->assertCount( 1, $events );

		// Recovery transition.
		$state['sites'][0]['status'] = 'operational';
		$state['overall_status']     = 'operational';
		WP_MCP_AI_Status_Alert_Poller::maybe_poll();
		$this->assertCount( 2, $events );
		$this->assertSame( array( 'site-a', 'site.recovered' ), $events[1] );
	}

	/**
	 * Worker failures are swallowed silently (retried on the next tick).
	 */
	public function test_poll_swallows_worker_failures() {
		update_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED, true );
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection refused' );
			},
			10,
			3
		);

		$this->assertFalse( WP_MCP_AI_Status_Alert_Poller::maybe_poll() );
	}
}
