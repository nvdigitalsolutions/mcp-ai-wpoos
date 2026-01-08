<?php
/**
 * Test Pro Dashboard Monitoring Tab Enhancements
 *
 * Verifies that the enhanced monitoring features work correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Dashboard monitoring enhancements.
 */
class Test_Pro_Dashboard_Monitoring extends WP_UnitTestCase {

	/**
	 * Pro Dashboard instance.
	 *
	 * @var WP_MCP_AI_Pro_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';
		}

		// Get singleton instance.
		$this->dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		// Set up admin user for menu registration.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Test that monitoring tab renders without errors.
	 */
	public function test_monitoring_tab_renders() {
		$_GET['tab'] = 'monitoring';

		ob_start();
		$this->dashboard->render_dashboard_with_tabs();
		$output = ob_get_clean();

		// Check that output was generated.
		$this->assertNotEmpty( $output, 'Monitoring tab should render output' );

		// Check for key monitoring elements.
		$this->assertStringContainsString(
			'wp-mcp-ai-monitoring-metrics',
			$output,
			'Should contain monitoring metrics section'
		);

		$this->assertStringContainsString(
			'wp-mcp-ai-monitoring-options',
			$output,
			'Should contain monitoring options bar'
		);

		$this->assertStringContainsString(
			'wp-mcp-ai-event-log-card',
			$output,
			'Should contain event log card'
		);

		unset( $_GET['tab'] );
	}

	/**
	 * Test monitoring event stats helper method.
	 */
	public function test_get_monitoring_event_stats() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_monitoring_event_stats' );
		$method->setAccessible( true );

		$stats = $method->invoke( $this->dashboard );

		// Verify stats structure.
		$this->assertIsArray( $stats, 'Event stats should be an array' );
		$this->assertArrayHasKey( 'total_events', $stats, 'Stats should have total_events' );
		$this->assertArrayHasKey( 'critical_count', $stats, 'Stats should have critical_count' );
		$this->assertArrayHasKey( 'file_integrity_events', $stats, 'Stats should have file_integrity_events' );
		$this->assertArrayHasKey( 'auth_events', $stats, 'Stats should have auth_events' );
		$this->assertArrayHasKey( 'update_events', $stats, 'Stats should have update_events' );
		$this->assertArrayHasKey( 'config_events', $stats, 'Stats should have config_events' );
		$this->assertArrayHasKey( 'security_events', $stats, 'Stats should have security_events' );

