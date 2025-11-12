<?php
/**
 * Test Performance Section AJAX Configuration
 *
 * Verifies that the Performance Monitoring section correctly configures
 * AJAX handlers and localized script data for performance tests.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Performance Section AJAX configuration
 */
class Test_Performance_Section_AJAX extends WP_UnitTestCase {

	/**
	 * Test that AJAX handler for running performance tests is registered
	 */
	public function test_ajax_run_performance_test_registered() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Verify AJAX action is registered.
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_run_performance_test' ) !== false,
			'AJAX action wp_ajax_wp_mcp_ai_run_performance_test should be registered'
		);
	}

	/**
	 * Test that AJAX handler for getting metrics is registered
	 */
	public function test_ajax_get_metrics_registered() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Verify AJAX action is registered.
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics' ) !== false,
			'AJAX action wp_ajax_wp_mcp_ai_get_performance_metrics should be registered'
		);
	}

	/**
	 * Test that AJAX handler for exporting results is registered
	 */
	public function test_ajax_export_results_registered() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Verify AJAX action is registered.
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_export_test_results' ) !== false,
			'AJAX action wp_ajax_wp_mcp_ai_export_test_results should be registered'
		);
	}

	/**
	 * Test that localized script data includes required properties
	 */
	public function test_localized_script_data_structure() {
		global $wp_scripts;

		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance and enqueue assets.
		$section = new WP_MCP_AI_Section_Performance();

		// Set up an admin context.
		set_current_screen( 'toplevel_page_wp-mcp-ai' );

		// Trigger the enqueue.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wp-mcp-ai' );

		// Check if script is enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-performance-admin', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-performance-admin', 'registered' ),
			'Performance admin script should be registered or enqueued'
		);

		// Check localized data.
		if ( isset( $wp_scripts->registered['wp-mcp-ai-performance-admin'] ) ) {
			$script = $wp_scripts->registered['wp-mcp-ai-performance-admin'];

			// Verify localized data exists.
			$this->assertNotEmpty(
				$script->extra,
				'Script should have extra data (localized scripts)'
			);

			if ( isset( $script->extra['data'] ) ) {
				$data = $script->extra['data'];

				// Check for required properties in the localized data.
				$this->assertStringContainsString(
					'wpMcpAiPerformance',
					$data,
					'Localized script should define wpMcpAiPerformance object'
				);
				$this->assertStringContainsString(
					'ajaxUrl',
					$data,
					'wpMcpAiPerformance should include ajaxUrl property'
				);
				$this->assertStringContainsString(
					'nonce',
					$data,
					'wpMcpAiPerformance should include nonce property'
				);
				$this->assertStringContainsString(
					'runningText',
					$data,
					'wpMcpAiPerformance should include runningText property'
				);
			}
		}
	}

	/**
	 * Test that AJAX run test requires proper nonce
	 */
	public function test_ajax_run_test_requires_nonce() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance.
		$section = new WP_MCP_AI_Section_Performance();

		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Try to call AJAX without nonce - should fail.
		$_POST['action']    = 'wp_mcp_ai_run_performance_test';
		$_POST['test_type'] = 'stress';

		// Expect this to die with nonce error.
		$this->expectException( 'WPAjaxDieContinueException' );

		$this->_handleAjax( 'wp_mcp_ai_run_performance_test' );
	}

	/**
	 * Test that AJAX run test requires manage_options capability
	 */
	public function test_ajax_run_test_requires_capability() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance.
		$section = new WP_MCP_AI_Section_Performance();

		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up request with valid nonce.
		$_POST['action']    = 'wp_mcp_ai_run_performance_test';
		$_POST['test_type'] = 'stress';
		$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_performance' );

		// Capture the AJAX response.
		try {
			$this->_handleAjax( 'wp_mcp_ai_run_performance_test' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - check the response.
		}

		// Get the JSON response.
		$response = $this->_last_response;

		// Should return error for lack of capability.
		$this->assertStringContainsString(
			'Permission denied',
			$response,
			'Should deny access for users without manage_options capability'
		);
	}
}
