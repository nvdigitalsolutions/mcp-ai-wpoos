<?php
/**
 * Test Performance Buttons Fix
 *
 * Verifies that the performance monitoring JavaScript is correctly enqueued
 * on the advanced settings page with performance_monitoring subtab.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for performance buttons fix
 */
class Test_Performance_Buttons_Fix extends WP_UnitTestCase {

	/**
	 * Test that performance admin script is enqueued on correct page
	 */
	public function test_performance_script_enqueued_on_performance_monitoring_page() {
		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Simulate being on the performance monitoring page.
		$_GET['page']   = 'wp-mcp-ai-dashboard';
		$_GET['tab']    = 'advanced';
		$_GET['subtab'] = 'performance_monitoring';

		// Set current screen.
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Trigger enqueue scripts action.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Verify the performance admin script is enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-performance-admin', 'enqueued' ),
			'Performance admin script should be enqueued on performance monitoring page'
		);

		// Clean up.
		unset( $_GET['page'], $_GET['tab'], $_GET['subtab'] );
	}

	/**
	 * Test that performance admin script is NOT enqueued on other pages
	 */
	public function test_performance_script_not_enqueued_on_other_pages() {
		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Simulate being on a different page.
		$_GET['page'] = 'wp-mcp-ai-dashboard';
		$_GET['tab']  = 'general';

		// Set current screen.
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Trigger enqueue scripts action.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Verify the performance admin script is NOT enqueued.
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-performance-admin', 'enqueued' ),
			'Performance admin script should NOT be enqueued on other pages'
		);

		// Clean up.
		unset( $_GET['page'], $_GET['tab'] );
	}

	/**
	 * Test that localized script data includes required properties
	 */
	public function test_performance_script_localized_data() {
		global $wp_scripts;

		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Simulate being on the performance monitoring page.
		$_GET['page']   = 'wp-mcp-ai-dashboard';
		$_GET['tab']    = 'advanced';
		$_GET['subtab'] = 'performance_monitoring';

		// Set current screen.
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Trigger enqueue scripts action.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai-dashboard' );

		// Check if script is enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-performance-admin', 'enqueued' ),
			'Performance admin script should be enqueued'
		);

		// Get localized data.
		if ( isset( $wp_scripts->registered['wp-mcp-ai-performance-admin'] ) ) {
			$script = $wp_scripts->registered['wp-mcp-ai-performance-admin'];

			// Verify localized data exists.
			$this->assertNotEmpty(
				$script->extra,
				'Script should have localized data'
			);

			// Check for wpMcpAiPerformance object in extra data.
			$extra_data = implode( '', $script->extra );

			$this->assertStringContainsString(
				'wpMcpAiPerformance',
				$extra_data,
				'Localized data should include wpMcpAiPerformance object'
			);

			$this->assertStringContainsString(
				'ajaxUrl',
				$extra_data,
				'Localized data should include ajaxUrl'
			);

			$this->assertStringContainsString(
				'nonce',
				$extra_data,
				'Localized data should include nonce'
			);

			$this->assertStringContainsString(
				'runningText',
				$extra_data,
				'Localized data should include runningText'
			);
		}

		// Clean up.
		unset( $_GET['page'], $_GET['tab'], $_GET['subtab'] );
	}

	/**
	 * Test that AJAX handlers are registered when Pro addon loads
	 */
	public function test_ajax_handlers_registered() {
		// Check if Pro addon is available.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Verify AJAX handlers are registered.
		$this->assertGreaterThan(
			0,
			has_action( 'wp_ajax_wp_mcp_ai_run_performance_test' ),
			'AJAX handler for running performance tests should be registered'
		);

		$this->assertGreaterThan(
			0,
			has_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics' ),
			'AJAX handler for getting performance metrics should be registered'
		);

		$this->assertGreaterThan(
			0,
			has_action( 'wp_ajax_wp_mcp_ai_export_test_results' ),
			'AJAX handler for exporting test results should be registered'
		);
	}
}
