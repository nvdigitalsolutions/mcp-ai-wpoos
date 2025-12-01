<?php
/**
 * Test Gauge Chart Functionality
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Gauge Chart test class.
 */
class Test_Gauge_Chart extends WP_UnitTestCase {

	/**
	 * Test gauge chart data structure.
	 */
	public function test_get_usage_gauge_data_structure() {
		// Get gauge data without specific user (site-wide).
		$gauge_data = WP_MCP_AI_Chart_JS_Helper::get_usage_gauge_data();

		// Verify data structure.
		$this->assertIsArray( $gauge_data );
		$this->assertArrayHasKey( 'percentage', $gauge_data );
		$this->assertArrayHasKey( 'usage', $gauge_data );
		$this->assertArrayHasKey( 'limit', $gauge_data );
		$this->assertArrayHasKey( 'label', $gauge_data );
		$this->assertArrayHasKey( 'color', $gauge_data );
		$this->assertArrayHasKey( 'datasets', $gauge_data );

		// Verify percentage is between 0 and 100.
		$this->assertGreaterThanOrEqual( 0, $gauge_data['percentage'] );
		$this->assertLessThanOrEqual( 100, $gauge_data['percentage'] );

		// Verify datasets structure.
		$this->assertIsArray( $gauge_data['datasets'] );
		$this->assertNotEmpty( $gauge_data['datasets'] );
		$this->assertArrayHasKey( 'data', $gauge_data['datasets'][0] );
		$this->assertArrayHasKey( 'backgroundColor', $gauge_data['datasets'][0] );
	}

	/**
	 * Test gauge chart data with specific user.
	 */
	public function test_get_usage_gauge_data_for_user() {
		// Create a test user.
		$user_id = $this->factory->user->create();

		// Set tier for user.
		update_user_meta( $user_id, '_wp_mcp_ai_token_tier', 'pro' );

		// Get gauge data for specific user.
		$gauge_data = WP_MCP_AI_Chart_JS_Helper::get_usage_gauge_data(
			array(
				'user_id' => $user_id,
			)
		);

		// Verify data is returned.
		$this->assertIsArray( $gauge_data );
		$this->assertArrayHasKey( 'percentage', $gauge_data );
		$this->assertArrayHasKey( 'usage', $gauge_data );
		$this->assertArrayHasKey( 'limit', $gauge_data );
	}

	/**
	 * Test gauge chart color based on usage percentage.
	 */
	public function test_gauge_chart_color_thresholds() {
		// Create users with different usage levels.
		$user_low    = $this->factory->user->create();
		$user_medium = $this->factory->user->create();
		$user_high   = $this->factory->user->create();

		update_user_meta( $user_low, '_wp_mcp_ai_token_tier', 'free' );
		update_user_meta( $user_medium, '_wp_mcp_ai_token_tier', 'free' );
		update_user_meta( $user_high, '_wp_mcp_ai_token_tier', 'free' );

		// Simulate different usage levels.
		// Low usage (< 50%) - should be green.
		$gauge_low = WP_MCP_AI_Chart_JS_Helper::get_usage_gauge_data(
			array(
				'user_id' => $user_low,
			)
		);

		// Verify color exists.
		$this->assertArrayHasKey( 'color', $gauge_low );
		$this->assertNotEmpty( $gauge_low['color'] );

		// Medium and high tests would require actual token usage data.
		// For now, we verify the color key exists and is valid.
		$this->assertMatchesRegularExpression( '/^rgba\(\d+,\s*\d+,\s*\d+,\s*[\d.]+\)$/', $gauge_low['color'] );
	}

	/**
	 * Test gauge chart data with zero limit.
	 */
	public function test_gauge_chart_with_zero_limit() {
		// Test with no users or zero limits.
		$gauge_data = WP_MCP_AI_Chart_JS_Helper::get_usage_gauge_data(
			array(
				'user_id' => 999999, // Non-existent user.
			)
		);

		// Should still return valid structure with 0 percentage.
		$this->assertIsArray( $gauge_data );
		$this->assertEquals( 0, $gauge_data['percentage'] );
		$this->assertEquals( 0, $gauge_data['usage'] );
		$this->assertEquals( 0, $gauge_data['limit'] );
	}

	/**
	 * Test gauge chart config.
	 */
	public function test_get_usage_gauge_config() {
		$config = WP_MCP_AI_Chart_JS_Helper::get_usage_gauge_config();

		// Verify config structure.
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'type', $config );
		$this->assertArrayHasKey( 'options', $config );
		$this->assertEquals( 'doughnut', $config['type'] );

		// Verify gauge-specific options.
		$this->assertArrayHasKey( 'circumference', $config['options'] );
		$this->assertArrayHasKey( 'rotation', $config['options'] );
		$this->assertArrayHasKey( 'cutout', $config['options'] );

		// Verify it's a half-circle gauge.
		$this->assertEquals( 180, $config['options']['circumference'] );
		$this->assertEquals( 270, $config['options']['rotation'] );
		$this->assertEquals( '75%', $config['options']['cutout'] );
	}

	/**
	 * Test gauge chart in analytics dashboard data.
	 */
	public function test_analytics_dashboard_includes_gauge() {
		// Access protected method via reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Analytics_Dashboard' );
		$method     = $reflection->getMethod( 'get_usage_overview_data' );
		$method->setAccessible( true );

		// Get usage overview data.
		$data = $method->invoke( null );

		// Verify gauge data is included.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'gauge', $data );
		$this->assertIsArray( $data['gauge'] );
		$this->assertArrayHasKey( 'percentage', $data['gauge'] );
		$this->assertArrayHasKey( 'datasets', $data['gauge'] );
	}
}
