<?php
/**
 * Tests for Health and Metrics REST endpoints.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Health and Metrics REST API endpoints.
 */
class Test_Health_Metrics_Endpoints extends WP_UnitTestCase {

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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create test users.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->user_id       = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		// Reset metrics.
		if ( class_exists( 'WP_MCP_AI_Metrics' ) ) {
			WP_MCP_AI_Metrics::reset();
		}

		parent::tearDown();
	}

	/**
	 * Test health check endpoint is publicly accessible.
	 */
	public function test_health_check_public_access() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 'healthy', $data['status'] );
		$this->assertArrayHasKey( 'timestamp', $data );
		$this->assertArrayHasKey( 'version', $data );
	}

	/**
	 * Test providers health endpoint requires admin.
	 */
	public function test_providers_health_requires_admin() {
		// Not logged in.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		// Regular user.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		// Admin user.
		wp_set_current_user( $this->admin_user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test providers health endpoint returns data.
	 */
	public function test_providers_health_returns_data() {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/health/providers' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'timestamp', $data );
		$this->assertArrayHasKey( 'providers', $data );

		$providers = $data['providers'];
		$this->assertArrayHasKey( 'openai', $providers );
		$this->assertArrayHasKey( 'gemini', $providers );
		$this->assertArrayHasKey( 'anthropic', $providers );

		// Check provider health structure.
		$openai_health = $providers['openai'];
		$this->assertArrayHasKey( 'provider', $openai_health );
		$this->assertArrayHasKey( 'state', $openai_health );
		$this->assertArrayHasKey( 'failure_count', $openai_health );
		$this->assertArrayHasKey( 'is_available', $openai_health );
	}

	/**
	 * Test metrics endpoint requires admin.
	 */
	public function test_metrics_requires_admin() {
		// Not logged in.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		// Regular user.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		// Admin user.
		wp_set_current_user( $this->admin_user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test metrics endpoint returns data.
	 */
	public function test_metrics_returns_data() {
		wp_set_current_user( $this->admin_user_id );

		// Add some metrics.
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total', 5 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total', 2 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'timestamp', $data );
		$this->assertArrayHasKey( 'metrics', $data );

		$metrics = $data['metrics'];
		$this->assertArrayHasKey( 'api_calls', $metrics );
		$this->assertArrayHasKey( 'failures', $metrics );
		$this->assertArrayHasKey( 'timeouts', $metrics );
		$this->assertArrayHasKey( 'retries', $metrics );
		$this->assertArrayHasKey( 'circuit_breaker', $metrics );
	}

	/**
	 * Test metrics reset endpoint requires admin.
	 */
	public function test_metrics_reset_requires_admin() {
		// Not logged in.
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		// Regular user.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		// Admin user.
		wp_set_current_user( $this->admin_user_id );
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test metrics reset all.
	 */
	public function test_metrics_reset_all() {
		wp_set_current_user( $this->admin_user_id );

		// Add metrics.
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total', 5 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total', 2 );

		// Reset all.
		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertStringContainsString( 'All metrics reset', $data['message'] );

		// Verify metrics are reset.
		$value1 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total' );
		$value2 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total' );

		$this->assertSame( 0, $value1 );
		$this->assertSame( 0, $value2 );
	}

	/**
	 * Test metrics reset specific category.
	 */
	public function test_metrics_reset_specific_category() {
		wp_set_current_user( $this->admin_user_id );

		// Add metrics.
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total', 5 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total', 2 );

		// Reset only failures category.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/metrics/reset' );
		$request->set_param( 'category', WP_MCP_AI_Metrics::CATEGORY_FAILURES );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertStringContainsString( 'failures', $data['message'] );

		// API calls should still exist.
		$value1 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total' );
		$this->assertSame( 5, $value1 );

		// Failures should be reset.
		$value2 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total' );
		$this->assertSame( 0, $value2 );
	}
}
