<?php
/**
 * Tests for Analytics REST API endpoints.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_REST_Analytics_Manager.
 */
class Test_REST_Analytics_Endpoints extends WP_UnitTestCase {

	/**
	 * Test admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Test user IDs.
	 *
	 * @var array
	 */
	private $test_users = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create test users.
		$this->test_users[] = $this->factory->user->create();
		$this->test_users[] = $this->factory->user->create();

		// Ensure classes are loaded.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-analytics-engine.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-token-limits.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-analytics-manager.php';

		// Register REST routes.
		WP_MCP_AI_REST_Analytics_Manager::register_routes();

		// Set up REST server.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		// Clean up test users.
		wp_delete_user( $this->admin_id );
		foreach ( $this->test_users as $user_id ) {
			wp_delete_user( $user_id );
		}

		parent::tearDown();
	}

	/**
	 * Test trends endpoint without authentication.
	 */
	public function test_trends_endpoint_requires_auth() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/trends/' . $this->test_users[0] );

		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test trends endpoint with authentication.
	 */
	public function test_trends_endpoint_with_auth() {
		$user_id = $this->test_users[0];

		// Create mock usage data.
		$usage = array(
			'test_tool' => array(
				'daily'  => array(
					'2025-01-01' => 100,
					'2025-01-02' => 150,
					'2025-01-03' => 200,
				),
				'hourly' => array(
					'2025-01-01-09' => 50,
					'2025-01-01-10' => 50,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/trends/' . $user_id );
		$request->set_param( 'days', 30 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( $user_id, $data['user_id'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'daily_usage', $data['data'] );
		$this->assertArrayHasKey( 'trend', $data['data'] );
		$this->assertArrayHasKey( 'statistics', $data['data'] );
		$this->assertArrayHasKey( 'patterns', $data['data'] );
	}

	/**
	 * Test patterns endpoint.
	 */
	public function test_patterns_endpoint() {
		$user_id = $this->test_users[0];

		// Create mock usage data.
		$usage = array(
			'test_tool' => array(
				'hourly' => array(
					'2025-01-01-09' => 100,
					'2025-01-01-10' => 200,
					'2025-01-01-11' => 150,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/patterns/' . $user_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'patterns', $data );
		$this->assertArrayHasKey( 'peak_hours', $data['patterns'] );
		$this->assertArrayHasKey( 'usage_type', $data['patterns'] );
	}

	/**
	 * Test compare users endpoint.
	 */
	public function test_compare_users_endpoint() {
		$user_1 = $this->test_users[0];
		$user_2 = $this->test_users[1];

		// Create usage data.
		$usage_1 = array(
			'test_tool' => array(
				'daily' => array(
					'2025-01-01' => 500,
					'2025-01-02' => 600,
				),
			),
		);

		$usage_2 = array(
			'test_tool' => array(
				'daily' => array(
					'2025-01-01' => 100,
					'2025-01-02' => 150,
				),
			),
		);

		update_user_meta( $user_1, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_1 );
		update_user_meta( $user_2, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_2 );

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/compare' );
		$request->set_param( 'user_ids', $user_1 . ',' . $user_2 );
		$request->set_param( 'days', 30 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'comparison', $data );
		$this->assertArrayHasKey( 'higher_user', $data['comparison'] );
		$this->assertEquals( $user_1, $data['comparison']['higher_user'] );
	}

	/**
	 * Test compare users with invalid user count.
	 */
	public function test_compare_users_invalid_count() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/compare' );
		$request->set_param( 'user_ids', $this->test_users[0] . ',' . $this->test_users[1] . ',999' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test anomalies endpoint for single user.
	 */
	public function test_anomalies_endpoint_single_user() {
		$user_id = $this->test_users[0];

		// Create usage data with anomaly.
		$usage = array(
			'test_tool' => array(
				'daily' => array(
					'2025-01-01' => 100,
					'2025-01-02' => 105,
					'2025-01-03' => 98,
					'2025-01-04' => 102,
					'2025-01-05' => 500, // Anomaly!
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/anomalies' );
		$request->set_param( 'user_id', $user_id );
		$request->set_param( 'threshold', 3.0 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'anomalies', $data );
		$this->assertNotEmpty( $data['anomalies'] );
	}

	/**
	 * Test anomalies endpoint with severity filter.
	 */
	public function test_anomalies_endpoint_severity_filter() {
		$user_id = $this->test_users[0];

		// Create usage data.
		$usage = array(
			'test_tool' => array(
				'daily' => array_fill_keys(
					array(
						'2025-01-01',
						'2025-01-02',
						'2025-01-03',
						'2025-01-04',
						'2025-01-05',
						'2025-01-06',
						'2025-01-07',
						'2025-01-08',
						'2025-01-09',
						'2025-01-10',
					),
					100
				),
			),
		);

		// Add high severity anomaly.
		$usage['test_tool']['daily']['2025-01-11'] = 1000;

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/anomalies' );
		$request->set_param( 'user_id', $user_id );
		$request->set_param( 'severity', 'high' );
		$request->set_param( 'threshold', 3.0 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test invalid user ID validation.
	 */
	public function test_invalid_user_id() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/trends/999999' );
		$response = rest_do_request( $request );

		// Should fail validation.
		$this->assertNotEquals( 200, $response->get_status() );
	}

	/**
	 * Test compare tools endpoint.
	 */
	public function test_compare_tools_endpoint() {
		// Create usage data for different tools.
		$usage = array(
			'tool_a' => array(
				'daily' => array(
					'2025-01-01' => 500,
					'2025-01-02' => 600,
				),
			),
			'tool_b' => array(
				'daily' => array(
					'2025-01-01' => 100,
					'2025-01-02' => 150,
				),
			),
		);

		update_user_meta( $this->test_users[0], WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/analytics/tools/compare' );
		$request->set_param( 'tool_slugs', 'tool_a,tool_b' );
		$request->set_param( 'days', 30 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'comparison', $data );
		$this->assertArrayHasKey( 'popular_tool', $data['comparison'] );
	}
}
