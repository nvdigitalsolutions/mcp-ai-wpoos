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
}
