<?php
/**
 * Test Enhanced Cost Tracking REST API endpoints.
 *
 * Tests REST API endpoints with enhanced tracking data including
 * estimated vs actual costs and accuracy percentage.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Enhanced Cost Tracking REST API.
 */
class Test_REST_Cost_Manager_Enhanced extends WP_UnitTestCase {

	/**
	 * REST API server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Test admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Test regular user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create test users.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->user_id       = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Initialize enhanced tracking.
		if ( class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
			WP_MCP_AI_Enhanced_Token_Tracking::init();
		}

		// Ensure table exists.
		if ( class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			WP_MCP_AI_Token_Tracking_Database::create_or_update_table();
		}

		// Register REST routes.
		if ( class_exists( 'WP_MCP_AI_REST_Cost_Manager' ) ) {
			WP_MCP_AI_REST_Cost_Manager::register_routes();
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test data.
		global $wpdb;
		$table_name = $wpdb->prefix . 'mcp_ai_hourly_token_usage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$table_name} WHERE user_id IN ({$this->admin_user_id}, {$this->user_id})" );

		// Clean up REST server.
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Test user cost breakdown endpoint includes enhanced fields.
	 */
	public function test_user_cost_breakdown_includes_enhanced_fields() {
		if ( ! class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			$this->markTestSkipped( 'Enhanced tracking not available' );
		}

		// Record some usage.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'chat',
			'openai',
			'gpt-4o',
			1000,
			500,
			0.01,
			false, // actual cost
			gmdate( 'Y-m-d H:i:s' )
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'search',
			'gemini',
			'gemini-1.5-flash',
			2000,
			1000,
			0.005,
			true, // estimated cost
			gmdate( 'Y-m-d H:i:s' )
		);

