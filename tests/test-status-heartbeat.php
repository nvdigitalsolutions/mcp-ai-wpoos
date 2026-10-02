<?php
/**
 * Tests for WP_MCP_AI_Status_Heartbeat (fleet monitoring emitter).
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the status heartbeat emitter.
 */
class Test_Status_Heartbeat extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-worker-config.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-status-heartbeat.php';
		delete_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED );
		delete_option( 'wp_mcp_ai_media_worker_url' );
		delete_transient( WP_MCP_AI_Status_Heartbeat::LOCK_KEY );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'pre_http_request' );
		delete_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED );
		delete_option( 'wp_mcp_ai_media_worker_url' );
		delete_transient( WP_MCP_AI_Status_Heartbeat::LOCK_KEY );
	}

	/**
	 * Heartbeat is disabled unless the option is set AND a worker URL exists.
	 */
	public function test_is_enabled_requires_option_and_url() {
		$this->assertFalse( WP_MCP_AI_Status_Heartbeat::is_enabled() );

		update_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED, true );
		$this->assertFalse( WP_MCP_AI_Status_Heartbeat::is_enabled(), 'Option alone must not enable without a URL.' );

		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );
		$this->assertTrue( WP_MCP_AI_Status_Heartbeat::is_enabled() );
	}

	/**
	 * Maybe_send() is a no-op when the feature is disabled.
	 */
	public function test_maybe_send_noop_when_disabled() {
		$this->assertFalse( WP_MCP_AI_Status_Heartbeat::maybe_send() );
	}

	/**
	 * Maybe_send() POSTs the heartbeat when enabled and fires the success action.
	 */
	public function test_maybe_send_posts_heartbeat_when_enabled() {
		update_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED, true );
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$requests ) {
				$requests[] = array(
					'url'    => $url,
					'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
					'args'   => $args,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'ok'          => true,
							'server_time' => time(),
						)
					),
				);
			},
			10,
			3
		);

		$sent = array();
		add_action(
			'wp_mcp_ai_status_heartbeat_sent',
			function ( $payload ) use ( &$sent ) {
				$sent = $payload;
			}
		);

		$this->assertTrue( WP_MCP_AI_Status_Heartbeat::maybe_send() );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://media-worker:3100/api/status/heartbeat', $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertArrayHasKey( 'X-Site-Token', $requests[0]['args']['headers'] );
		$this->assertArrayHasKey( 'X-Site-Url', $requests[0]['args']['headers'] );

		// The success action fired with a payload matching the v1 contract.
		$this->assertSame( 1, $sent['v'] );
		$this->assertArrayHasKey( 'overall', $sent['checks'] );
		$this->assertArrayHasKey( 'wp_version', $sent['meta'] );
	}

	/**
	 * A failing delivery fires the failed action with the WP_Error.
	 */
	public function test_maybe_send_fires_failed_action_on_error() {
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

		$failed = array();
		add_action(
			'wp_mcp_ai_status_heartbeat_failed',
			function ( $error ) use ( &$failed ) {
				$failed = $error;
			}
		);

		$this->assertTrue( WP_MCP_AI_Status_Heartbeat::maybe_send() );
		$this->assertInstanceOf( 'WP_Error', $failed );
		$this->assertSame( 'http_request_failed', $failed->get_error_code() );
	}

	/**
	 * The payload only carries public source components — never private ones.
	 */
	public function test_build_payload_allowlists_public_components() {
		$payload = WP_MCP_AI_Status_Heartbeat::build_payload();

		$this->assertSame( 1, $payload['v'] );
		$this->assertArrayHasKey( 'components', $payload['checks'] );

		// queue_health is a private (non-public) source — it must never be
		// included even if present in the cached snapshot.
		foreach ( $payload['checks']['components'] as $slug => $component ) {
			$this->assertNotSame( 'queue_health', $slug );
			$this->assertArrayHasKey( 'status', $component );
			$this->assertArrayHasKey( 'message', $component );
		}

		// Maintenance field is always present (0 when no window is active).
		$this->assertArrayHasKey( 'maintenance_until', $payload['meta'] );
	}

	/**
	 * The heartbeat lock prevents overlapping sends within the window.
	 */
	public function test_lock_prevents_concurrent_sends() {
		update_option( WP_MCP_AI_Status_Heartbeat::OPTION_ENABLED, true );
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		set_transient( WP_MCP_AI_Status_Heartbeat::LOCK_KEY, 1, 4 * MINUTE_IN_SECONDS );

		$this->assertFalse( WP_MCP_AI_Status_Heartbeat::maybe_send() );
	}
}
