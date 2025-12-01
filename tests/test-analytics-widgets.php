<?php
/**
 * Test Analytics Widgets
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for analytics widget templates.
 */
class Test_Analytics_Widgets extends WP_UnitTestCase {

	/**
	 * Test analytics trends widget rendering.
	 */
	public function test_analytics_trends_widget_renders_without_data() {
		// Ensure Analytics Engine is available.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Analytics_Engine' ), 'Analytics Engine class should exist' );

		// Start output buffering.
		ob_start();

		// Set data for widget.
		$data = array(
			'user_id' => 1,
			'days'    => 30,
		);

		// Include the widget template.
		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-trends.php';

		$output = ob_get_clean();

		// Check that output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-widget-analytics-trends', $output, 'Widget container should exist' );
	}

	/**
	 * Test analytics patterns widget rendering.
	 */
	public function test_analytics_patterns_widget_renders() {
		// Ensure Analytics Engine is available.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Analytics_Engine' ), 'Analytics Engine class should exist' );

		// Start output buffering.
		ob_start();

		// Set data for widget.
		$data = array(
			'user_id' => 1,
		);

		// Include the widget template.
		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-patterns.php';

		$output = ob_get_clean();

		// Check that output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-widget-analytics-patterns', $output, 'Widget container should exist' );
	}

	/**
	 * Test analytics anomalies widget rendering.
	 */
	public function test_analytics_anomalies_widget_renders() {
		// Ensure Analytics Engine is available.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Analytics_Engine' ), 'Analytics Engine class should exist' );

		// Start output buffering.
		ob_start();

		// Set data for widget.
		$data = array(
			'user_id'   => 0,
			'threshold' => 3.0,
		);

		// Include the widget template.
		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-anomalies.php';

		$output = ob_get_clean();

		// Check that output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-widget-analytics-anomalies', $output, 'Widget container should exist' );
	}

	/**
	 * Test analytics trends widget shows placeholder when no data.
	 */
	public function test_analytics_trends_shows_placeholder_for_new_user() {
		// Create a new user with no usage data.
		$user_id = $this->factory->user->create();

		// Start output buffering.
		ob_start();

		$data = array(
			'user_id' => $user_id,
			'days'    => 30,
		);

		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-trends.php';

		$output = ob_get_clean();

		// Should show placeholder message.
		$this->assertStringContainsString( 'Usage is stable. No action required.', $output, 'Should show stable usage message' );
		$this->assertStringContainsString( 'Advanced forecasting is currently being implemented', $output, 'Should show implementation notice' );
	}

	/**
	 * Test analytics anomalies widget shows no anomalies message.
	 */
	public function test_analytics_anomalies_shows_no_anomalies_message() {
		// Create a user with no anomalies.
		$user_id = $this->factory->user->create();

		ob_start();

		$data = array(
			'user_id'   => $user_id,
			'threshold' => 3.0,
		);

		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-anomalies.php';

		$output = ob_get_clean();

		// Should show no anomalies message.
		$this->assertStringContainsString( 'No Anomalies Detected', $output, 'Should show no anomalies message' );
	}

	/**
	 * Test analytics dashboard widgets registration.
	 */
	public function test_analytics_dashboard_registers_widgets() {
		// Set up user with manage_options capability.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Trigger widget registration.
		do_action( 'wp_dashboard_setup' );

		// Check global wp_meta_boxes for dashboard widgets.
		global $wp_meta_boxes;

		// Core dashboard widgets should be registered.
		$this->assertArrayHasKey( 'dashboard', $wp_meta_boxes, 'Dashboard meta boxes should exist' );

		// Analytics widgets should be registered if Analytics Engine is available.
		if ( class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			$this->assertArrayHasKey( 'wp_mcp_ai_analytics_trends', $wp_meta_boxes['dashboard']['normal']['core'], 'Trends widget should be registered' );
			$this->assertArrayHasKey( 'wp_mcp_ai_analytics_patterns', $wp_meta_boxes['dashboard']['normal']['core'], 'Patterns widget should be registered' );
			$this->assertArrayHasKey( 'wp_mcp_ai_analytics_anomalies', $wp_meta_boxes['dashboard']['normal']['core'], 'Anomalies widget should be registered' );
		}
	}

