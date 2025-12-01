<?php
/**
 * Tests for Analytics Engine.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_Analytics_Engine.
 */
class Test_Analytics_Engine extends WP_UnitTestCase {

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

		// Create test users.
		$this->test_users[] = $this->factory->user->create();
		$this->test_users[] = $this->factory->user->create();

		// Ensure Analytics Engine class is loaded.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-analytics-engine.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-token-limits.php';
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up test users.
		foreach ( $this->test_users as $user_id ) {
			wp_delete_user( $user_id );
		}

		parent::tearDown();
	}

	/**
	 * Test linear regression calculation.
	 */
	public function test_calculate_trend() {
		// Create simple increasing trend data.
		$data_points = array(
			strtotime( '2025-01-01' ) => 100,
			strtotime( '2025-01-02' ) => 150,
			strtotime( '2025-01-03' ) => 200,
			strtotime( '2025-01-04' ) => 250,
			strtotime( '2025-01-05' ) => 300,
		);

		$trend = WP_MCP_AI_Analytics_Engine::calculate_trend( $data_points );

		$this->assertIsArray( $trend );
		$this->assertArrayHasKey( 'slope', $trend );
		$this->assertArrayHasKey( 'intercept', $trend );
		$this->assertArrayHasKey( 'r_squared', $trend );
		$this->assertArrayHasKey( 'direction', $trend );
		$this->assertArrayHasKey( 'confidence', $trend );

		// Should detect increasing trend.
		$this->assertEquals( 'increasing', $trend['direction'] );

		// Should have high confidence for perfect linear data.
		$this->assertGreaterThan( 90, $trend['confidence'] );

		// R-squared should be close to 1.0 for perfect fit.
		$this->assertGreaterThan( 0.9, $trend['r_squared'] );
	}

	/**
	 * Test trend calculation with decreasing data.
	 */
	public function test_calculate_trend_decreasing() {
		$data_points = array(
			strtotime( '2025-01-01' ) => 300,
			strtotime( '2025-01-02' ) => 250,
			strtotime( '2025-01-03' ) => 200,
			strtotime( '2025-01-04' ) => 150,
			strtotime( '2025-01-05' ) => 100,
		);

		$trend = WP_MCP_AI_Analytics_Engine::calculate_trend( $data_points );

		$this->assertEquals( 'decreasing', $trend['direction'] );
		$this->assertLessThan( 0, $trend['slope'] );
	}

	/**
	 * Test trend calculation with stable data.
	 */
	public function test_calculate_trend_stable() {
		$data_points = array(
			strtotime( '2025-01-01' ) => 100,
			strtotime( '2025-01-02' ) => 105,
			strtotime( '2025-01-03' ) => 98,
			strtotime( '2025-01-04' ) => 102,
			strtotime( '2025-01-05' ) => 100,
		);

		$trend = WP_MCP_AI_Analytics_Engine::calculate_trend( $data_points );

		$this->assertEquals( 'stable', $trend['direction'] );
	}

	/**
	 * Test statistical calculations.
	 */
	public function test_calculate_statistics() {
		$values = array( 10, 20, 30, 40, 50 );

		$stats = WP_MCP_AI_Analytics_Engine::calculate_statistics( $values );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'mean', $stats );
		$this->assertArrayHasKey( 'median', $stats );
		$this->assertArrayHasKey( 'std_dev', $stats );
		$this->assertArrayHasKey( 'variance', $stats );
		$this->assertArrayHasKey( 'min', $stats );
		$this->assertArrayHasKey( 'max', $stats );
		$this->assertArrayHasKey( 'count', $stats );

		$this->assertEquals( 30, $stats['mean'] );
		$this->assertEquals( 30, $stats['median'] );
		$this->assertEquals( 10, $stats['min'] );
		$this->assertEquals( 50, $stats['max'] );
		$this->assertEquals( 5, $stats['count'] );
	}

	/**
	 * Test statistics with empty data.
	 */
	public function test_calculate_statistics_empty() {
		$stats = WP_MCP_AI_Analytics_Engine::calculate_statistics( array() );

		$this->assertEquals( 0, $stats['mean'] );
		$this->assertEquals( 0, $stats['median'] );
		$this->assertEquals( 0, $stats['count'] );
	}

	/**
	 * Test Z-score calculation.
	 */
	public function test_calculate_z_score() {
		$mean    = 100;
		$std_dev = 15;

		// Value 1 std dev above mean should have Z-score of 1.
		$z_score = WP_MCP_AI_Analytics_Engine::calculate_z_score( 115, $mean, $std_dev );
		$this->assertEquals( 1, $z_score );

		// Value 2 std dev below mean should have Z-score of -2.
		$z_score = WP_MCP_AI_Analytics_Engine::calculate_z_score( 70, $mean, $std_dev );
		$this->assertEquals( -2, $z_score );

		// Value at mean should have Z-score of 0.
		$z_score = WP_MCP_AI_Analytics_Engine::calculate_z_score( 100, $mean, $std_dev );
		$this->assertEquals( 0, $z_score );
	}

	/**
	 * Test Z-score with zero std dev.
	 */
	public function test_calculate_z_score_zero_std_dev() {
		$z_score = WP_MCP_AI_Analytics_Engine::calculate_z_score( 100, 100, 0 );
		$this->assertEquals( 0, $z_score );
	}

	/**
	 * Test pattern detection with mock data.
	 */
	public function test_detect_patterns() {
		$user_id = $this->test_users[0];

		// Create mock usage data.
		$usage = array(
			'test_tool' => array(
				'total_tokens' => 1000,
				'hourly'       => array(
					'2025-01-01-09' => 100,
					'2025-01-01-10' => 200,
					'2025-01-01-11' => 150,
					'2025-01-02-09' => 120,
					'2025-01-02-10' => 180,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		$patterns = WP_MCP_AI_Analytics_Engine::detect_patterns( $user_id );

		$this->assertIsArray( $patterns );
		$this->assertArrayHasKey( 'peak_hours', $patterns );
		$this->assertArrayHasKey( 'peak_days', $patterns );
		$this->assertArrayHasKey( 'hourly_pattern', $patterns );
		$this->assertArrayHasKey( 'daily_pattern', $patterns );
		$this->assertArrayHasKey( 'usage_type', $patterns );

		// Should detect hour 10 as peak hour.
		$this->assertContains( 10, $patterns['peak_hours'] );
	}

	/**
	 * Test user comparison.
	 */
	public function test_compare_users() {
		$user_1 = $this->test_users[0];
		$user_2 = $this->test_users[1];

		// Create usage data for user 1 (higher usage).
		$usage_1 = array(
			'test_tool' => array(
				'daily' => array(
					'2025-01-01' => 500,
					'2025-01-02' => 600,
					'2025-01-03' => 550,
				),
			),
		);

		// Create usage data for user 2 (lower usage).
		$usage_2 = array(
			'test_tool' => array(
				'daily' => array(
					'2025-01-01' => 100,
					'2025-01-02' => 150,
					'2025-01-03' => 120,
				),
			),
		);

		update_user_meta( $user_1, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_1 );
		update_user_meta( $user_2, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage_2 );

		$comparison = WP_MCP_AI_Analytics_Engine::compare_users( $user_1, $user_2, 30 );

		$this->assertIsArray( $comparison );
		$this->assertArrayHasKey( 'user1_stats', $comparison );
		$this->assertArrayHasKey( 'user2_stats', $comparison );
		$this->assertArrayHasKey( 'usage_ratio', $comparison );
		$this->assertArrayHasKey( 'higher_user', $comparison );
		$this->assertArrayHasKey( 'difference_pct', $comparison );

		// User 1 should have higher usage.
		$this->assertEquals( $user_1, $comparison['higher_user'] );

		// Usage ratio should be greater than 1.
		$this->assertGreaterThan( 1, $comparison['usage_ratio'] );
	}

	/**
	 * Test anomaly detection.
	 */
	public function test_detect_anomalies() {
		$user_id = $this->test_users[0];

		// Create usage data with one anomaly.
		$usage = array(
			'test_tool' => array(
				'daily' => array(
					'2025-01-01' => 100,
					'2025-01-02' => 105,
					'2025-01-03' => 98,
					'2025-01-04' => 102,
					'2025-01-05' => 500, // Anomaly!
					'2025-01-06' => 100,
					'2025-01-07' => 95,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		$anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $user_id, 3.0 );

		$this->assertIsArray( $anomalies );
		$this->assertNotEmpty( $anomalies );

		// Should detect the anomaly on 2025-01-05.
		$anomaly_dates = array_column( $anomalies, 'date' );
		$this->assertContains( '2025-01-05', $anomaly_dates );

		// Check anomaly structure.
		$anomaly = $anomalies[0];
		$this->assertArrayHasKey( 'date', $anomaly );
		$this->assertArrayHasKey( 'tokens', $anomaly );
		$this->assertArrayHasKey( 'z_score', $anomaly );
		$this->assertArrayHasKey( 'expected_value', $anomaly );
		$this->assertArrayHasKey( 'severity', $anomaly );
	}

	/**
	 * Test severity classification.
	 */
	public function test_anomaly_severity_classification() {
		$user_id = $this->test_users[0];

		// Create data with different severity anomalies.
		$base_usage = array_fill( 0, 20, 100 );
		$usage      = array();

		$date = strtotime( '2025-01-01' );
		foreach ( $base_usage as $tokens ) {
			$date_key                                 = gmdate( 'Y-m-d', $date );
			$usage['test_tool']['daily'][ $date_key ] = $tokens;
			$date                                    += DAY_IN_SECONDS;
		}

		// Add critical anomaly (6+ std dev).
		$usage['test_tool']['daily']['2025-01-21'] = 1000;

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		$anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $user_id, 3.0 );

		$this->assertNotEmpty( $anomalies );

		// Find the critical anomaly.
		$critical = array_filter(
			$anomalies,
			function ( $a ) {
				return $a['date'] === '2025-01-21';
			}
		);

		$this->assertNotEmpty( $critical );
	}

	/**
	 * Test get_user_trends.
	 */
	public function test_get_user_trends() {
		$user_id = $this->test_users[0];

		// Create usage data with clear trend.
		$usage = array(
			'test_tool' => array(
				'daily'  => array(
					'2025-01-01' => 100,
					'2025-01-02' => 120,
					'2025-01-03' => 140,
					'2025-01-04' => 160,
					'2025-01-05' => 180,
				),
				'hourly' => array(
					'2025-01-01-09' => 50,
					'2025-01-01-10' => 50,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		$trends = WP_MCP_AI_Analytics_Engine::get_user_trends( $user_id, 30 );

		$this->assertIsArray( $trends );
		$this->assertArrayHasKey( 'daily_usage', $trends );
		$this->assertArrayHasKey( 'trend', $trends );
		$this->assertArrayHasKey( 'statistics', $trends );
		$this->assertArrayHasKey( 'patterns', $trends );
		$this->assertArrayHasKey( 'projected_7d', $trends );
		$this->assertArrayHasKey( 'projected_30d', $trends );

		// Should detect increasing trend.
		$this->assertEquals( 'increasing', $trends['trend']['direction'] );

		// Projections should be positive.
		$this->assertGreaterThan( 0, $trends['projected_7d'] );
		$this->assertGreaterThan( 0, $trends['projected_30d'] );
	}

	/**
	 * Test get_user_trends with empty usage data.
	 *
	 * This test ensures the method handles users with no usage data gracefully,
	 * preventing the ValueError that was occurring on line 440.
	 */
	public function test_get_user_trends_empty_data() {
		$user_id = $this->test_users[0];

		// Ensure user has no usage data.
		delete_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );

		$trends = WP_MCP_AI_Analytics_Engine::get_user_trends( $user_id, 30 );

		$this->assertIsArray( $trends );
		$this->assertArrayHasKey( 'daily_usage', $trends );
		$this->assertArrayHasKey( 'trend', $trends );
		$this->assertArrayHasKey( 'statistics', $trends );
		$this->assertArrayHasKey( 'patterns', $trends );
		$this->assertArrayHasKey( 'projected_7d', $trends );
		$this->assertArrayHasKey( 'projected_30d', $trends );

		// With no data, daily_usage should be empty.
		$this->assertEmpty( $trends['daily_usage'] );

		// Should return stable trend with no data.
		$this->assertEquals( 'stable', $trends['trend']['direction'] );

		// Projections should be 0 with no data.
		$this->assertEquals( 0, $trends['projected_7d'] );
		$this->assertEquals( 0, $trends['projected_30d'] );

		// Statistics should handle empty data gracefully.
		$this->assertEquals( 0, $trends['statistics']['mean'] );
		$this->assertEquals( 0, $trends['statistics']['count'] );
	}

	/**
	 * Test rebuild_usage_from_transcripts method.
	 *
	 * This test verifies that the method can handle the case when
	 * JetEngine CCT is not available, returning appropriate error messages.
	 */
	public function test_rebuild_usage_from_transcripts_without_jetengine() {
		$user_id = $this->test_users[0];

		// Call rebuild when JetEngine is not available.
		$result = WP_MCP_AI_Analytics_Engine::rebuild_usage_from_transcripts( $user_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'transcripts_processed', $result );
		$this->assertArrayHasKey( 'users_updated', $result );
		$this->assertArrayHasKey( 'tokens_recovered', $result );
		$this->assertArrayHasKey( 'errors', $result );

		// Should have 0 results since JetEngine is not available in test environment.
		$this->assertEquals( 0, $result['transcripts_processed'] );
		$this->assertEquals( 0, $result['users_updated'] );
		$this->assertEquals( 0, $result['tokens_recovered'] );

		// Should have an error message.
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test get_site_wide_trends method.
	 *
	 * This test verifies that site-wide trends can be calculated by aggregating
	 * usage data from multiple users.
	 */
	public function test_get_site_wide_trends() {
		// Create usage data for multiple users.
		foreach ( $this->test_users as $index => $user_id ) {
			$base_tokens = ( $index + 1 ) * 100;
			$usage       = array(
				'test_tool' => array(
					'total_tokens' => $base_tokens * 5,
					'requests'     => 5,
					'daily'        => array(
						'2025-01-01' => $base_tokens,
						'2025-01-02' => $base_tokens + 20,
						'2025-01-03' => $base_tokens + 40,
						'2025-01-04' => $base_tokens + 60,
						'2025-01-05' => $base_tokens + 80,
					),
				),
			);

			update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );
		}

		// Test with user_id = 0 to get site-wide trends.
		$trends = WP_MCP_AI_Analytics_Engine::get_user_trends( 0, 30 );

		$this->assertIsArray( $trends );
		$this->assertArrayHasKey( 'daily_usage', $trends );
		$this->assertArrayHasKey( 'trend', $trends );
		$this->assertArrayHasKey( 'statistics', $trends );
		$this->assertArrayHasKey( 'patterns', $trends );
		$this->assertArrayHasKey( 'projected_7d', $trends );
		$this->assertArrayHasKey( 'projected_30d', $trends );

		// Should have aggregated data from both users.
		$this->assertNotEmpty( $trends['daily_usage'] );

		// Should detect increasing trend (both users have increasing usage).
		$this->assertEquals( 'increasing', $trends['trend']['direction'] );

		// Projections should be positive.
		$this->assertGreaterThan( 0, $trends['projected_7d'] );
		$this->assertGreaterThan( 0, $trends['projected_30d'] );

		// Statistics should reflect aggregated data.
		$this->assertGreaterThan( 0, $trends['statistics']['mean'] );
		$this->assertEquals( 5, $trends['statistics']['count'] ); // 5 days of data.
	}

	/**
	 * Test get_site_wide_trends with no data.
	 *
	 * This test ensures the method handles empty site-wide data gracefully.
	 */
	public function test_get_site_wide_trends_empty() {
		// Ensure no users have usage data.
		foreach ( $this->test_users as $user_id ) {
			delete_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
		}

		// Test with user_id = 0 to get site-wide trends.
		$trends = WP_MCP_AI_Analytics_Engine::get_user_trends( 0, 30 );

		$this->assertIsArray( $trends );
		$this->assertArrayHasKey( 'daily_usage', $trends );
		$this->assertArrayHasKey( 'trend', $trends );

		// With no data, daily_usage should be empty.
		$this->assertEmpty( $trends['daily_usage'] );

		// Should return stable trend with no data.
		$this->assertEquals( 'stable', $trends['trend']['direction'] );

		// Projections should be 0 with no data.
		$this->assertEquals( 0, $trends['projected_7d'] );
		$this->assertEquals( 0, $trends['projected_30d'] );
	}
}
