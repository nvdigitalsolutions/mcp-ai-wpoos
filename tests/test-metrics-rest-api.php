<?php
/**
 * Tests for Advanced Metrics REST API.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for metrics REST API endpoints.
 */
class WP_MCP_AI_Metrics_REST_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user for permissions testing.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Set up some test usage data.
		$this->setup_test_usage_data();

		// Register REST routes.
		WP_MCP_AI_REST_Metrics::register_routes();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_resource_usage_history' );

		parent::tearDown();
	}

	/**
	 * Set up test usage data.
	 */
	private function setup_test_usage_data() {
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();

		// Add some sample usage data.
		for ( $i = 0; $i < 10; $i++ ) {
			$resource_manager->record_usage(
				array(
					'operation_type' => 'chat',
					'assistant_id'   => 1,
					'tokens_used'    => 1000 + ( $i * 100 ),
					'execution_time' => 5 + ( $i * 0.5 ),
					'status'         => ( 0 === $i % 5 ) ? 'error' : 'success',
				)
			);

			// Sleep briefly to ensure different timestamps.
			sleep( 1 );
		}
	}

	/**
	 * Test overview endpoint.
	 */
	public function test_overview_endpoint() {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/overview' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_requests', $data );
		$this->assertArrayHasKey( 'total_tokens', $data );
		$this->assertArrayHasKey( 'avg_response_time', $data );
		$this->assertArrayHasKey( 'success_rate', $data );
		$this->assertArrayHasKey( 'health_status', $data );

		$this->assertGreaterThan( 0, $data['total_requests'] );
	}

	/**
	 * Test trends endpoint.
	 */
	public function test_trends_endpoint() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/trends' );
		$request->set_param( 'period', '24h' );
		$request->set_param( 'metric', 'tokens' );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'period', $data );
		$this->assertArrayHasKey( 'metric', $data );
		$this->assertArrayHasKey( 'data_points', $data );

		$this->assertEquals( '24h', $data['period'] );
		$this->assertEquals( 'tokens', $data['metric'] );
		$this->assertIsArray( $data['data_points'] );
	}

	/**
	 * Test assistants endpoint.
	 */
	public function test_assistants_endpoint() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/assistants' );
		$request->set_param( 'period', '7d' );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'period', $data );
		$this->assertArrayHasKey( 'assistants', $data );

		$this->assertIsArray( $data['assistants'] );
		$this->assertNotEmpty( $data['assistants'] );

		// Verify assistant data structure.
		$first_assistant = $data['assistants'][0];
		$this->assertArrayHasKey( 'assistant_id', $first_assistant );
		$this->assertArrayHasKey( 'requests', $first_assistant );
		$this->assertArrayHasKey( 'tokens', $first_assistant );
		$this->assertArrayHasKey( 'avg_response_time', $first_assistant );
		$this->assertArrayHasKey( 'success_rate', $first_assistant );
	}

	/**
	 * Test cost analysis endpoint.
	 */
	public function test_cost_analysis_endpoint() {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/cost' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_tokens', $data );
		$this->assertArrayHasKey( 'estimated_cost', $data );
		$this->assertArrayHasKey( 'period', $data );
		$this->assertArrayHasKey( 'recommendations', $data );

		$this->assertIsArray( $data['recommendations'] );
		$this->assertNotEmpty( $data['recommendations'] );
	}

	/**
	 * Test export endpoint with JSON format.
	 */
	public function test_export_json_endpoint() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/export' );
		$request->set_param( 'format', 'json' );
		$request->set_param( 'range', '7d' );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'format', $data );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'filename', $data );

		$this->assertEquals( 'json', $data['format'] );
		$this->assertIsArray( $data['data'] );
	}

	/**
	 * Test export endpoint with CSV format.
	 */
	public function test_export_csv_endpoint() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/export' );
		$request->set_param( 'format', 'csv' );
		$request->set_param( 'range', '7d' );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'format', $data );
		$this->assertArrayHasKey( 'data', $data );

		$this->assertEquals( 'csv', $data['format'] );
		$this->assertIsString( $data['data'] );
		$this->assertStringContainsString( 'Timestamp,Operation Type', $data['data'] );
	}

	/**
	 * Test permissions check - should fail for non-admin.
	 */
	public function test_permissions_check_fails_for_non_admin() {
		$subscriber = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $subscriber );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/overview' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test permissions check - should fail for unauthenticated.
	 */
	public function test_permissions_check_fails_for_unauthenticated() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/overview' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test parameter validation for period.
	 */
	public function test_invalid_period_parameter() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/trends' );
		$request->set_param( 'period', 'invalid' );
		$request->set_param( 'metric', 'tokens' );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test parameter validation for metric.
	 */
	public function test_invalid_metric_parameter() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/trends' );
		$request->set_param( 'period', '7d' );
		$request->set_param( 'metric', 'invalid' );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test parameter validation for export format.
	 */
	public function test_invalid_export_format() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/metrics/export' );
		$request->set_param( 'format', 'invalid' );

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}
}
