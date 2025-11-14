<?php
/**
 * Tests for Voice Conversation REST Controller
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for voice conversation REST controller.
 */
class Test_Voice_Conversation_Controller extends WP_UnitTestCase {

	/**
	 * Voice conversation controller instance.
	 *
	 * @var WP_MCP_AI_REST_Voice_Conversation_Controller
	 */
	protected $controller;

	/**
	 * Tool registry mock.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-controller-base.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-voice-conversation-controller.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';

		// Initialize tool registry.
		$this->tool_registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Create controller instance.
		$this->controller = new WP_MCP_AI_REST_Voice_Conversation_Controller(
			$this->tool_registry,
			null, // assistant service
			null  // chat service
		);
	}

	/**
	 * Test that the controller class exists.
	 */
	public function test_controller_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_REST_Voice_Conversation_Controller' ),
			'Voice conversation controller class should exist'
		);
	}

	/**
	 * Test that controller has register_routes method.
	 */
	public function test_controller_has_register_routes_method() {
		$this->assertTrue(
			method_exists( $this->controller, 'register_routes' ),
			'Controller should have register_routes method'
		);
	}

	/**
	 * Test that controller has check_permission method.
	 */
	public function test_controller_has_check_permission_method() {
		$this->assertTrue(
			method_exists( $this->controller, 'check_permission' ),
			'Controller should have check_permission method'
		);
	}

	/**
	 * Test that controller has handle_voice_conversation method.
	 */
	public function test_controller_has_handle_voice_conversation_method() {
		$this->assertTrue(
			method_exists( $this->controller, 'handle_voice_conversation' ),
			'Controller should have handle_voice_conversation method'
		);
	}

	/**
	 * Test permission check denies unauthenticated requests when guests not allowed.
	 */
	public function test_permission_check_denies_unauthenticated_without_guest_access() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/voice-conversation' );
		$request->set_param( 'allow_guests', '0' );

		$result = $this->controller->check_permission( $request );

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Permission check should return WP_Error for unauthenticated users without guest access'
		);
	}

	/**
	 * Test permission check allows unauthenticated requests when guests are allowed.
	 */
	public function test_permission_check_allows_unauthenticated_with_guest_access() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/voice-conversation' );
		$request->set_param( 'allow_guests', '1' );

		$result = $this->controller->check_permission( $request );

		$this->assertTrue(
			$result,
			'Permission check should allow unauthenticated users with guest access enabled'
		);
	}

	/**
	 * Test permission check allows authenticated users.
	 */
	public function test_permission_check_allows_authenticated_users() {
		// Create and set current user.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/voice-conversation' );
		$request->set_param( 'allow_guests', '0' );

		$result = $this->controller->check_permission( $request );

		$this->assertTrue(
			$result,
			'Permission check should allow authenticated users'
		);

		// Clean up.
		wp_set_current_user( 0 );
	}

	/**
	 * Test route registration adds the voice-conversation endpoint.
	 */
	public function test_route_registration() {
		// Register routes.
		$this->controller->register_routes();

		// Get REST server instance.
		$server = rest_get_server();
		$routes = $server->get_routes();

		// Check if our route exists.
		$this->assertArrayHasKey(
			'/mcp-ai/v1/voice-conversation',
			$routes,
			'Voice conversation route should be registered'
		);

		// Check route configuration.
		$route = $routes['/mcp-ai/v1/voice-conversation'];
		$this->assertIsArray( $route, 'Route should be an array' );

		// Find POST endpoint.
		$post_endpoint = null;
		foreach ( $route as $endpoint ) {
			if ( in_array( 'POST', $endpoint['methods'], true ) ) {
				$post_endpoint = $endpoint;
				break;
			}
		}

		$this->assertNotNull( $post_endpoint, 'Route should have POST endpoint' );
		$this->assertArrayHasKey( 'callback', $post_endpoint, 'Endpoint should have callback' );
		$this->assertArrayHasKey( 'permission_callback', $post_endpoint, 'Endpoint should have permission callback' );
	}

	/**
	 * Test that widget class exists.
	 */
	public function test_widget_class_exists() {
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-voice-conversation-button-widget.php';

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Elementor_Voice_Conversation_Button_Widget' ),
			'Voice conversation button widget class should exist'
		);
	}

	/**
	 * Test that assets manager class exists.
	 */
	public function test_assets_manager_exists() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-voice-conversation-assets.php';

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Voice_Conversation_Assets' ),
			'Voice conversation assets manager class should exist'
		);
	}
}
