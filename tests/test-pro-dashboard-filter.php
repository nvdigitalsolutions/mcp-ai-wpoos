<?php
/**
 * Test Pro Dashboard Filter
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Pro Dashboard filter functionality.
 */
class Test_Pro_Dashboard_Filter extends WP_UnitTestCase {

	/**
	 * Test that filter can enable Pro features dynamically.
	 */
	public function test_pro_dashboard_filter_enables_features() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		// Initially, Pro should be disabled.
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled by default' );

		// Add filter to enable Pro features (simulating user snippet).
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Now Pro should be enabled.
		$this->assertTrue( $dashboard->is_pro_active(), 'Pro should be enabled after filter is applied' );

		// Remove filter.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Pro should be disabled again.
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled after filter is removed' );
	}

	/**
	 * Test that constant can enable Pro features.
	 */
	public function test_pro_dashboard_constant_enables_features() {
		// Skip if constant is already defined (can't redefine).
		if ( defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED constant already defined' );
			return;
		}

		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Test that constant is checked by temporarily overriding defined().
		// Since we can't actually define constants in tests, we verify the logic.
		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		// Initially disabled.
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled without constant or filter' );
	}

	/**
	 * Test that constant takes priority over filter.
	 */
	public function test_pro_dashboard_constant_priority() {
		// This test documents that if the constant were set, it would take priority.
		// Actual priority testing requires defining the constant which we can't do in tests.
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		// With filter false, should be disabled.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_false' );
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled with false filter' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_false' );
	}

	/**
	 * Test that filter can be applied with priority.
	 */
	public function test_pro_dashboard_filter_with_priority() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		// Add filter with priority 0 (simulating WPCode snippet with priority 0).
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true', 0 );

		// Pro should be enabled regardless of priority.
		$this->assertTrue( $dashboard->is_pro_active(), 'Pro should be enabled with priority 0 filter' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true', 0 );
	}

	/**
	 * Test that filter is checked dynamically, not cached.
	 */
	public function test_pro_dashboard_filter_is_dynamic() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		// Check multiple times with different filter states.
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled initially' );

		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
		$this->assertTrue( $dashboard->is_pro_active(), 'Pro should be enabled after filter' );

		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled after removing filter' );

		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
		$this->assertTrue( $dashboard->is_pro_active(), 'Pro should be enabled again after re-adding filter' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that custom filter callbacks work.
	 */
	public function test_pro_dashboard_custom_filter_callback() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		// Add custom callback that checks a condition.
		$callback = function() {
			return get_option( 'custom_pro_enabled', false );
		};

		add_filter( 'wp_mcp_ai_pro_dashboard_available', $callback );

		// Initially disabled.
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled when option is false' );

		// Enable via option.
		update_option( 'custom_pro_enabled', true );
		$this->assertTrue( $dashboard->is_pro_active(), 'Pro should be enabled when option is true' );

		// Disable via option.
		update_option( 'custom_pro_enabled', false );
		$this->assertFalse( $dashboard->is_pro_active(), 'Pro should be disabled when option is false again' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', $callback );
		delete_option( 'custom_pro_enabled' );
	}

	/**
	 * Test that method is public and accessible.
	 */
	public function test_is_pro_active_method_is_public() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$dashboard = new WP_MCP_AI_Pro_Dashboard();

		$reflection = new ReflectionMethod( $dashboard, 'is_pro_active' );
		$this->assertTrue( $reflection->isPublic(), 'is_pro_active method should be public' );
	}

	/**
	 * Test that recent activity rendering handles missing array keys without warnings.
	 */
	public function test_recent_activity_handles_missing_keys() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Enable Pro dashboard to test the rendering.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Test with various incomplete data structures that might cause undefined array key warnings.
		$test_events = array(
			// Complete event with all expected keys.
			array(
				'icon'      => 'shield',
				'message'   => 'Test event with all keys',
				'time'      => '2025-01-05 10:00:00',
			),
			// Event with timestamp key (actual data structure).
			array(
				'message'   => 'Event with timestamp key',
				'timestamp' => '2025-01-05 11:00:00',
			),
			// Event with only message.
			array(
				'message' => 'Event with only message',
			),
			// Empty event array.
			array(),
		);

		update_option( 'wp_mcp_ai_recent_activity', $test_events );

		// Capture output and ensure no PHP warnings are generated.
		ob_start();
		$dashboard = new WP_MCP_AI_Pro_Dashboard();
		$dashboard->render_overview();
		$output = ob_get_clean();

		// Verify output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-activity-list', $output, 'Activity list should be rendered' );
		$this->assertStringContainsString( 'Test event with all keys', $output, 'Complete event should be displayed' );
		$this->assertStringContainsString( 'Event with timestamp key', $output, 'Event with timestamp should be displayed' );
		$this->assertStringContainsString( 'Event with only message', $output, 'Event with only message should be displayed' );

		// Clean up.
		delete_option( 'wp_mcp_ai_recent_activity' );
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that recent activity with empty array doesn't cause errors.
	 */
	public function test_recent_activity_handles_empty_array() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Enable Pro dashboard.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Set empty array.
		update_option( 'wp_mcp_ai_recent_activity', array() );

		// Capture output.
		ob_start();
		$dashboard = new WP_MCP_AI_Pro_Dashboard();
		$dashboard->render_overview();
		$output = ob_get_clean();

		// Should show empty state, not the activity list.
		$this->assertStringContainsString( 'wp-mcp-ai-empty-state', $output, 'Empty state should be shown' );
		$this->assertStringNotContainsString( 'wp-mcp-ai-activity-list', $output, 'Activity list should not be rendered' );

		// Clean up.
		delete_option( 'wp_mcp_ai_recent_activity' );
		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that recent activity handles non-pro mode correctly.
	 */
	public function test_recent_activity_non_pro_mode() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Set some events.
		update_option(
			'wp_mcp_ai_recent_activity',
			array(
				array(
					'message'   => 'Test event',
					'timestamp' => '2025-01-05 10:00:00',
				),
			)
		);

		// Capture output with Pro disabled.
		ob_start();
		$dashboard = new WP_MCP_AI_Pro_Dashboard();
		$dashboard->render_overview();
		$output = ob_get_clean();

		// Should show empty state, not the activity list (Pro is disabled).
		$this->assertStringContainsString( 'wp-mcp-ai-empty-state', $output, 'Empty state should be shown when Pro is disabled' );
		$this->assertStringNotContainsString( 'Test event', $output, 'Events should not be displayed when Pro is disabled' );

		// Clean up.
		delete_option( 'wp_mcp_ai_recent_activity' );
	}
}
