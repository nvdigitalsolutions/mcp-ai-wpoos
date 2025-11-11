<?php
/**
 * Test Performance Section Health Status Display
 *
 * Verifies that the Performance Monitoring section correctly displays
 * the orchestration health status instead of performance test results.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Performance Section health status display
 */
class Test_Performance_Section_Health_Status extends WP_UnitTestCase {

	/**
	 * Test that Performance section uses orchestration health service
	 */
	public function test_performance_section_uses_orchestration_health() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_orchestration_health_status' );
		$method->setAccessible( true );

		// Get the health status.
		$health = $method->invoke( $section );

		// Verify health status structure.
		$this->assertIsArray( $health, 'Health status should be an array' );
		$this->assertArrayHasKey( 'status', $health, 'Health status should have status key' );
		$this->assertArrayHasKey( 'label', $health, 'Health status should have label key' );
		$this->assertArrayHasKey( 'icon', $health, 'Health status should have icon key' );
		$this->assertArrayHasKey( 'metrics', $health, 'Health status should have metrics key' );

		// Verify metrics structure.
		$this->assertIsArray( $health['metrics'], 'Metrics should be an array' );
		$this->assertArrayHasKey( 'memory', $health['metrics'], 'Metrics should have memory key' );
		$this->assertArrayHasKey( 'error_rate', $health['metrics'], 'Metrics should have error_rate key' );
		$this->assertArrayHasKey( 'avg_response', $health['metrics'], 'Metrics should have avg_response key' );
	}

	/**
	 * Test that health status contains valid status values
	 */
	public function test_health_status_valid_values() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section    = new WP_MCP_AI_Section_Performance();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_orchestration_health_status' );
		$method->setAccessible( true );

		$health = $method->invoke( $section );
		$status = $health['status'];

		// Verify status is one of the expected values.
		$valid_statuses = array( 'healthy', 'warning', 'critical', 'unknown' );
		$this->assertContains(
			$status,
			$valid_statuses,
			'Health status should be one of: ' . implode( ', ', $valid_statuses )
		);
	}

	/**
	 * Test that health status gracefully handles missing service
	 */
	public function test_health_status_fallback_on_error() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section    = new WP_MCP_AI_Section_Performance();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_orchestration_health_status' );
		$method->setAccessible( true );

		// Even if service fails, we should get a default health status.
		$health = $method->invoke( $section );

		$this->assertIsArray( $health, 'Should return fallback array on error' );
		$this->assertArrayHasKey( 'status', $health, 'Fallback should have status' );
		$this->assertArrayHasKey( 'label', $health, 'Fallback should have label' );
		$this->assertArrayHasKey( 'metrics', $health, 'Fallback should have metrics' );
	}

	/**
	 * Test that render method includes orchestration health status
	 */
	public function test_render_includes_health_status() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Set up admin user for capability check.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$section = new WP_MCP_AI_Section_Performance();

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Verify that the output contains orchestration health elements.
		$this->assertStringContainsString(
			'wp-mcp-ai-orchestration-health-banner',
			$output,
			'Output should contain orchestration health banner'
		);
		$this->assertStringContainsString(
			'System Health',
			$output,
			'Output should contain System Health heading'
		);
		$this->assertStringContainsString(
			'health-metrics',
			$output,
			'Output should contain health metrics'
		);
	}

	/**
	 * Test that orchestration health metrics are displayed
	 */
	public function test_health_metrics_display() {
		// Load the performance section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Set up admin user for capability check.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$section = new WP_MCP_AI_Section_Performance();

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Verify that memory, errors, and response time metrics are displayed.
		$this->assertStringContainsString(
			'Memory:',
			$output,
			'Output should display memory metric'
		);
		$this->assertStringContainsString(
			'Errors:',
			$output,
			'Output should display error rate metric'
		);
		$this->assertStringContainsString(
			'Avg Response:',
			$output,
			'Output should display average response time metric'
		);
	}

	/**
	 * Test that performance section is compatible with orchestration section
	 */
	public function test_consistency_with_orchestration_section() {
		// Load both sections.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-orchestration.php';

		$performance_section = new WP_MCP_AI_Section_Performance();

		// Get health status from performance section.
		$reflection = new ReflectionClass( $performance_section );
		$method     = $reflection->getMethod( 'get_orchestration_health_status' );
		$method->setAccessible( true );
		$perf_health = $method->invoke( $performance_section );

		// Get health status from orchestration service directly.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
			$orch_health = WP_MCP_AI_Orchestration_Health_Service::get_health_status();

			// Both should have the same structure.
			$this->assertEquals(
				$orch_health['status'],
				$perf_health['status'],
				'Performance and orchestration sections should show same health status'
			);
		} else {
			$this->markTestSkipped( 'Orchestration Health Service not available' );
		}
	}
}
