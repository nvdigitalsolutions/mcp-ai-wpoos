<?php
/**
 * Test Analytics Dashboard functionality
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Analytics Dashboard
 */
class Test_Analytics_Dashboard extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test admin user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Set current user.
		wp_set_current_user( $this->test_user_id );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that analytics dashboard class exists.
	 */
	public function test_analytics_dashboard_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Analytics_Dashboard' ), 'Analytics Dashboard class should exist' );
	}

	/**
	 * Test widget registration for admin user.
	 */
	public function test_widgets_register_for_admin() {
		global $wp_meta_boxes;

		// Reset dashboard widgets.
		$wp_meta_boxes['dashboard'] = array();

		// Trigger widget registration.
		do_action( 'wp_dashboard_setup' );

		// Check if widgets are registered.
		$this->assertArrayHasKey( 'dashboard', $wp_meta_boxes, 'Dashboard meta boxes should exist' );

		// Check for our specific widgets.
		$found_usage_overview = false;
		$found_cost_breakdown = false;
		$found_usage_forecast = false;

		foreach ( $wp_meta_boxes['dashboard'] as $context => $priority_boxes ) {
			foreach ( $priority_boxes as $priority => $widgets ) {
				if ( isset( $widgets['wp_mcp_ai_token_usage_overview'] ) ) {
					$found_usage_overview = true;
				}
				if ( isset( $widgets['wp_mcp_ai_cost_breakdown'] ) ) {
					$found_cost_breakdown = true;
				}
				if ( isset( $widgets['wp_mcp_ai_usage_forecast'] ) ) {
					$found_usage_forecast = true;
				}
			}
		}

		$this->assertTrue( $found_usage_overview, 'Token Usage Overview widget should be registered' );
		$this->assertTrue( $found_cost_breakdown, 'Cost Breakdown widget should be registered' );
		$this->assertTrue( $found_usage_forecast, 'Usage Forecast widget should be registered' );
	}

	/**
	 * Test widget templates exist.
	 */
	public function test_widget_templates_exist() {
		$templates = array(
			'token-usage-overview.php',
			'cost-breakdown.php',
			'usage-forecast.php',
		);

		foreach ( $templates as $template ) {
			$path = WP_MCP_AI_PATH . 'includes/admin/widgets/' . $template;
			$this->assertFileExists( $path, "Widget template {$template} should exist" );
		}
	}

	/**
	 * Test analytics assets are registered.
	 */
	public function test_analytics_assets_registered() {
		// Simulate dashboard page.
		set_current_screen( 'dashboard' );

		// Trigger asset enqueue.
		do_action( 'admin_enqueue_scripts', 'index.php' );

		// Check if analytics dashboard script is enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-analytics-dashboard', 'enqueued' ) ||
			wp_script_is( 'wp-mcp-ai-analytics-dashboard', 'registered' ),
			'Analytics dashboard JavaScript should be registered or enqueued'
		);

		// Check if analytics dashboard style is enqueued.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-analytics-dashboard', 'enqueued' ) ||
			wp_style_is( 'wp-mcp-ai-analytics-dashboard', 'registered' ),
			'Analytics dashboard CSS should be registered or enqueued'
		);
	}

	/**
	 * Test usage overview data structure.
	 */
	public function test_usage_overview_data_structure() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Analytics_Dashboard' );
		$method     = $reflection->getMethod( 'get_usage_overview_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null );

		// Verify data structure.
		$this->assertIsArray( $data, 'Usage overview data should be an array' );
		$this->assertArrayHasKey( 'trend', $data, 'Data should have trend key' );
		$this->assertArrayHasKey( 'tiers', $data, 'Data should have tiers key' );
		$this->assertArrayHasKey( 'current_stats', $data, 'Data should have current_stats key' );

		// Verify trend data structure.
		$this->assertIsArray( $data['trend'], 'Trend data should be an array' );
		$this->assertArrayHasKey( 'labels', $data['trend'], 'Trend should have labels' );
		$this->assertArrayHasKey( 'datasets', $data['trend'], 'Trend should have datasets' );

		// Verify tiers data structure.
		$this->assertIsArray( $data['tiers'], 'Tiers data should be an array' );
		$this->assertArrayHasKey( 'labels', $data['tiers'], 'Tiers should have labels' );
		$this->assertArrayHasKey( 'values', $data['tiers'], 'Tiers should have values' );

		// Verify current stats structure.
		$this->assertIsArray( $data['current_stats'], 'Current stats should be an array' );
		$this->assertArrayHasKey( 'today_tokens', $data['current_stats'] );
		$this->assertArrayHasKey( 'week_tokens', $data['current_stats'] );
		$this->assertArrayHasKey( 'month_tokens', $data['current_stats'] );
		$this->assertArrayHasKey( 'active_users', $data['current_stats'] );
	}

	/**
	 * Test cost breakdown data structure.
	 */
	public function test_cost_breakdown_data_structure() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Analytics_Dashboard' );
		$method     = $reflection->getMethod( 'get_cost_breakdown_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null );

		// Verify data structure.
		$this->assertIsArray( $data, 'Cost breakdown data should be an array' );
		$this->assertArrayHasKey( 'total_cost', $data );
		$this->assertArrayHasKey( 'by_provider', $data );
		$this->assertArrayHasKey( 'by_model', $data );
		$this->assertArrayHasKey( 'by_user', $data );
		$this->assertArrayHasKey( 'period_start', $data );
		$this->assertArrayHasKey( 'period_end', $data );

		// Verify total cost is numeric.
		$this->assertIsNumeric( $data['total_cost'], 'Total cost should be numeric' );
	}

	/**
	 * Test usage forecast data structure.
	 */
	public function test_usage_forecast_data_structure() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Analytics_Dashboard' );
		$method     = $reflection->getMethod( 'get_usage_forecast_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null );

		// Verify data structure.
		$this->assertIsArray( $data, 'Usage forecast data should be an array' );
		$this->assertArrayHasKey( 'projected_usage', $data );
		$this->assertArrayHasKey( 'projected_date', $data );
		$this->assertArrayHasKey( 'confidence', $data );
		$this->assertArrayHasKey( 'trend', $data );

		// Verify projected usage is numeric.
		$this->assertIsNumeric( $data['projected_usage'], 'Projected usage should be numeric' );

		// Verify confidence is numeric.
		$this->assertIsNumeric( $data['confidence'], 'Confidence should be numeric' );

		// Verify trend is a valid value.
		$this->assertContains(
			$data['trend'],
			array( 'increasing', 'decreasing', 'stable' ),
			'Trend should be one of: increasing, decreasing, stable'
		);
	}

	/**
	 * Test current usage stats calculation.
	 */
	public function test_current_usage_stats() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Analytics_Dashboard' );
		$method     = $reflection->getMethod( 'get_current_usage_stats' );
		$method->setAccessible( true );

		$stats = $method->invoke( null );

		// Verify stats structure.
		$this->assertIsArray( $stats, 'Stats should be an array' );
		$this->assertArrayHasKey( 'today_tokens', $stats );
		$this->assertArrayHasKey( 'week_tokens', $stats );
		$this->assertArrayHasKey( 'month_tokens', $stats );
		$this->assertArrayHasKey( 'active_users', $stats );
		$this->assertArrayHasKey( 'total_users', $stats );

		// Verify all values are non-negative integers.
		foreach ( $stats as $key => $value ) {
			$this->assertIsInt( $value, "{$key} should be an integer" );
			$this->assertGreaterThanOrEqual( 0, $value, "{$key} should be non-negative" );
		}
	}

	/**
	 * Test widget rendering doesn't produce PHP errors.
	 */
	public function test_widget_rendering_no_errors() {
		// Buffer output to prevent display during test.
		ob_start();

		try {
			// Test usage overview widget.
			$reflection = new ReflectionClass( 'WP_MCP_AI_Analytics_Dashboard' );
			$method     = $reflection->getMethod( 'render_usage_overview_widget' );
			$method->setAccessible( true );
			$method->invoke( null );

			// Test cost breakdown widget.
			$method = $reflection->getMethod( 'render_cost_breakdown_widget' );
			$method->setAccessible( true );
			$method->invoke( null );

			// Test usage forecast widget.
			$method = $reflection->getMethod( 'render_usage_forecast_widget' );
			$method->setAccessible( true );
			$method->invoke( null );

			$output = ob_get_clean();

			// If we got here without exception, rendering was successful.
			$this->assertTrue( true, 'Widget rendering should not produce errors' );

			// Verify some output was generated.
			$this->assertNotEmpty( $output, 'Widget rendering should produce output' );
		} catch ( Exception $e ) {
			ob_end_clean();
			$this->fail( 'Widget rendering produced an error: ' . $e->getMessage() );
		}
	}
}
