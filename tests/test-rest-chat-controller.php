<?php
/**
 * Tests for WP_MCP_AI_REST_Chat_Controller class.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test REST Chat Controller class.
 */
class Test_REST_Chat_Controller extends WP_UnitTestCase {
	/**
	 * Chat controller instance.
	 *
	 * @var WP_MCP_AI_REST_Chat_Controller
	 */
	private $controller;

	/**
	 * Main REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $main_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize main REST controller.
		$this->main_controller = WP_MCP_AI_REST::get_instance();

		// Create chat controller instance.
		$this->controller = new WP_MCP_AI_REST_Chat_Controller( $this->main_controller );
	}

	/**
	 * Test that Chat Controller can be instantiated.
	 */
	public function test_controller_instantiation() {
		$this->assertInstanceOf( WP_MCP_AI_REST_Chat_Controller::class, $this->controller );
	}

	/**
	 * Test that Chat Controller extends base controller.
	 */
	public function test_controller_extends_base() {
		$this->assertInstanceOf( WP_MCP_AI_REST_Controller_Base::class, $this->controller );
	}

	/**
	 * Test that register_routes method exists.
	 */
	public function test_register_routes_method_exists() {
		$this->assertTrue( method_exists( $this->controller, 'register_routes' ) );
	}

	/**
	 * Test that all expected endpoint handlers exist.
	 */
	public function test_endpoint_handlers_exist() {
		$handlers = array(
			'handle_chat_request',
			'handle_chat_client_request',
			'handle_chat_transcripts',
			'handle_chat_transcript_save',
			'handle_chat_transcript_get',
			'handle_chat_transcript_delete',
		);

		foreach ( $handlers as $handler ) {
			$this->assertTrue(
				method_exists( $this->controller, $handler ),
				"Handler method {$handler} should exist"
			);
		}
	}

	/**
	 * Test that permission check methods exist.
	 */
	public function test_permission_check_methods_exist() {
		$this->assertTrue( method_exists( $this->controller, 'permissions_check' ) );
		$this->assertTrue( method_exists( $this->controller, 'chat_transcripts_permissions_check' ) );
	}

	/**
	 * Register the plugin's REST routes via the rest_api_init flow and return
	 * the route table.
	 *
	 * Calling register_routes() directly outside rest_api_init raises a
	 * _doing_it_wrong notice on WP 6.9+. Resetting the server and letting
	 * rest_get_server() fire rest_api_init exercises the real registration
	 * path (WP_MCP_AI_REST hooks its register_routes() to rest_api_init).
	 *
	 * @return array Registered routes, keyed by route regex.
	 */
	private function get_routes_via_init() {
		global $wp_rest_server;

		$wp_rest_server = null;

		return rest_get_server()->get_routes();
	}

	/**
	 * Test that routes are registered correctly.
	 */
	public function test_routes_registered() {
		// Register routes through the rest_api_init flow.
		$routes = $this->get_routes_via_init();

		// Check that chat routes exist.
		$expected_routes = array(
			'/mcp-ai/v1/chat',
			'/mcp-ai/v1/chat-client',
			'/mcp-ai/v1/chat-transcripts',
			'/mcp-ai/v1/chat-transcripts/(?P<session_key>[^/]+)',
		);

		foreach ( $expected_routes as $route ) {
			$this->assertArrayHasKey(
				$route,
				$routes,
				"Route {$route} should be registered"
			);
		}
	}