	/**
	 * Test that cost breakdown widget shows correct message.
	 */
	public function test_cost_breakdown_widget_shows_no_usage_message() {
		ob_start();

		// Data with no token usage.
		$data = array(
			'total_cost'   => 0.0,
			'by_provider'  => array(),
			'period_start' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			'period_end'   => gmdate( 'Y-m-d' ),
		);

		include WP_MCP_AI_PATH . 'includes/admin/widgets/cost-breakdown.php';

		$output = ob_get_clean();

		// Check for the no usage message.
		$this->assertStringContainsString( 'No token usage recorded in this period.', $output, 'Should show no usage message when cost is 0' );
	}

	/**
	 * Test that usage forecast widget shows correct message for stable trend.
	 */
	public function test_usage_forecast_widget_shows_stable_message() {
		ob_start();

		// Data with stable trend.
		$data = array(
			'projected_usage' => 1000,
			'projected_date'  => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'confidence'      => 75,
			'trend'           => 'stable',
		);

		include WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php';

		$output = ob_get_clean();

		// Check for the stable trend message.
		$this->assertStringContainsString( 'Usage is stable. No action required.', $output, 'Should show stable usage message' );
	}

	/**
	 * Test that usage forecast widget hides forecast value when no data is available.
	 */
	public function test_usage_forecast_widget_hides_zero_when_no_data() {
		ob_start();

		// Data with no forecast (default values).
		$data = array(
			'projected_usage' => 0,
			'projected_date'  => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'confidence'      => 0,
			'trend'           => 'stable',
		);

		include WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php';

		$output = ob_get_clean();

		// Should NOT show the forecast summary section with "0".
		$this->assertStringNotContainsString( 'wp-mcp-ai-forecast-summary', $output, 'Should not show forecast summary when no data' );
		$this->assertStringNotContainsString( 'wp-mcp-ai-forecast-amount', $output, 'Should not show forecast amount when no data' );

		// Should still show trend indicator and implementation notice.
		$this->assertStringContainsString( 'Usage is stable. No action required.', $output, 'Should show stable usage message' );
		$this->assertStringContainsString( 'Advanced forecasting is currently being implemented', $output, 'Should show implementation notice' );
		$this->assertStringContainsString( 'wp-mcp-ai-forecast-trend', $output, 'Should show trend indicator section' );
	}

	/**
	 * Test that usage forecast widget shows forecast value when data is available.
	 */
	public function test_usage_forecast_widget_shows_value_with_data() {
		ob_start();

		// Data with forecast.
		$data = array(
			'projected_usage' => 5000,
			'projected_date'  => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'confidence'      => 85,
			'trend'           => 'increasing',
		);

		include WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php';

		$output = ob_get_clean();

		// Should show the forecast summary section with value.
		$this->assertStringContainsString( 'wp-mcp-ai-forecast-summary', $output, 'Should show forecast summary when data available' );
		$this->assertStringContainsString( 'wp-mcp-ai-forecast-amount', $output, 'Should show forecast amount when data available' );
		$this->assertStringContainsString( '5,000', $output, 'Should display formatted projected usage' );
		$this->assertStringContainsString( 'Confidence: 85%', $output, 'Should show confidence percentage' );

		// Should show appropriate trend message.
		$this->assertStringContainsString( 'Usage is trending upward', $output, 'Should show increasing trend message' );

		// Should NOT show implementation notice when data is available.
		$this->assertStringNotContainsString( 'Advanced forecasting is currently being implemented', $output, 'Should not show implementation notice when data is available' );
	}
}