		// Create request.
		wp_set_current_user( $this->admin_user_id );
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$this->user_id}/cost-breakdown" );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

		// Execute request.
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Verify response structure.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'breakdown', $data );

		// Verify enhanced fields are present.
		$breakdown = $data['breakdown'];
		$this->assertArrayHasKey( 'total_cost', $breakdown );
		$this->assertArrayHasKey( 'estimated_cost', $breakdown );
		$this->assertArrayHasKey( 'actual_cost', $breakdown );
		$this->assertArrayHasKey( 'accuracy_percentage', $breakdown );
		$this->assertArrayHasKey( 'by_provider', $breakdown );

		// Verify values.
		$this->assertEquals( 0.015, $breakdown['total_cost'], 'Total cost incorrect', 0.001 );
		$this->assertEquals( 0.01, $breakdown['actual_cost'], 'Actual cost incorrect', 0.001 );
		$this->assertEquals( 0.005, $breakdown['estimated_cost'], 'Estimated cost incorrect', 0.001 );
		$this->assertGreaterThan( 0, $breakdown['accuracy_percentage'], 'Accuracy percentage should be > 0' );
	}

	/**
	 * Test site cost breakdown endpoint includes accuracy metrics.
	 */
	public function test_site_cost_breakdown_includes_accuracy() {
		if ( ! class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			$this->markTestSkipped( 'Enhanced tracking not available' );
		}

		// Record usage for multiple users.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'chat',
			'openai',
			'gpt-4o',
			1000,
			500,
			0.02,
			false,
			gmdate( 'Y-m-d H:i:s' )
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->admin_user_id,
			'chat',
			'gemini',
			'gemini-1.5-pro',
			2000,
			1000,
			0.01,
			true,
			gmdate( 'Y-m-d H:i:s' )
		);

		// Create request.
		wp_set_current_user( $this->admin_user_id );
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/total' );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

		// Execute request.
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Verify response.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'breakdown', $data );

		// Verify enhanced fields.
		$breakdown = $data['breakdown'];
		$this->assertArrayHasKey( 'total_cost', $breakdown );
		$this->assertArrayHasKey( 'estimated_cost', $breakdown );
		$this->assertArrayHasKey( 'actual_cost', $breakdown );
		$this->assertArrayHasKey( 'accuracy_percentage', $breakdown );

		// Verify aggregation.
		$this->assertEquals( 0.03, $breakdown['total_cost'], 'Total cost incorrect', 0.001 );
		$this->assertEquals( 0.02, $breakdown['actual_cost'], 'Actual cost incorrect', 0.001 );
		$this->assertEquals( 0.01, $breakdown['estimated_cost'], 'Estimated cost incorrect', 0.001 );
	}

	/**
	 * Test permission check for user cost breakdown.
	 */
	public function test_user_cost_breakdown_requires_permission() {
		// Try as subscriber (should fail for other users).
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$this->admin_user_id}/cost-breakdown" );

		$response = $this->server->dispatch( $request );

		// Should be forbidden unless checking own data or admin.
		// Note: Actual permission logic depends on implementation.
		$this->assertNotEquals( 200, $response->get_status(), 'Non-admin should not access other users\' cost data' );
	}

	/**
	 * Test site breakdown requires admin permission.
	 */
	public function test_site_cost_breakdown_requires_admin() {
		// Try as regular user.
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/total' );

		$response = $this->server->dispatch( $request );

		// Should be forbidden for non-admin.
		$this->assertEquals( 403, $response->get_status(), 'Non-admin should not access site cost data' );
	}

	/**
	 * Test accuracy percentage calculation in response.
	 */
	public function test_accuracy_percentage_in_response() {
		if ( ! class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			$this->markTestSkipped( 'Enhanced tracking not available' );
		}

		// Record 75% actual, 25% estimated.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'chat',
			'openai',
			'gpt-4o',
			3000,
			1500,
			0.30,
			false, // actual
			gmdate( 'Y-m-d H:i:s' )
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'search',
			'gemini',
			'gemini-1.5-flash',
			1000,
			500,
			0.10,
			true, // estimated
			gmdate( 'Y-m-d H:i:s' )
		);

		// Make request.
		wp_set_current_user( $this->admin_user_id );
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$this->user_id}/cost-breakdown" );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Verify accuracy percentage.
		$breakdown           = $data['breakdown'];
		$expected_accuracy   = ( 0.30 / 0.40 ) * 100; // 75%.
		$this->assertEquals( round( $expected_accuracy, 2 ), $breakdown['accuracy_percentage'], 'Accuracy percentage calculation incorrect', 0.1 );
	}

	/**
	 * Test backward compatibility - endpoints work without enhanced tracking.
	 */
	public function test_backward_compatibility() {
		// This test verifies endpoints don't break if enhanced tracking unavailable.
		// The service includes fallback logic.

		wp_set_current_user( $this->admin_user_id );
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$this->user_id}/cost-breakdown" );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

		$response = $this->server->dispatch( $request );

		// Should not error even with no data.
		$this->assertNotEquals( 500, $response->get_status(), 'Endpoint should not error without data' );
	}

	/**
	 * Test provider breakdown in REST response.
	 */
	public function test_provider_breakdown_in_response() {
		if ( ! class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			$this->markTestSkipped( 'Enhanced tracking not available' );
		}

		// Record usage for different providers.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'chat',
			'openai',
			'gpt-4o',
			1000,
			500,
			0.01,
			false,
			gmdate( 'Y-m-d H:i:s' )
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$this->user_id,
			'chat',
			'gemini',
			'gemini-1.5-flash',
			2000,
			1000,
			0.005,
			false,
			gmdate( 'Y-m-d H:i:s' )
		);

		// Make request.
		wp_set_current_user( $this->admin_user_id );
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$this->user_id}/cost-breakdown" );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Verify provider breakdown.
		$breakdown = $data['breakdown'];
		$this->assertArrayHasKey( 'by_provider', $breakdown );
		$this->assertArrayHasKey( 'openai', $breakdown['by_provider'] );
		$this->assertArrayHasKey( 'gemini', $breakdown['by_provider'] );
	}
}