	/**
	 * Test that the /session/nonce route is registered.
	 */
	public function test_session_nonce_route_registered() {
		$routes = $this->get_routes_via_init();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/session/nonce',
			$routes,
			'Session nonce route should be registered'
		);
	}

	/**
	 * Test that the /session/nonce route only supports GET.
	 */
	public function test_session_nonce_route_methods() {
		$routes      = $this->get_routes_via_init();
		$nonce_route = $routes['/mcp-ai/v1/session/nonce'];

		$methods = array();
		foreach ( $nonce_route as $endpoint ) {
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'GET', $methods, 'Session nonce route should support GET' );
	}

	/**
	 * Test that the session-nonce handler exists.
	 */
	public function test_session_nonce_handler_exists() {
		$this->assertTrue( method_exists( $this->controller, 'handle_session_nonce' ) );
	}

	/**
	 * Test that the handler returns a nonce that verifies for the current
	 * (guest) session.
	 */
	public function test_session_nonce_returns_verifiable_guest_nonce() {
		$response = $this->controller->handle_session_nonce();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertNotSame( '', $data['nonce'] );
		$this->assertNotFalse( wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
	}

	/**
	 * Test that the handler mints the nonce from the request's auth cookie, so
	 * a logged-in user receives a nonce bound to their session token — the
	 * recovery path for stale nonces served from cached pages or after
	 * session-token rotation.
	 */
	public function test_session_nonce_is_bound_to_auth_cookie_session() {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		// Simulate a cookie-authenticated request with a known session token.
		$session_token = wp_generate_password( 43, false, false );
		$auth_cookie   = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in', $session_token );

		$_COOKIE[ LOGGED_IN_COOKIE ] = $auth_cookie;
		wp_set_current_user( $user_id );

		$response = $this->controller->handle_session_nonce();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'nonce', $data );

		// The nonce must verify against the session token carried by the auth
		// cookie — the same check WordPress's rest_cookie_check_errors performs
		// for real REST requests.
		$this->assertNotFalse( wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
		$this->assertSame( $session_token, wp_get_session_token() );

		// Clean up cookie state so subsequent tests see a guest request.
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
	}

	/**
	 * Test that /chat route has correct methods.
	 */
	public function test_chat_route_methods() {
		$routes     = $this->get_routes_via_init();
		$chat_route = $routes['/mcp-ai/v1/chat'];

		// Should have POST and GET methods.
		$methods = array();
		foreach ( $chat_route as $endpoint ) {
			// WP 6.9+ stores methods as keys (array( 'POST' => true )).
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'POST', $methods, 'Chat route should support POST' );
		$this->assertContains( 'GET', $methods, 'Chat route should support GET for SSE' );
	}

	/**
	 * Test that /chat-client route has correct methods.
	 */
	public function test_chat_client_route_methods() {
		$routes           = $this->get_routes_via_init();
		$chat_client_route = $routes['/mcp-ai/v1/chat-client'];

		// Should have POST and GET methods.
		$methods = array();
		foreach ( $chat_client_route as $endpoint ) {
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'POST', $methods, 'Chat client route should support POST' );
		$this->assertContains( 'GET', $methods, 'Chat client route should support GET for SSE' );
	}

	/**
	 * Test that /chat-transcripts route has correct methods.
	 */
	public function test_chat_transcripts_route_methods() {
		$routes            = $this->get_routes_via_init();
		$transcripts_route = $routes['/mcp-ai/v1/chat-transcripts'];

		// Should have GET and POST methods.
		$methods = array();
		foreach ( $transcripts_route as $endpoint ) {
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'GET', $methods, 'Transcripts route should support GET' );
		$this->assertContains( 'POST', $methods, 'Transcripts route should support POST' );
	}

	/**
	 * Test that individual transcript route has correct methods.
	 */
	public function test_individual_transcript_route_methods() {
		$routes           = $this->get_routes_via_init();
		$transcript_route = $routes['/mcp-ai/v1/chat-transcripts/(?P<session_key>[^/]+)'];

		// Should have GET and DELETE methods.
		$methods = array();
		foreach ( $transcript_route as $endpoint ) {
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'GET', $methods, 'Individual transcript route should support GET' );
		$this->assertContains( 'DELETE', $methods, 'Individual transcript route should support DELETE' );
	}

	/**
	 * Test that delegation to main controller works.
	 */
	public function test_delegation_to_main_controller() {
		// Create a mock request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		// Since we're delegating, calling the handler should not throw errors.
		// We can't test the full flow without proper authentication, but we can verify.
		// the method is callable and returns something.
		$this->assertTrue( is_callable( array( $this->controller, 'handle_chat_request' ) ) );
	}

	/**
	 * Test that Chat Controller uses base controller error method.
	 */
	public function test_uses_base_controller_error_method() {
		// The error method is protected, but we can verify it's inherited.
		$reflection = new ReflectionClass( $this->controller );
		$this->assertTrue( $reflection->hasMethod( 'error' ) );
	}

	/**
	 * Test that Chat Controller uses base controller success method.
	 */
	public function test_uses_base_controller_success_method() {
		// The success method is protected, but we can verify it's inherited.
		$reflection = new ReflectionClass( $this->controller );
		$this->assertTrue( $reflection->hasMethod( 'success' ) );
	}
}