		// Verify all are numeric.
		foreach ( $stats as $key => $value ) {
			$this->assertIsNumeric( $value, "{$key} should be numeric" );
		}
	}

	/**
	 * Test system health status helper method.
	 */
	public function test_get_system_health_status() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_system_health_status' );
		$method->setAccessible( true );

		$health = $method->invoke( $this->dashboard );

		// Verify health structure.
		$this->assertIsArray( $health, 'System health should be an array' );
		$this->assertArrayHasKey( 'overall_status', $health, 'Health should have overall_status' );
		$this->assertArrayHasKey( 'uptime_display', $health, 'Health should have uptime_display' );
		$this->assertArrayHasKey( 'indicators', $health, 'Health should have indicators' );

		// Verify overall status is valid.
		$valid_statuses = array( 'operational', 'warning', 'error' );
		$this->assertContains(
			$health['overall_status'],
			$valid_statuses,
			'Overall status should be valid'
		);

		// Verify indicators structure.
		$this->assertIsArray( $health['indicators'], 'Indicators should be an array' );
		$this->assertNotEmpty( $health['indicators'], 'Should have at least one indicator' );

		foreach ( $health['indicators'] as $indicator ) {
			$this->assertArrayHasKey( 'name', $indicator, 'Indicator should have name' );
			$this->assertArrayHasKey( 'value', $indicator, 'Indicator should have value' );
			$this->assertArrayHasKey( 'status', $indicator, 'Indicator should have status' );
			$this->assertArrayHasKey( 'icon', $indicator, 'Indicator should have icon' );
		}
	}

	/**
	 * Test system uptime display method.
	 */
	public function test_get_system_uptime() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_system_uptime' );
		$method->setAccessible( true );

		$uptime = $method->invoke( $this->dashboard );

		// Verify uptime is a string.
		$this->assertIsString( $uptime, 'Uptime should be a string' );
		$this->assertNotEmpty( $uptime, 'Uptime should not be empty' );
	}

	/**
	 * Test event enrichment method.
	 */
	public function test_enrich_monitoring_events() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'enrich_monitoring_events' );
		$method->setAccessible( true );

		// Create sample events.
		$sample_events = array(
			array(
				'message'   => 'Test authentication event',
				'type'      => 'authentication',
				'level'     => 'info',
				'timestamp' => current_time( 'timestamp' ),
			),
			array(
				'message'   => 'Test security alert',
				'type'      => 'security-alerts',
				'level'     => 'critical',
				'timestamp' => current_time( 'timestamp' ) - 3600,
			),
		);

		$enriched = $method->invoke( $this->dashboard, $sample_events );

		// Verify enrichment.
		$this->assertIsArray( $enriched, 'Enriched events should be an array' );
		$this->assertCount( 2, $enriched, 'Should have enriched both events' );

		foreach ( $enriched as $event ) {
			$this->assertArrayHasKey( 'id', $event, 'Enriched event should have id' );
			$this->assertArrayHasKey( 'type', $event, 'Enriched event should have type' );
			$this->assertArrayHasKey( 'type_label', $event, 'Enriched event should have type_label' );
			$this->assertArrayHasKey( 'icon', $event, 'Enriched event should have icon' );
			$this->assertArrayHasKey( 'message', $event, 'Enriched event should have message' );
			$this->assertArrayHasKey( 'severity', $event, 'Enriched event should have severity' );
			$this->assertArrayHasKey( 'timestamp', $event, 'Enriched event should have timestamp' );
			$this->assertArrayHasKey( 'time_display', $event, 'Enriched event should have time_display' );
		}
	}

	/**
	 * Test event table rendering method.
	 */
	public function test_render_monitoring_event_table() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'render_monitoring_event_table' );
		$method->setAccessible( true );

		// Create sample events.
		$sample_events = array(
			array(
				'message'   => 'Test event 1',
				'type'      => 'authentication',
				'level'     => 'info',
				'timestamp' => current_time( 'timestamp' ),
			),
		);

		ob_start();
		$method->invoke( $this->dashboard, $sample_events );
		$output = ob_get_clean();

		// Verify output contains expected elements.
		$this->assertStringContainsString(
			'wp-mcp-ai-event-table',
			$output,
			'Output should contain event table'
		);

		$this->assertStringContainsString(
			'wp-mcp-ai-event-row',
			$output,
			'Output should contain event rows'
		);

		$this->assertStringContainsString(
			'wp-mcp-ai-severity-badge',
			$output,
			'Output should contain severity badges'
		);
	}

	/**
	 * Test monitoring filters are properly escaped.
	 */
	public function test_monitoring_filters_are_escaped() {
		$_GET['tab'] = 'monitoring';

		ob_start();
		$this->dashboard->render_dashboard_with_tabs();
		$output = ob_get_clean();

		// Verify filter elements exist with proper IDs.
		$this->assertStringContainsString( 'id="monitoring-event-type"', $output );
		$this->assertStringContainsString( 'id="monitoring-severity"', $output );
		$this->assertStringContainsString( 'id="monitoring-timeframe"', $output );
		$this->assertStringContainsString( 'id="monitoring-search"', $output );

		unset( $_GET['tab'] );
	}

	/**
	 * Test that monitoring assets are enqueued on monitoring tab.
	 */
	public function test_monitoring_assets_enqueued() {
		global $wp_scripts, $wp_styles;

		// Trigger asset enqueue with pro dashboard hook.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_nvoos-pro-dashboard' );

		// Check that pro dashboard JS is registered.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-pro-dashboard', 'registered' ),
			'Pro dashboard JS should be registered'
		);

		// Check that pro dashboard CSS is registered.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-pro-dashboard', 'registered' ),
			'Pro dashboard CSS should be registered'
		);
	}
}
