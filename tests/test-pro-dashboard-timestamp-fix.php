<?php
/**
 * Test Pro Dashboard Timestamp Fix
 *
 * Tests for the fix of the non-numeric value error in Real-time Event Log.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Pro Dashboard timestamp handling.
 */
class Test_Pro_Dashboard_Timestamp_Fix extends WP_UnitTestCase {

	/**
	 * Test that MySQL datetime timestamps are converted to Unix timestamps.
	 */
	public function test_enrich_monitoring_events_handles_mysql_datetime() {
		// Require the dashboard class.
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Create test events with MySQL datetime timestamps (as stored by integrations).
		$test_events = array(
			array(
				'message'   => 'Test event with MySQL datetime',
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'authentication',
				'level'     => 'info',
			),
			array(
				'message'   => 'Test event with Unix timestamp',
				'timestamp' => current_time( 'timestamp' ),
				'type'      => 'security-alerts',
				'level'     => 'warning',
			),
			array(
				'message'   => 'Test event with string timestamp',
				'timestamp' => '2026-01-08 10:00:00',
				'type'      => 'configuration',
				'level'     => 'info',
			),
		);

		// Set the test events in the option.
		update_option( 'wp_mcp_ai_recent_activity', $test_events );

		// Get the dashboard instance.
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		// Use reflection to call the private enrich_monitoring_events method.
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'enrich_monitoring_events' );
		$method->setAccessible( true );

		// Call the method.
		$enriched_events = $method->invoke( $dashboard, $test_events );

		// Assertions.
		$this->assertIsArray( $enriched_events, 'Enriched events should be an array' );
		$this->assertCount( 3, $enriched_events, 'Should have 3 enriched events' );

		// Check that all timestamps are numeric.
		foreach ( $enriched_events as $event ) {
			$this->assertIsInt( $event['timestamp'], 'Timestamp should be an integer' );
			$this->assertGreaterThan( 0, $event['timestamp'], 'Timestamp should be positive' );
			$this->assertArrayHasKey( 'time_display', $event, 'Event should have time_display' );
			$this->assertStringContainsString( 'ago', $event['time_display'], 'Time display should contain "ago"' );
		}
	}

	/**
	 * Test that invalid timestamps are handled gracefully.
	 */
	public function test_enrich_monitoring_events_handles_invalid_timestamps() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Create test events with invalid timestamps.
		$test_events = array(
			array(
				'message'   => 'Event with invalid timestamp',
				'timestamp' => 'invalid-date-string',
				'type'      => 'default',
				'level'     => 'info',
			),
			array(
				'message'   => 'Event with no timestamp',
				'type'      => 'default',
				'level'     => 'info',
			),
		);

		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'enrich_monitoring_events' );
		$method->setAccessible( true );

		// This should not throw an error.
		$enriched_events = $method->invoke( $dashboard, $test_events );

		$this->assertIsArray( $enriched_events, 'Enriched events should be an array' );
		$this->assertCount( 2, $enriched_events, 'Should have 2 enriched events' );

		// Check that all timestamps are numeric (fallback to current time).
		foreach ( $enriched_events as $event ) {
			$this->assertIsInt( $event['timestamp'], 'Timestamp should be an integer even for invalid input' );
			$this->assertGreaterThan( 0, $event['timestamp'], 'Timestamp should be positive' );
		}
	}

	/**
	 * Test that get_monitoring_event_stats handles MySQL datetime timestamps.
	 */
	public function test_get_monitoring_event_stats_handles_mysql_datetime() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		// Create test events with MySQL datetime timestamps within the last 24 hours.
		$test_events = array(
			array(
				'message'   => 'Recent authentication event',
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'authentication',
				'level'     => 'info',
			),
			array(
				'message'   => 'Old file integrity event',
				'timestamp' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ),
				'type'      => 'file-integrity',
				'level'     => 'warning',
			),
			array(
				'message'   => 'Recent update event',
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'plugin-updates',
				'level'     => 'info',
			),
		);

		// Set the test events.
		update_option( 'wp_mcp_ai_recent_activity', $test_events );

		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_monitoring_event_stats' );
		$method->setAccessible( true );

		// This should not throw an error.
		$stats = $method->invoke( $dashboard );

		$this->assertIsArray( $stats, 'Stats should be an array' );
		$this->assertArrayHasKey( 'total_events', $stats, 'Stats should have total_events' );
		
		// Should count 2 recent events (not the old one from 2 days ago).
		$this->assertEquals( 2, $stats['total_events'], 'Should count 2 recent events within 24 hours' );
		$this->assertEquals( 1, $stats['auth_events'], 'Should count 1 auth event' );
		$this->assertEquals( 1, $stats['update_events'], 'Should count 1 update event' );
		$this->assertEquals( 0, $stats['file_integrity_events'], 'Should not count old file integrity event' );
	}

	/**
	 * Test that error events with MySQL datetime timestamps are handled.
	 */
	public function test_get_monitoring_event_stats_handles_error_timestamps() {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';

		$test_errors = array(
			array(
				'message'   => 'Recent critical error',
				'timestamp' => current_time( 'mysql' ),
				'level'     => 'critical',
			),
			array(
				'message'   => 'Old error',
				'timestamp' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ),
				'level'     => 'error',
			),
		);

		update_option( 'wp_mcp_ai_recent_errors', $test_errors );

		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();

		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_monitoring_event_stats' );
		$method->setAccessible( true );

		$stats = $method->invoke( $dashboard );

		$this->assertIsArray( $stats, 'Stats should be an array' );
		$this->assertEquals( 1, $stats['critical_count'], 'Should count 1 recent critical/error event' );
	}
}
