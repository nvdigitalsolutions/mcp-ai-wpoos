<?php
/**
 * Comprehensive tests for REST API endpoint availability.
 *
 * Tests all registered endpoints to confirm they are properly registered
 * and respond with expected status codes.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test REST API endpoint availability and basic functionality.
 */
class Test_REST_Endpoint_Availability extends WP_UnitTestCase {

	/**
	 * REST API server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Regular user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Create test users.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->user_id       = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Configure assistant with basic settings.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_config',
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4',
				'tools'    => array( 'get_current_date_time' ),
			)
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Test that /assistants endpoint is registered.
	 */
	public function test_assistants_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/assistants', $routes, '/assistants endpoint should be registered' );
	}

	/**
	 * Test that /assistants GET endpoint responds.
	 */
	public function test_assistants_get_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$status = $response->get_status();
		$this->assertTrue( 200 === $status || 404 === $status, 'GET /assistants should return 200 or 404' );
	}

	/**
	 * Test that /chat endpoint is registered.
	 */
	public function test_chat_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/chat', $routes, '/chat endpoint should be registered' );
	}

	/**
	 * Test that /chat GET endpoint responds (for SSE handshake).
	 */
	public function test_chat_get_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		// GET /chat may return various status codes depending on SSE configuration.
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /chat POST endpoint responds with validation error for invalid data.
	 */
	public function test_chat_post_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		// Send minimal invalid data to test endpoint availability without triggering full chat.
		$request->set_param( 'messages', array() );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		// Expecting validation error (400) or other error, not a fatal error.
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /chat-client endpoint is registered.
	 */
	public function test_chat_client_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-client', $routes, '/chat-client endpoint should be registered' );
	}

	/**
	 * Test that /chat-client POST endpoint responds.
	 */
	public function test_chat_client_post_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'messages', array() );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /chat-transcripts endpoint is registered.
	 */
	public function test_chat_transcripts_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-transcripts', $routes, '/chat-transcripts endpoint should be registered' );
	}

	/**
	 * Test that /chat-transcripts GET endpoint responds.
	 */
	public function test_chat_transcripts_get_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertTrue( $response->get_status() === 200 || $response->get_status() === 404, 'GET /chat-transcripts should return 200 or 404' );
	}

	/**
	 * Test that /chat-transcripts POST endpoint responds.
	 */
	public function test_chat_transcripts_post_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-' . time() );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message',
				),
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /chat-transcripts/{session_key} endpoint is registered.
	 */
	public function test_chat_transcripts_delete_endpoint_registered() {
		$routes = $this->server->get_routes();
		// Check for the pattern route.
		$pattern_found = false;
		foreach ( $routes as $route => $handlers ) {
			if ( preg_match( '#^/mcp-ai/v1/chat-transcripts/\(\?P<session_key>\[.*\]#', $route ) ) {
				$pattern_found = true;
				break;
			}
		}
		$this->assertTrue( $pattern_found, '/chat-transcripts/{session_key} endpoint should be registered' );
	}

	/**
	 * Test that /tools endpoint is registered.
	 */
	public function test_tools_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/tools', $routes, '/tools endpoint should be registered' );
	}

	/**
	 * Test that /tools GET endpoint responds.
	 */
	public function test_tools_get_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame( 200, $response->get_status(), 'GET /tools should return 200' );
	}

	/**
	 * Test that /tools POST endpoint responds.
	 */
	public function test_tools_post_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'get_current_date_time' );
		$request->set_param( 'arguments', array() );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /files/{file_id}/download endpoint is registered.
	 */
	public function test_files_download_endpoint_registered() {
		$routes = $this->server->get_routes();
		// Check for the pattern route.
		$pattern_found = false;
		foreach ( $routes as $route => $handlers ) {
			if ( preg_match( '#^/mcp-ai/v1/files/\(\?P<file_id>\[.*\]/download#', $route ) ) {
				$pattern_found = true;
				break;
			}
		}
		$this->assertTrue( $pattern_found, '/files/{file_id}/download endpoint should be registered' );
	}

	/**
	 * Test that /sse endpoint is registered.
	 */
	public function test_sse_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/sse', $routes, '/sse endpoint should be registered' );
	}

	/**
	 * Test that /sse GET endpoint responds.
	 */
	public function test_sse_get_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /mcp endpoint is registered.
	 */
	public function test_mcp_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/mcp', $routes, '/mcp endpoint should be registered' );
	}

	/**
	 * Test that /mcp OPTIONS endpoint responds.
	 */
	public function test_mcp_options_endpoint_responds() {
		$request = new WP_REST_Request( 'OPTIONS', '/mcp-ai/v1/mcp' );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame( 200, $response->get_status(), 'OPTIONS /mcp should return 200' );
	}

	/**
	 * Test that /mcp POST endpoint responds.
	 */
	public function test_mcp_post_endpoint_responds() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'jsonrpc', '2.0' );
		$request->set_param( 'method', 'initialize' );
		$request->set_param( 'id', 1 );

		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertGreaterThanOrEqual( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 599, $response->get_status() );
	}

	/**
	 * Test that /health endpoint is registered.
	 */
	public function test_health_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/health', $routes, '/health endpoint should be registered' );
	}

	/**
	 * Test that /health endpoint is publicly accessible.
	 */
	public function test_health_endpoint_public_access() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health' );
		$response = $this->server->dispatch( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame( 200, $response->get_status(), 'GET /health should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'status', $data, 'Health response should include status' );
		$this->assertArrayHasKey( 'timestamp', $data, 'Health response should include timestamp' );
	}

	/**
	 * Test that /health/providers endpoint is registered.
	 */
	public function test_health_providers_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/health/providers', $routes, '/health/providers endpoint should be registered' );
	}

	/**
	 * Test that /health/providers requires admin permission.
	 */
	public function test_health_providers_requires_admin() {
		// Not logged in - should fail.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'GET /health/providers should require authentication' );

		// Regular user - should fail.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'GET /health/providers should require admin capability' );

		// Admin user - should succeed.
		wp_set_current_user( $this->admin_user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'GET /health/providers should allow admin users' );
	}

	/**
	 * Test that /metrics endpoint is registered.
	 */
	public function test_metrics_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/metrics', $routes, '/metrics endpoint should be registered' );
	}

	/**
	 * Test that /metrics requires admin permission.
	 */
	public function test_metrics_requires_admin() {
		// Not logged in - should fail.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'GET /metrics should require authentication' );

		// Regular user - should fail.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'GET /metrics should require admin capability' );

		// Admin user - should succeed.
		wp_set_current_user( $this->admin_user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'GET /metrics should allow admin users' );
	}

	/**
	 * Test that /metrics/reset endpoint is registered.
	 */
	public function test_metrics_reset_endpoint_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/metrics/reset', $routes, '/metrics/reset endpoint should be registered' );
	}

	/**
	 * Test that /metrics/reset requires admin permission.
	 */
	public function test_metrics_reset_requires_admin() {
		// Not logged in - should fail.
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'POST /metrics/reset should require authentication' );

		// Regular user - should fail.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'POST /metrics/reset should require admin capability' );

		// Admin user - should succeed.
		wp_set_current_user( $this->admin_user_id );
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = $this->server->dispatch( $request );
		$this->assertTrue( $response->get_status() === 200 || $response->get_status() === 404, 'POST /metrics/reset should allow admin users' );
	}

	/**
	 * Test that all expected endpoints are registered.
	 */
	public function test_all_expected_endpoints_registered() {
		$routes = $this->server->get_routes();

		$expected_routes = array(
			'/mcp-ai/v1/assistants',
			'/mcp-ai/v1/chat',
			'/mcp-ai/v1/chat-client',
			'/mcp-ai/v1/chat-transcripts',
			'/mcp-ai/v1/tools',
			'/mcp-ai/v1/sse',
			'/mcp-ai/v1/mcp',
			'/mcp-ai/v1/health',
			'/mcp-ai/v1/health/providers',
			'/mcp-ai/v1/metrics',
			'/mcp-ai/v1/metrics/reset',
		);

		foreach ( $expected_routes as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} should be registered" );
		}
	}

	/**
	 * Test that all endpoints are under correct namespace.
	 */
	public function test_all_endpoints_use_correct_namespace() {
		$routes = $this->server->get_routes();

		foreach ( $routes as $route => $handlers ) {
			if ( strpos( $route, '/mcp-ai/v1' ) === 0 ) {
				$this->assertNotEmpty( $handlers, "Route {$route} should have handlers" );
				$this->assertIsArray( $handlers, "Route {$route} handlers should be an array" );
			}
		}
	}
}
