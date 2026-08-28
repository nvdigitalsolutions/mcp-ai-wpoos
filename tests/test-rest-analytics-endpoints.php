<?php
/**
 * Tests for Analytics REST API endpoints.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Register REST routes within rest_api_init - register_rest_route()
		// outside the action raises an incorrect-usage notice. The manager is
		// not wired into the plugin bootstrap, so hook it explicitly here.
		add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Analytics_Manager', 'register_routes' ) );

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
		// Pin to an anonymous user: the bootstrap may leave an admin as the
		// current user, which would satisfy the permission gate.
		wp_set_current_user( 0 );

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

		// Use dates within the 30-day comparison window; older dates are
		// filtered out by the cutoff and would compare as equal (0 vs 0).
		$date_1 = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$date_2 = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Create usage data.
		$usage_1 = array(
			'test_tool' => array(
				'daily' => array(
					$date_1 => 500,
					$date_2 => 600,
				),
			),
		);

		$usage_2 = array(
			'test_tool' => array(
				'daily' => array(
					$date_1 => 100,
					$date_2 => 150,
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

		// Three user IDs fail the validate_user_ids() args gate, which
		// rejects with the standard rest_invalid_param envelope.
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	/**
	 * Test anomalies endpoint for single user.
	 */
	public function test_anomalies_endpoint_single_user() {
		$user_id = $this->test_users[0];

		// Build 20 baseline days inside the 30-day detection window plus one
		// spike. A small sample cannot exceed the 3.0 Z-score threshold: the
		// maximum Z-score for n points is (n-1)/sqrt(n), so 5 points can only
		// reach ~1.79.
		$daily = array();
		for ( $i = 21; $i >= 2; $i-- ) {
			$daily[ gmdate( 'Y-m-d', strtotime( "-{$i} days" ) ) ] = 100;
		}
		$daily[ gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ] = 500; // Anomaly!

		// Create usage data with anomaly.
		$usage = array(
			'test_tool' => array(
				'daily' => $daily,
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

		// Use dates within the 30-day detection window; older dates are
		// filtered out by the cutoff and produce no anomalies.
		$dates = array();
		for ( $i = 11; $i >= 2; $i-- ) {
			$dates[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		}

		// Create usage data.
		$usage = array(
			'test_tool' => array(
				'daily' => array_fill_keys( $dates, 100 ),
			),
		);

		// Add high severity anomaly.
		$usage['test_tool']['daily'][ gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ] = 1000;

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
