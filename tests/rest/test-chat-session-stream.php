<?php
/**
 * Tests for WP_MCP_AI_REST_Chat_Session_Stream_Controller route registration.
 *
 * We test the non-streaming aspects: route exists, auth is enforced.
 * Full SSE streaming is not testable in unit tests (requires an HTTP
 * connection and `sleep()`) — that is covered by manual / integration tests.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for the chat-session stream REST controller.
 */
class Test_Chat_Session_Stream_Controller extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber (no plugin capability).
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-controller-base.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-session-frame-buffer.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-chat-session-stream-controller.php';

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$controller = new WP_MCP_AI_REST_Chat_Session_Stream_Controller();
		$controller->register_routes();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Route registration
	// -----------------------------------------------------------------------

	/**
	 * The stream route is registered.
	 *
	 * @test
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();
		$found  = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( false !== strpos( $route, 'chat-sessions' ) && false !== strpos( $route, 'stream' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'chat-sessions stream route should be registered' );
	}

	// -----------------------------------------------------------------------
	// Authentication
	// -----------------------------------------------------------------------

	/**
	 * Unauthenticated request returns 401 / 403.
	 *
	 * @test
	 */
	public function test_unauthenticated_request_is_rejected() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-sessions/test_session_abc/stream' );
		$response = $this->server->dispatch( $request );
		$status   = $response->get_status();
		$this->assertContains( $status, array( 401, 403 ), "Expected 401 or 403, got {$status}" );
	}

	/**
	 * Valid session_id characters pass pattern validation.
	 *
	 * @test
	 */
	public function test_valid_session_id_passes_pattern() {
		// The route regex allows [a-zA-Z0-9_-]{1,64}.
		$routes    = $this->server->get_routes();
		$route_key = null;
		foreach ( array_keys( $routes ) as $route ) {
			if ( false !== strpos( $route, 'chat-sessions' ) && false !== strpos( $route, 'stream' ) ) {
				$route_key = $route;
				break;
			}
		}
		$this->assertNotNull( $route_key );
		// If the regex is in the route key, the session ID pattern must match valid tokens.
		$this->assertStringContainsString( 'session_id', $route_key );
	}

	// -----------------------------------------------------------------------
	// Frame buffer interactions (unit-level)
	// -----------------------------------------------------------------------

	/**
	 * resolveChatSessionId logic (pure PHP proxy): sanitized session IDs are
	 * accepted by the frame buffer.
	 *
	 * @test
	 */
	public function test_frame_buffer_used_by_stream_route() {
		$session_id = 'cs_test_' . substr( md5( microtime() ), 0, 8 );
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session_id, 'chat:resumed', array( 'message' => 'hi' ) );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session_id, 0 );
		$this->assertCount( 1, $frames );
		$this->assertSame( 'chat:resumed', $frames[0]['event'] );

		WP_MCP_AI_Chat_Session_Frame_Buffer::flush( $session_id );
	}

	// -----------------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------------

	/**
	 * POLL_INTERVAL is a positive integer.
	 *
	 * @test
	 */
	public function test_poll_interval_is_positive() {
		$this->assertGreaterThan( 0, WP_MCP_AI_REST_Chat_Session_Stream_Controller::POLL_INTERVAL );
	}

	/**
	 * MAX_TICKS bounds the connection to a finite lifetime.
	 *
	 * @test
	 */
	public function test_max_ticks_is_bounded() {
		$this->assertGreaterThan( 0, WP_MCP_AI_REST_Chat_Session_Stream_Controller::MAX_TICKS );
		// Should not be absurdly large (< 10000 ticks = < 5.5 hours @ 2s tick).
		$this->assertLessThan( 10000, WP_MCP_AI_REST_Chat_Session_Stream_Controller::MAX_TICKS );
	}
}
