<?php
/**
 * Tests for WP_MCP_AI_REST_Tools_Controller class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test REST Tools Controller class.
 */
class Test_REST_Tools_Controller extends WP_UnitTestCase {
	/**
	 * Tools controller instance.
	 *
	 * @var WP_MCP_AI_REST_Tools_Controller
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

		// Create tools controller instance.
		$this->controller = new WP_MCP_AI_REST_Tools_Controller( $this->main_controller );
	}

	/**
	 * Test that Tools Controller can be instantiated.
	 */
	public function test_controller_instantiation() {
		$this->assertInstanceOf( WP_MCP_AI_REST_Tools_Controller::class, $this->controller );
	}

	/**
	 * Test that Tools Controller extends base controller.
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
			'handle_tools_list',
			'handle_tool_request',
			'handle_file_download',
			'handle_cron_status_request',
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
		$this->assertTrue( method_exists( $this->controller, 'download_file_permissions_check' ) );
		$this->assertTrue( method_exists( $this->controller, 'permissions_check_cron_status' ) );
	}

	/**
	 * Test that routes are registered correctly.
	 */
	public function test_routes_registered() {
		// Register routes.
		$this->controller->register_routes();

		// Get all registered routes.
		$routes = rest_get_server()->get_routes();

		// Check that tools routes exist.
		$expected_routes = array(
			'/mcp-ai/v1/tools',
			'/mcp-ai/v1/files/(?P<file_id>[^/]+)/download',
			'/mcp-ai/v1/cron-status',
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
	 * Test that /tools route has correct HTTP methods.
	 */
	public function test_tools_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/tools', $routes );

		$route = $routes['/mcp-ai/v1/tools'];

		// Check that GET and POST methods are supported.
		$methods     = wp_list_pluck( $route, 'methods' );
		$all_methods = array();

		foreach ( $methods as $method_array ) {
			if ( is_array( $method_array ) ) {
				$all_methods = array_merge( $all_methods, array_keys( $method_array ) );
			}
		}

		$this->assertContains( 'GET', $all_methods, 'GET method should be supported' );
		$this->assertContains( 'POST', $all_methods, 'POST method should be supported' );
	}

	/**
	 * Test that /files/{file_id}/download route has correct HTTP methods.
	 */
	public function test_file_download_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$route_pattern = '/mcp-ai/v1/files/(?P<file_id>[^/]+)/download';
		$this->assertArrayHasKey( $route_pattern, $routes );

		$route = $routes[ $route_pattern ];

		// Check that GET method is supported.
		$methods     = wp_list_pluck( $route, 'methods' );
		$all_methods = array();

		foreach ( $methods as $method_array ) {
			if ( is_array( $method_array ) ) {
				$all_methods = array_merge( $all_methods, array_keys( $method_array ) );
			}
		}

		$this->assertContains( 'GET', $all_methods, 'GET method should be supported' );
	}

	/**
	 * Test that /cron-status route has correct HTTP methods.
	 */
	public function test_cron_status_route_methods() {
		$this->controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/cron-status', $routes );

		$route = $routes['/mcp-ai/v1/cron-status'];

		// Check that GET method is supported.
		$methods     = wp_list_pluck( $route, 'methods' );
		$all_methods = array();

		foreach ( $methods as $method_array ) {
			if ( is_array( $method_array ) ) {
				$all_methods = array_merge( $all_methods, array_keys( $method_array ) );
			}
		}

		$this->assertContains( 'GET', $all_methods, 'GET method should be supported' );
	}

	/**
	 * Test that handlers delegate to main controller when available.
	 */
	public function test_handlers_delegate_to_main_controller() {
		// Create mock request.
		$request = new WP_REST_Request();

		// Test that each handler returns a response (not "not implemented" error).
		// This confirms delegation is working.
		$handlers_to_test = array(
			'handle_tools_list',
			'handle_tool_request',
			'handle_file_download',
			'handle_cron_status_request',
		);

		foreach ( $handlers_to_test as $handler ) {
			if ( method_exists( $this->main_controller, $handler ) ) {
				$result = $this->controller->$handler( $request );

				// Should not get "not_implemented" error when main controller has the handler.
				if ( is_wp_error( $result ) ) {
					$this->assertNotEquals(
						'not_implemented',
						$result->get_error_code(),
						"Handler {$handler} should delegate to main controller"
					);
				}
			}
		}
	}

	/**
	 * Test that controller works without main controller (fallback behavior).
	 */
	public function test_controller_without_main_controller() {
		$standalone_controller = new WP_MCP_AI_REST_Tools_Controller();
		$this->assertInstanceOf( WP_MCP_AI_REST_Tools_Controller::class, $standalone_controller );

		// Routes should still register.
		$standalone_controller->register_routes();
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/tools', $routes );
	}
}
