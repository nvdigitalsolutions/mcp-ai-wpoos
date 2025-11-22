<?php
/**
 * Test Enhanced REST Cost Manager functionality.
 *
 * Tests Phase 7 Week 5-6 enhancements to REST API endpoints
 * for actual vs estimated cost tracking and accuracy reporting.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_REST_Cost_Manager_Enhanced
 */
class Test_REST_Cost_Manager_Enhanced extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the database table.
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();

		// Create test admin user.
		$this->admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data.
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		parent::tearDown();
	}

	/**
	 * Test user cost breakdown includes enhanced tracking data.
	 */
	public function test_user_cost_breakdown_includes_enhanced_data() {
		$user_id = $this->factory->user->create();

		// Insert some test data - mix of actual and estimated costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'chat',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.10,
			false // actual cost
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'chat',
			'openai',
			'gpt-4o',
			500,
			250,
			0.05,
			true // estimated cost
		);

		// Create REST request.
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$user_id}/cost-breakdown" );
		$request->set_param( 'id', $user_id );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d' ) );

		// Set current user to admin for permission.
		wp_set_current_user( $this->admin_id );

		// Get response.
		$response = WP_MCP_AI_REST_Cost_Manager::get_user_cost_breakdown( $request );
		$data     = $response->get_data();

		// Verify enhanced tracking data is included.
		$this->assertArrayHasKey( 'cost_summary', $data, 'Response should include cost_summary' );
		$this->assertArrayHasKey( 'actual_cost', $data['cost_summary'], 'cost_summary should include actual_cost' );
		$this->assertArrayHasKey( 'estimated_cost', $data['cost_summary'], 'cost_summary should include estimated_cost' );
		$this->assertArrayHasKey( 'accuracy_percentage', $data['cost_summary'], 'cost_summary should include accuracy_percentage' );

		// Verify values.
		$this->assertEquals( 0.10, $data['cost_summary']['actual_cost'], 'Actual cost should be 0.10' );
		$this->assertEquals( 0.05, $data['cost_summary']['estimated_cost'], 'Estimated cost should be 0.05' );
		$this->assertEquals( 0.15, $data['cost_summary']['total_cost'], 'Total cost should be 0.15' );

		// Accuracy should be (0.10 / 0.15) * 100 = 66.67%.
		$expected_accuracy = ( 0.10 / 0.15 ) * 100;
		$this->assertEquals( $expected_accuracy, $data['cost_summary']['accuracy_percentage'], 'Accuracy percentage should be calculated correctly', 0.01 );
	}

	/**
	 * Test site cost breakdown includes actual vs estimated breakdown.
	 */
	public function test_site_cost_breakdown_includes_actual_vs_estimated() {
		$user_id_1 = $this->factory->user->create();
		$user_id_2 = $this->factory->user->create();

		// Insert test data for multiple users.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id_1,
			'chat',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.10,
			false // actual
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id_2,
			'chat',
			'gemini',
			'gemini-1.5-pro',
			2000,
			1000,
			0.20,
			true // estimated
		);

		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/total' );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d' ) );

		// Set current user to admin for permission.
		wp_set_current_user( $this->admin_id );

		// Get response.
		$response = WP_MCP_AI_REST_Cost_Manager::get_site_cost_breakdown( $request );
		$data     = $response->get_data();

		// Verify enhanced tracking fields.
		$this->assertArrayHasKey( 'estimated_cost', $data, 'Response should include estimated_cost' );
		$this->assertArrayHasKey( 'actual_cost', $data, 'Response should include actual_cost' );
		$this->assertArrayHasKey( 'accuracy_percentage', $data, 'Response should include accuracy_percentage' );

		// Verify values.
		$this->assertEquals( 0.10, $data['actual_cost'], 'Actual cost should be 0.10' );
		$this->assertEquals( 0.20, $data['estimated_cost'], 'Estimated cost should be 0.20' );

		// Accuracy should be (0.10 / 0.30) * 100 = 33.33%.
		$expected_accuracy = ( 0.10 / 0.30 ) * 100;
		$this->assertEquals( $expected_accuracy, $data['accuracy_percentage'], 'Accuracy percentage should be calculated correctly', 0.01 );
	}

	/**
	 * Test dashboard summary includes enhanced statistics.
	 */
	public function test_dashboard_summary_includes_enhanced_stats() {
		$user_id = $this->factory->user->create();

		// Insert multiple records with different costs.
		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_AI_Token_Tracking_Database::record_usage(
				$user_id,
				'chat',
				'openai',
				'gpt-4o-mini',
				1000,
				500,
				0.05,
				false // actual
			);
		}

		for ( $i = 0; $i < 3; $i++ ) {
			WP_MCP_AI_Token_Tracking_Database::record_usage(
				$user_id,
				'tool_test',
				'gemini',
				'gemini-1.5-pro',
				500,
				250,
				0.03,
				true // estimated
			);
		}

		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/dashboard-summary' );
		$request->set_param( 'days', 7 );

		// Set current user to admin for permission.
		wp_set_current_user( $this->admin_id );

		// Get response.
		$response = WP_MCP_AI_REST_Cost_Manager::get_dashboard_summary( $request );
		$data     = $response->get_data();

		// Verify enhanced stats are included.
		$this->assertArrayHasKey( 'enhanced_stats', $data, 'Response should include enhanced_stats' );
		$this->assertArrayHasKey( 'total_cost', $data['enhanced_stats'], 'enhanced_stats should include total_cost' );
		$this->assertArrayHasKey( 'estimated_cost', $data['enhanced_stats'], 'enhanced_stats should include estimated_cost' );
		$this->assertArrayHasKey( 'actual_cost', $data['enhanced_stats'], 'enhanced_stats should include actual_cost' );
		$this->assertArrayHasKey( 'accuracy_percentage', $data['enhanced_stats'], 'enhanced_stats should include accuracy_percentage' );
		$this->assertArrayHasKey( 'total_tokens', $data['enhanced_stats'], 'enhanced_stats should include total_tokens' );
		$this->assertArrayHasKey( 'total_records', $data['enhanced_stats'], 'enhanced_stats should include total_records' );

		// Verify values.
		$expected_actual    = 0.05 * 5; // 5 records at $0.05 each.
		$expected_estimated = 0.03 * 3; // 3 records at $0.03 each.
		$expected_total     = $expected_actual + $expected_estimated;

		$this->assertEquals( $expected_actual, $data['enhanced_stats']['actual_cost'], 'Actual cost should match' );
		$this->assertEquals( $expected_estimated, $data['enhanced_stats']['estimated_cost'], 'Estimated cost should match' );
		$this->assertEquals( $expected_total, $data['enhanced_stats']['total_cost'], 'Total cost should match' );
		$this->assertEquals( 8, $data['enhanced_stats']['total_records'], 'Total records should be 8' );

		// Accuracy should be (actual / total) * 100.
		$expected_accuracy = ( $expected_actual / $expected_total ) * 100;
		$this->assertEquals( $expected_accuracy, $data['enhanced_stats']['accuracy_percentage'], 'Accuracy percentage should be calculated correctly', 0.01 );
	}

	/**
	 * Test accuracy calculation when no actual costs exist.
	 */
	public function test_accuracy_with_no_actual_costs() {
		$user_id = $this->factory->user->create();

		// Insert only estimated costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'chat',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.10,
			true // estimated only
		);

		// Create REST request.
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$user_id}/cost-breakdown" );
		$request->set_param( 'id', $user_id );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d' ) );

		wp_set_current_user( $this->admin_id );

		$response = WP_MCP_AI_REST_Cost_Manager::get_user_cost_breakdown( $request );
		$data     = $response->get_data();

		// Accuracy should be 0% when all costs are estimated.
		$this->assertEquals( 0.0, $data['cost_summary']['accuracy_percentage'], 'Accuracy should be 0% when all costs are estimated' );
	}

	/**
	 * Test accuracy calculation when all costs are actual.
	 */
	public function test_accuracy_with_all_actual_costs() {
		$user_id = $this->factory->user->create();

		// Insert only actual costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'chat',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.10,
			false // actual only
		);

		// Create REST request.
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$user_id}/cost-breakdown" );
		$request->set_param( 'id', $user_id );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d' ) );

		wp_set_current_user( $this->admin_id );

		$response = WP_MCP_AI_REST_Cost_Manager::get_user_cost_breakdown( $request );
		$data     = $response->get_data();

		// Accuracy should be 100% when all costs are actual.
		$this->assertEquals( 100.0, $data['cost_summary']['accuracy_percentage'], 'Accuracy should be 100% when all costs are actual' );
	}

	/**
	 * Test backward compatibility when enhanced tracking is not available.
	 */
	public function test_backward_compatibility_without_enhanced_tracking() {
		$user_id = $this->factory->user->create();

		// Create REST request.
		$request = new WP_REST_Request( 'GET', "/mcp-ai/v1/users/{$user_id}/cost-breakdown" );
		$request->set_param( 'id', $user_id );
		$request->set_param( 'start_date', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$request->set_param( 'end_date', gmdate( 'Y-m-d' ) );

		wp_set_current_user( $this->admin_id );

		$response = WP_MCP_AI_REST_Cost_Manager::get_user_cost_breakdown( $request );
		$data     = $response->get_data();

		// Response should still work, cost_summary may be empty but should exist.
		$this->assertIsArray( $data, 'Response should be an array' );
		$this->assertArrayHasKey( 'user_id', $data, 'Response should include user_id' );
		$this->assertArrayHasKey( 'breakdown', $data, 'Response should include breakdown' );
	}
}
