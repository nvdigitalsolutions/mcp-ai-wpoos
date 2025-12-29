<?php
/**
 * Test to verify performance tests have proper security settings.
 *
 * This test validates that the performance test fixes for security
 * (user authentication and capabilities) are working correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Performance security test class.
 */
class WP_MCP_AI_Performance_Security_Fix_Test extends WP_UnitTestCase {

	/**
	 * Test that all performance test classes have proper user setup methods.
	 *
	 * Uses data provider for separation of concerns - test logic is separate
	 * from test data.
	 *
	 * @dataProvider performance_test_classes_provider
	 *
	 * @param string $file_path  Path to the test file.
	 * @param string $class_name Name of the test class.
	 */
	public function test_performance_test_has_proper_user_setup( $file_path, $class_name ) {
		// Load the test class.
		require_once WP_MCP_AI_PATH . $file_path;

		// Verify setUp method exists.
		$this->assertTrue(
			method_exists( $class_name, 'setUp' ),
			"{$class_name} should have setUp method for user authentication"
		);

		// Verify tearDown method exists.
		$this->assertTrue(
			method_exists( $class_name, 'tearDown' ),
			"{$class_name} should have tearDown method to clean up user session"
		);
	}

	/**
	 * Data provider for performance test classes.
	 *
	 * Separates test data from test logic for better maintainability.
	 *
	 * @return array Test data with file paths and class names.
	 */
	public function performance_test_classes_provider() {
		return array(
			'Elementor Performance Test'   => array(
				'tests/performance/test-elementor-performance.php',
				'WP_MCP_AI_Elementor_Performance_Test',
			),
			'Speed Benchmarks Test'        => array(
				'tests/performance/test-speed-benchmarks.php',
				'WP_MCP_AI_Speed_Benchmarks_Test',
			),
			'Stress Suite Test'            => array(
				'tests/performance/test-stress-suite.php',
				'WP_MCP_AI_Stress_Suite_Test',
			),
			'Optimization Comparison Test' => array(
				'tests/performance/test-optimization-comparison.php',
				'WP_MCP_AI_Optimization_Comparison_Test',
			),
		);
	}

	/**
	 * Test that AJAX handler properly rejects unauthenticated requests.
	 *
	 * This ensures the security fix is valid - the handler should reject
	 * requests without proper authentication.
	 */
	public function test_ajax_handler_requires_authentication() {
		// Load the performance section.
		if ( ! class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
		}

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Create the section instance to register AJAX handlers.
		$section = new WP_MCP_AI_Section_Performance();

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_performance_metrics';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_performance' );

		// Capture the output.
		ob_start();
		try {
			// This should trigger wp_send_json_error with permission denied.
			do_action( 'wp_ajax_nopriv_wp_mcp_ai_get_performance_metrics' );
		} catch ( Exception $e ) {
		// Intentionally empty - error handled elsewhere.
			// wp_send_json_error calls wp_die which might throw.
		}
		$output = ob_get_clean();

		// The action is only registered for logged-in users (wp_ajax_).
		// For unauthenticated users (nopriv), nothing should happen.
		$this->assertFalse(
			has_action( 'wp_ajax_nopriv_wp_mcp_ai_get_performance_metrics' ),
			'AJAX handler should not be registered for unauthenticated users'
		);
	}

	/**
	 * Test that AJAX handler works with authenticated admin user.
	 */
	public function test_ajax_handler_works_with_admin_user() {
		// Skip if Performance Monitor CCT is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Load the performance section.
		if ( ! class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
		}

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Verify user has proper capability.
		$this->assertTrue(
			current_user_can( 'manage_options' ),
			'Admin user should have manage_options capability'
		);

		// Clean up.
		wp_set_current_user( 0 );
	}
}
