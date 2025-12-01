<?php
/**
 * Test chart data methods for Token Manager
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for chart data generation
 */
class Test_Chart_Data extends WP_UnitTestCase {

	/**
	 * Test user IDs.
	 *
	 * @var array
	 */
	protected $test_user_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test users with different tiers.
		$this->test_user_ids = array(
			'free'       => $this->factory->user->create( array( 'role' => 'subscriber' ) ),
			'pro'        => $this->factory->user->create( array( 'role' => 'editor' ) ),
			'enterprise' => $this->factory->user->create( array( 'role' => 'administrator' ) ),
		);

		// Set up tier assignments.
		update_user_meta( $this->test_user_ids['pro'], WP_MCP_AI_Tool_Token_Limits::TIER_META_KEY, 'pro' );
		update_user_meta( $this->test_user_ids['enterprise'], WP_MCP_AI_Tool_Token_Limits::TIER_META_KEY, 'enterprise' );

		// Add some usage data for testing.
		$this->add_test_usage_data();
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		foreach ( $this->test_user_ids as $user_id ) {
			delete_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
			delete_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::TIER_META_KEY );
		}

		parent::tearDown();
	}

	/**
	 * Add test usage data.
	 */
	private function add_test_usage_data() {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Add usage for free tier user.
		$usage_data = array(
			'general_tools' => array(
				'total_tokens' => 5000,
				'requests'     => 10,
				'daily'        => array(
					$today     => 3000,
					$yesterday => 2000,
				),
			),
		);
		update_user_meta( $this->test_user_ids['free'], WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_data );

		// Add usage for pro tier user.
		$usage_data = array(
			'general_tools'    => array(
				'total_tokens' => 15000,
				'requests'     => 30,
				'daily'        => array(
					$today     => 9000,
					$yesterday => 6000,
				),
			),
			'run_crawl4ai_job' => array(
				'total_tokens' => 20000,
				'requests'     => 5,
				'daily'        => array(
					$today     => 12000,
					$yesterday => 8000,
				),
			),
		);
		update_user_meta( $this->test_user_ids['pro'], WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_data );
	}

	/**
	 * Test get_usage_trend_data with default parameters.
	 */
	public function test_get_usage_trend_data_default() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data();

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels' );
		$this->assertArrayHasKey( 'datasets', $data, 'Should have datasets' );

		// Verify labels are dates.
		$this->assertIsArray( $data['labels'], 'Labels should be an array' );
		$this->assertGreaterThan( 0, count( $data['labels'] ), 'Should have at least one label' );

		// Verify datasets structure.
		$this->assertIsArray( $data['datasets'], 'Datasets should be an array' );
		if ( ! empty( $data['datasets'] ) ) {
			$this->assertArrayHasKey( 'label', $data['datasets'][0], 'Dataset should have label' );
			$this->assertArrayHasKey( 'data', $data['datasets'][0], 'Dataset should have data' );
		}
	}

	/**
	 * Test get_usage_trend_data with specific user.
	 */
	public function test_get_usage_trend_data_specific_user() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
			array(
				'user_id' => $this->test_user_ids['pro'],
				'days'    => 7,
			)
		);

		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data );
		$this->assertArrayHasKey( 'datasets', $data );
	}

	/**
	 * Test get_usage_trend_data with different day ranges.
	 */
	public function test_get_usage_trend_data_different_ranges() {
		// Test 7 days.
		$data_7 = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 7 ) );
		$this->assertEquals( 7, count( $data_7['labels'] ), 'Should have 7 labels for 7 days' );

		// Test 30 days.
		$data_30 = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 30 ) );
		$this->assertEquals( 30, count( $data_30['labels'] ), 'Should have 30 labels for 30 days' );

		// Test 90 days.
		$data_90 = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 90 ) );
		$this->assertEquals( 90, count( $data_90['labels'] ), 'Should have 90 labels for 90 days' );
	}

	/**
	 * Test get_tier_distribution_data.
	 */
	public function test_get_tier_distribution_data() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels' );
		$this->assertArrayHasKey( 'values', $data, 'Should have values' );

		// Verify we have 3 tiers.
		$this->assertEquals( 3, count( $data['labels'] ), 'Should have 3 tier labels' );
		$this->assertEquals( 3, count( $data['values'] ), 'Should have 3 tier values' );

		// Verify tier labels.
		$this->assertContains( 'Free', $data['labels'], 'Should have Free tier' );
		$this->assertContains( 'Pro', $data['labels'], 'Should have Pro tier' );
		$this->assertContains( 'Enterprise', $data['labels'], 'Should have Enterprise tier' );

		// Verify values are non-negative integers.
		foreach ( $data['values'] as $value ) {
			$this->assertIsInt( $value, 'Value should be an integer' );
			$this->assertGreaterThanOrEqual( 0, $value, 'Value should be non-negative' );
		}

		// We should have at least our 3 test users distributed across tiers.
		$total_users = array_sum( $data['values'] );
		$this->assertGreaterThanOrEqual( 3, $total_users, 'Should have at least 3 users total' );
	}

	/**
	 * Test get_tool_breakdown_data with default parameters.
	 */
	public function test_get_tool_breakdown_data_default() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data();

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels' );
		$this->assertArrayHasKey( 'values', $data, 'Should have values' );

		// Verify labels and values have same count.
		$this->assertEquals(
			count( $data['labels'] ),
			count( $data['values'] ),
			'Labels and values should have same count'
		);

		// Verify values are sorted descending.
		if ( count( $data['values'] ) > 1 ) {
			$sorted_values = $data['values'];
			rsort( $sorted_values );
			$this->assertEquals( $sorted_values, $data['values'], 'Values should be sorted descending' );
		}
	}

	/**
	 * Test get_tool_breakdown_data with limit.
	 */
	public function test_get_tool_breakdown_data_with_limit() {
		$limit = 5;
		$data  = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data( array( 'limit' => $limit ) );

		$this->assertLessThanOrEqual( $limit, count( $data['labels'] ), 'Should not exceed limit' );
		$this->assertLessThanOrEqual( $limit, count( $data['values'] ), 'Should not exceed limit' );
	}

	/**
	 * Test get_tool_breakdown_data with specific user.
	 */
	public function test_get_tool_breakdown_data_specific_user() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data(
			array(
				'user_id' => $this->test_user_ids['pro'],
			)
		);

		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data );
		$this->assertArrayHasKey( 'values', $data );

		// Pro user should have both general_tools and run_crawl4ai_job.
		$this->assertGreaterThanOrEqual( 2, count( $data['labels'] ), 'Pro user should have at least 2 tools' );
	}

	/**
	 * Test get_usage_forecast_data.
	 */
	public function test_get_usage_forecast_data() {
		$data = WP_MCP_AI_Analytics_Dashboard::get_usage_forecast_data();

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'current_usage', $data, 'Should have current_usage' );
		$this->assertArrayHasKey( 'projected_usage', $data, 'Should have projected_usage' );
		$this->assertArrayHasKey( 'trend', $data, 'Should have trend' );
		$this->assertArrayHasKey( 'confidence', $data, 'Should have confidence' );

		// Verify trend is one of expected values.
		$this->assertContains(
			$data['trend'],
			array( 'increasing', 'decreasing', 'stable' ),
			'Trend should be increasing, decreasing, or stable'
		);

		// Verify confidence is between 0 and 100.
		$this->assertGreaterThanOrEqual( 0, $data['confidence'], 'Confidence should be >= 0' );
		$this->assertLessThanOrEqual( 100, $data['confidence'], 'Confidence should be <= 100' );
	}

	/**
	 * Test chart data with no usage data.
	 */
	public function test_chart_data_with_no_usage() {
		// Create a new user with no usage.
		$empty_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
			array(
				'user_id' => $empty_user,
			)
		);

		// Should still return valid structure.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'labels', $data );
		$this->assertArrayHasKey( 'datasets', $data );

		// Clean up.
		wp_delete_user( $empty_user );
	}
}
