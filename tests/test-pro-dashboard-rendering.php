<?php
/**
 * Test Pro Dashboard Rendering
 *
 * @package WP_MCP_AI
 */

/**
 * Test Pro Dashboard rendering functionality.
 */
class Test_Pro_Dashboard_Rendering extends WP_UnitTestCase {

	/**
	 * Pro Dashboard instance.
	 *
	 * @var WP_MCP_AI_Pro_Dashboard
	 */
	private $dashboard;

	/**
	 * Setup test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the Pro Dashboard class.
		require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-pro-dashboard.php';
		$this->dashboard = new WP_MCP_AI_Pro_Dashboard();

		// Clear any existing activity logs.
		delete_option( 'wp_mcp_ai_recent_activity' );
	}

	/**
	 * Teardown test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_recent_activity' );
		parent::tearDown();
	}

	/**
	 * Test that recent activity renders without warnings when events have timestamp field.
	 */
	public function test_recent_activity_renders_with_timestamp() {
		// Create activity log with timestamp field (as created by logger).
		$activity = array(
			array(
				'timestamp' => '2026-01-05 10:00:00',
				'type'      => 'info',
				'message'   => 'Test event 1',
			),
			array(
				'timestamp' => '2026-01-05 10:01:00',
				'type'      => 'warning',
				'message'   => 'Test event 2',
			),
		);

		update_option( 'wp_mcp_ai_recent_activity', $activity );

		// Enable Pro features.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Capture output.
		ob_start();
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'render_recent_activity' );
		$method->setAccessible( true );
		$method->invoke( $this->dashboard );
		$output = ob_get_clean();

		// Verify output contains timestamps and messages.
		$this->assertStringContainsString( '2026-01-05 10:00:00', $output );
		$this->assertStringContainsString( '2026-01-05 10:01:00', $output );
		$this->assertStringContainsString( 'Test event 1', $output );
		$this->assertStringContainsString( 'Test event 2', $output );

		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that recent activity handles missing timestamp gracefully.
	 */
	public function test_recent_activity_handles_missing_timestamp() {
		// Create activity log with missing timestamp field.
		$activity = array(
			array(
				'message' => 'Event without timestamp',
			),
		);

		update_option( 'wp_mcp_ai_recent_activity', $activity );

		// Enable Pro features.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Capture output - should not cause PHP warnings.
		ob_start();
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'render_recent_activity' );
		$method->setAccessible( true );
		$method->invoke( $this->dashboard );
		$output = ob_get_clean();

		// Verify output contains the message.
		$this->assertStringContainsString( 'Event without timestamp', $output );

		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}

	/**
	 * Test that controls table renders when Pro is active.
	 */
	public function test_controls_table_renders_when_pro_active() {
		// Enable Pro features.
		add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );

		// Capture output.
		ob_start();
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'render_controls_table' );
		$method->setAccessible( true );
		$method->invoke( $this->dashboard );
		$output = ob_get_clean();

		// Verify output contains controls table markup or data.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'Full interactive controls table', $output );

		remove_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
	}
}
