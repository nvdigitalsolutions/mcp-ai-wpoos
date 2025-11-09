<?php
/**
 * Tests for enhanced Token Budget Manager features.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for enhanced token budget manager functionality.
 */
class WP_MCP_AI_Enhanced_Token_Budget_Manager_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Clean up any existing test data.
		delete_option( 'wp_mcp_ai_token_analytics' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_token_analytics' );
		
		parent::tearDown();
	}

	/**
	 * Test recording analytics.
	 */
	public function test_record_analytics() {
		$user_id = 1;
		$tokens  = 1000;
		$context = array(
			'model'     => 'gpt-4o-mini',
			'assistant' => 'test-assistant',
		);

		WP_MCP_AI_Token_Budget_Manager::record_analytics( $user_id, $tokens, $context );

		$analytics_data = get_option( 'wp_mcp_ai_token_analytics', array() );

		$this->assertIsArray( $analytics_data );
		$this->assertArrayHasKey( $user_id, $analytics_data );
		$this->assertNotEmpty( $analytics_data[ $user_id ] );

		// Check the recorded data.
		$last_entry = end( $analytics_data[ $user_id ] );
		$this->assertEquals( 1000, $last_entry['tokens'] );
		$this->assertEquals( 1, $last_entry['requests'] );
		$this->assertEquals( $context, $last_entry['context'] );
	}

	/**
	 * Test getting usage analytics with no data.
	 */
	public function test_get_usage_analytics_no_data() {
		$user_id   = 1;
		$analytics = WP_MCP_AI_Token_Budget_Manager::get_usage_analytics( $user_id, '24h' );

		$this->assertIsArray( $analytics );
		$this->assertEquals( 0, $analytics['total_tokens'] );
		$this->assertEquals( 0, $analytics['total_requests'] );
		$this->assertEquals( 'insufficient_data', $analytics['trend'] );
	}

	/**
	 * Test getting usage analytics with data.
	 */
	public function test_get_usage_analytics_with_data() {
		$user_id = 1;

		// Record some analytics data.
		for ( $i = 0; $i < 10; $i++ ) {
			WP_MCP_AI_Token_Budget_Manager::record_analytics( $user_id, 500, array() );
			sleep( 1 ); // Ensure different timestamps.
		}

		$analytics = WP_MCP_AI_Token_Budget_Manager::get_usage_analytics( $user_id, '24h' );

		$this->assertIsArray( $analytics );
		$this->assertEquals( 5000, $analytics['total_tokens'] );
		$this->assertEquals( 10, $analytics['total_requests'] );
		$this->assertEquals( 500, $analytics['avg_tokens_per_request'] );
		$this->assertGreaterThan( 0, $analytics['peak_usage'] );
	}

	/**
	 * Test analytics time period filtering.
	 */
	public function test_analytics_time_period_filtering() {
		$user_id = 1;
		$now     = time();

		// Manually create analytics with different timestamps.
		$analytics_data = array(
			$user_id => array(
				$now - ( 2 * HOUR_IN_SECONDS )  => array(
					'tokens'   => 500,
					'requests' => 1,
					'context'  => array(),
				),
				$now - ( 25 * HOUR_IN_SECONDS ) => array(
					'tokens'   => 300,
					'requests' => 1,
					'context'  => array(),
				),
				$now                            => array(
					'tokens'   => 1000,
					'requests' => 1,
					'context'  => array(),
				),
			),
		);

		update_option( 'wp_mcp_ai_token_analytics', $analytics_data );

		$analytics_24h = WP_MCP_AI_Token_Budget_Manager::get_usage_analytics( $user_id, '24h' );

		// Should only include data from last 24 hours (2 entries).
		$this->assertEquals( 1500, $analytics_24h['total_tokens'] ); // 500 + 1000.
		$this->assertEquals( 2, $analytics_24h['total_requests'] );
	}

	/**
	 * Test trend detection.
	 */
	public function test_trend_detection() {
		$user_id = 1;
		$now     = time();

		// Create data showing increasing trend.
		$analytics_data = array(
			$user_id => array(),
		);

		// First 12 hours: lower usage.
		for ( $i = 1; $i <= 12; $i++ ) {
			$timestamp                                  = $now - ( ( 24 - $i ) * HOUR_IN_SECONDS );
			$analytics_data[ $user_id ][ $timestamp ] = array(
				'tokens'   => 100,
				'requests' => 1,
				'context'  => array(),
			);
		}

		// Last 12 hours: higher usage (increasing trend).
		for ( $i = 13; $i <= 24; $i++ ) {
			$timestamp                                  = $now - ( ( 24 - $i ) * HOUR_IN_SECONDS );
			$analytics_data[ $user_id ][ $timestamp ] = array(
				'tokens'   => 500,
				'requests' => 1,
				'context'  => array(),
			);
		}

		update_option( 'wp_mcp_ai_token_analytics', $analytics_data );

		$analytics = WP_MCP_AI_Token_Budget_Manager::get_usage_analytics( $user_id, '24h' );

		$this->assertEquals( 'increasing', $analytics['trend'] );
	}

	/**
	 * Test usage forecasting with no data.
	 */
	public function test_forecast_usage_no_data() {
		$user_id  = 1;
		$forecast = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '24h' );

		$this->assertIsArray( $forecast );
		$this->assertEquals( 0, $forecast['forecasted_tokens'] );
		$this->assertEquals( 0, $forecast['confidence'] );
		$this->assertEquals( 'insufficient_data', $forecast['recommendation'] );
	}

	/**
	 * Test usage forecasting with data.
	 */
	public function test_forecast_usage_with_data() {
		$user_id = 1;

		// Record analytics for 7 days to build history.
		for ( $i = 0; $i < 50; $i++ ) {
			WP_MCP_AI_Token_Budget_Manager::record_analytics( $user_id, 1000, array() );
		}

		$forecast = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '24h' );

		$this->assertIsArray( $forecast );
		$this->assertGreaterThan( 0, $forecast['forecasted_tokens'] );
		$this->assertGreaterThan( 0, $forecast['confidence'] );
		$this->assertEquals( '24h', $forecast['period'] );
	}

	/**
	 * Test forecast recommendation for increasing trend.
	 */
	public function test_forecast_recommendation_increasing_trend() {
		$user_id = 1;

		// Record data showing increasing trend.
		for ( $i = 0; $i < 50; $i++ ) {
			// Linearly increasing token usage.
			$tokens = 1000 + ( $i * 100 );
			WP_MCP_AI_Token_Budget_Manager::record_analytics( $user_id, $tokens, array() );
		}

		$forecast = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '24h' );

		// With increasing trend, should recommend budget increase if forecast is high.
		$this->assertContains( $forecast['recommendation'], array( 'normal', 'consider_budget_increase' ) );
	}

	/**
	 * Test analytics data cleanup.
	 */
	public function test_analytics_data_cleanup() {
		$user_id = 1;
		$now     = time();

		// Manually create analytics with old timestamps.
		$analytics_data = array(
			$user_id => array(
				$now - ( 31 * DAY_IN_SECONDS ) => array(
					'tokens'   => 500,
					'requests' => 1,
					'context'  => array(),
				),
				$now                            => array(
					'tokens'   => 1000,
					'requests' => 1,
					'context'  => array(),
				),
			),
		);

		update_option( 'wp_mcp_ai_token_analytics', $analytics_data );

		// Record new analytics to trigger cleanup.
		WP_MCP_AI_Token_Budget_Manager::record_analytics( $user_id, 200, array() );

		$updated_analytics = get_option( 'wp_mcp_ai_token_analytics', array() );

		// Should not contain the 31-day-old entry.
		$this->assertArrayNotHasKey( $now - ( 31 * DAY_IN_SECONDS ), $updated_analytics[ $user_id ] );
		// Should contain recent entries.
		$this->assertGreaterThanOrEqual( 2, count( $updated_analytics[ $user_id ] ) );
	}

	/**
	 * Test SIEM integration in budget check.
	 */
	public function test_budget_check_without_siem() {
		$user_id      = $this->factory->user->create();
		$assistant_id = $this->factory->post->create(
			array(
				'post_type' => 'mcp_ai_assistant',
			)
		);

		// Set budget limit.
		update_post_meta( $assistant_id, '_wp_mcp_ai_token_budget', 10000 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		// Should work fine even without SIEM class loaded.
		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages, $options );

		$this->assertTrue( $result );
	}

	/**
	 * Test forecast different time periods.
	 */
	public function test_forecast_different_periods() {
		$user_id = 1;

		// Record data.
		for ( $i = 0; $i < 50; $i++ ) {
			WP_MCP_AI_Token_Budget_Manager::record_analytics( $user_id, 1000, array() );
		}

		$forecast_1h  = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '1h' );
		$forecast_24h = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '24h' );
		$forecast_7d  = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '7d' );

		// 24h forecast should be higher than 1h forecast.
		$this->assertGreaterThan( $forecast_1h['forecasted_tokens'], $forecast_24h['forecasted_tokens'] );
		// 7d forecast should be higher than 24h forecast.
		$this->assertGreaterThan( $forecast_24h['forecasted_tokens'], $forecast_7d['forecasted_tokens'] );
	}
}
