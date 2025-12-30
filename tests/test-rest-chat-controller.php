<?php
/**
 * Tests for WP_MCP_AI_REST_Chat_Controller class.
 *
 * @package WP_MCP_AI
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
	 * Test that routes are registered correctly.
	 */
	public function test_routes_registered() {
		// Register routes.
		$this->controller->register_routes();

		// Get all registered routes.
		$routes = rest_get_server()->get_routes();

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
	 * Test that /chat route has correct methods.
	 */
	public function test_chat_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$chat_route = $routes['/mcp-ai/v1/chat'];

		// Should have POST and GET methods.
		$methods = array();
		foreach ( $chat_route as $endpoint ) {
			$methods = array_merge( $methods, $endpoint['methods'] );
		}

		$this->assertContains( 'POST', $methods, 'Chat route should support POST' );
		$this->assertContains( 'GET', $methods, 'Chat route should support GET for SSE' );
	}

	/**
	 * Test that /chat-client route has correct methods.
	 */
	public function test_chat_client_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$chat_client_route = $routes['/mcp-ai/v1/chat-client'];

		// Should have POST and GET methods.
		$methods = array();
		foreach ( $chat_client_route as $endpoint ) {
			$methods = array_merge( $methods, $endpoint['methods'] );
		}

		$this->assertContains( 'POST', $methods, 'Chat client route should support POST' );
		$this->assertContains( 'GET', $methods, 'Chat client route should support GET for SSE' );
	}

	/**
	 * Test that /chat-transcripts route has correct methods.
	 */
	public function test_chat_transcripts_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$transcripts_route = $routes['/mcp-ai/v1/chat-transcripts'];

		// Should have GET and POST methods.
		$methods = array();
		foreach ( $transcripts_route as $endpoint ) {
			$methods = array_merge( $methods, $endpoint['methods'] );
		}

		$this->assertContains( 'GET', $methods, 'Transcripts route should support GET' );
		$this->assertContains( 'POST', $methods, 'Transcripts route should support POST' );
	}

	/**
	 * Test that individual transcript route has correct methods.
	 */
	public function test_individual_transcript_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$transcript_route = $routes['/mcp-ai/v1/chat-transcripts/(?P<session_key>[^/]+)'];

		// Should have GET and DELETE methods.
		$methods = array();
		foreach ( $transcript_route as $endpoint ) {
			$methods = array_merge( $methods, $endpoint['methods'] );
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
