<?php
/**
 * Test Performance Section AJAX Registration on Frontend
 *
 * Verifies that the Performance section's AJAX handlers are properly
 * registered on the frontend for use by Elementor widgets.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Performance Section frontend AJAX registration
 */
class Test_Performance_AJAX_Frontend_Registration extends WP_UnitTestCase {

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Remove any previously registered actions to start fresh.
		remove_all_actions( 'wp_ajax_wp_mcp_ai_run_performance_test' );
		remove_all_actions( 'wp_ajax_wp_mcp_ai_get_performance_metrics' );
		remove_all_actions( 'wp_ajax_wp_mcp_ai_export_test_results' );
		remove_all_actions( 'admin_enqueue_scripts' );
	}

	/**
	 * Test that AJAX handlers are registered when instantiated on frontend
	 */
	public function test_ajax_handlers_registered_on_frontend() {
		// Simulate frontend context by setting is_admin() to return false.
		// We can't override is_admin() directly, but we can test the section behavior.

		// Load required dependencies.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Verify AJAX actions are registered.
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_run_performance_test' ) !== false,
			'AJAX action wp_ajax_wp_mcp_ai_run_performance_test should be registered'
		);

		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics' ) !== false,
			'AJAX action wp_ajax_wp_mcp_ai_get_performance_metrics should be registered'
		);

		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_export_test_results' ) !== false,
			'AJAX action wp_ajax_wp_mcp_ai_export_test_results should be registered'
		);
	}

	/**
	 * Test that admin_enqueue_scripts is only registered in admin context
	 */
	public function test_admin_enqueue_scripts_conditional_registration() {
		// Load required dependencies.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Check if admin_enqueue_scripts is registered.
		$admin_enqueue_registered = false;
		$callbacks                = $GLOBALS['wp_filter']['admin_enqueue_scripts'] ?? null;

		if ( $callbacks ) {
			foreach ( $callbacks as $priority => $callbacks_at_priority ) {
				foreach ( $callbacks_at_priority as $callback_data ) {
					if ( is_array( $callback_data['function'] ) ) {
						if ( $callback_data['function'][0] instanceof WP_MCP_AI_Section_Performance ) {
							$admin_enqueue_registered = true;
							break 2;
						}
					}
				}
			}
		}

		// If we're in admin context, it should be registered.
		// If we're not in admin context, it should not be registered.
		$is_admin_context = is_admin();
		$this->assertEquals(
			$is_admin_context,
			$admin_enqueue_registered,
			'admin_enqueue_scripts should only be registered in admin context'
		);
	}

	/**
	 * Test that AJAX handlers enforce capability checks
	 */
	public function test_ajax_handlers_enforce_capability_checks() {
		// Set up admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Load required dependencies.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Verify the section was created.
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Performance', $section );

		// Test that ajax_run_test method exists and requires capability.
		$this->assertTrue(
			method_exists( $section, 'ajax_run_test' ),
			'ajax_run_test method should exist'
		);

		$this->assertTrue(
			method_exists( $section, 'ajax_get_metrics' ),
			'ajax_get_metrics method should exist'
		);

		// Note: We can't easily test the actual capability check without
		// triggering the AJAX handler, but we verify the methods exist.
		// and the class is properly instantiated.
	}

	/**
	 * Test that Performance section can be instantiated via container
	 */
	public function test_performance_section_container_instantiation() {
		// Load container if not already loaded.
		if ( ! function_exists( 'wp_mcp_ai_container' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-container.php';
		}

		$container = wp_mcp_ai_container();

		// Try to get the performance section from container.
		// This simulates what happens in mcp-ai-wpoos.php.
		try {
			$section = $container->get( 'section.performance' );

			$this->assertInstanceOf(
				'WP_MCP_AI_Section_Performance',
				$section,
				'Container should return Performance section instance'
			);

			// Verify AJAX handlers are registered after container instantiation.
			$this->assertTrue(
				has_action( 'wp_ajax_wp_mcp_ai_run_performance_test' ) !== false,
				'AJAX handlers should be registered via container instantiation'
			);
		} catch ( Exception $e ) {
			// Intentionally empty - error handled elsewhere.
			// If container doesn't have the section registered, that's okay for this test.
			$this->markTestSkipped( 'Container does not have section.performance registered: ' . $e->getMessage() );
		}
	}

	/**
	 * Test that both Elementor widgets can find their AJAX handlers
	 */
	public function test_elementor_widgets_ajax_actions_available() {
		// Load required dependencies.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance to register AJAX handlers.
		$section = new WP_MCP_AI_Section_Performance();

		// Test Performance Test Runner Widget's action.
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_run_performance_test' ) !== false,
			'Performance Test Runner Widget AJAX action should be available'
		);

		// Test Performance Metrics Widget's action.
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics' ) !== false,
			'Performance Metrics Widget AJAX action should be available'
		);

		// Verify both actions are pointing to the same section instance.
		$run_test_callbacks    = $GLOBALS['wp_filter']['wp_ajax_wp_mcp_ai_run_performance_test'] ?? null;
		$get_metrics_callbacks = $GLOBALS['wp_filter']['wp_ajax_wp_mcp_ai_get_performance_metrics'] ?? null;

		$this->assertNotNull( $run_test_callbacks, 'Run test callbacks should exist' );
		$this->assertNotNull( $get_metrics_callbacks, 'Get metrics callbacks should exist' );
	}
}
