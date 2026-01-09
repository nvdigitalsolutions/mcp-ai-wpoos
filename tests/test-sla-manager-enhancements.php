<?php
/**
 * Tests for SLA Manager enhanced functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SLA Manager enhancements.
 */
class Test_SLA_Manager_Enhancements extends WP_UnitTestCase {
	/**
	 * Test SLA compliance tracking.
	 */
	public function test_sla_compliance_tracking() {
		$job_id      = 'test_job_' . wp_generate_uuid4();
		$tier        = 'near_realtime';
		$actual_time = 25.0;
		$target_time = 30.0;
		$success     = true;

		WP_MCP_AI_SLA_Manager::track_sla_compliance( $job_id, $tier, $actual_time, $target_time, $success );

		// Verify compliance data was stored.
		$log = get_option( 'wp_mcp_ai_sla_compliance_log', array() );
		$this->assertNotEmpty( $log );
		$this->assertIsArray( $log );

		// Find our entry.
		$found = false;
		foreach ( $log as $entry ) {
			if ( $entry['job_id'] === $job_id ) {
				$found = true;
				$this->assertEquals( $tier, $entry['tier'] );
				$this->assertEquals( $actual_time, $entry['actual_time'] );
				$this->assertEquals( $target_time, $entry['target_time'] );
				$this->assertTrue( $entry['success'] );
				$this->assertTrue( $entry['compliant'] ); // 25s < 30s target.
				break;
			}
		}

		$this->assertTrue( $found, 'Compliance entry not found in log' );
	}

	/**
	 * Test SLA statistics calculation.
	 */
	public function test_sla_statistics_calculation() {
		// Clear existing log.
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		// Track multiple jobs.
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'job1', 'realtime', 0.5, 1.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'job2', 'realtime', 1.5, 1.0, true ); // Violated.
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'job3', 'realtime', 0.8, 1.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'job4', 'near_realtime', 25.0, 30.0, true );

		// Get statistics for realtime tier.
		$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics( 'realtime', 24 );

		$this->assertEquals( 3, $stats['total_jobs'] );
		$this->assertEquals( 2, $stats['compliant_jobs'] );
		$this->assertEquals( 1, $stats['violated_jobs'] );
		$this->assertEqualsWithDelta( 66.67, $stats['compliance_rate'], 0.1 );

		// Average actual time: (0.5 + 1.5 + 0.8) / 3 = 0.933.
		$this->assertEqualsWithDelta( 0.933, $stats['avg_actual_time'], 0.1 );

		// Verify percentiles are calculated.
		$this->assertGreaterThan( 0, $stats['p50_actual_time'] );
		$this->assertGreaterThan( 0, $stats['p95_actual_time'] );
	}

	/**
	 * Test percentile calculation.
	 */
	public function test_percentile_calculation() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SLA_Manager' );
		$method     = $reflection->getMethod( 'calculate_percentile' );
		$method->setAccessible( true );

		$values = array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 );

		// P50 (median) should be 5.5.
		$p50 = $method->invoke( null, $values, 50 );
		$this->assertEquals( 5.5, $p50 );

		// P95 should be close to 9.5.
		$p95 = $method->invoke( null, $values, 95 );
		$this->assertEqualsWithDelta( 9.5, $p95, 0.1 );

		// P0 should be 1.
		$p0 = $method->invoke( null, $values, 0 );
		$this->assertEquals( 1, $p0 );

		// P100 should be 10.
		$p100 = $method->invoke( null, $values, 100 );
		$this->assertEquals( 10, $p100 );
	}

	/**
	 * Test dashboard data retrieval.
	 */
	public function test_get_dashboard_data() {
		// Clear and seed compliance log.
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		// Add sample data for all tiers.
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'rt1', 'realtime', 0.8, 1.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'rt2', 'realtime', 0.9, 1.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'nrt1', 'near_realtime', 20.0, 30.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'nrt2', 'near_realtime', 35.0, 30.0, true ); // Violated.
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'b1', 'batch', 200.0, 300.0, true );

		$dashboard = WP_MCP_AI_SLA_Manager::get_dashboard_data();

		// Verify structure.
		$this->assertIsArray( $dashboard );
		$this->assertArrayHasKey( 'tiers', $dashboard );
		$this->assertArrayHasKey( 'overall', $dashboard );
		$this->assertArrayHasKey( 'recommendations', $dashboard );

		// Verify tier data.
		$this->assertArrayHasKey( 'realtime', $dashboard['tiers'] );
		$this->assertArrayHasKey( 'near_realtime', $dashboard['tiers'] );
		$this->assertArrayHasKey( 'batch', $dashboard['tiers'] );

		// Verify overall statistics.
		$overall = $dashboard['overall'];
		$this->assertEquals( 5, $overall['total_jobs'] );
		$this->assertEquals( 4, $overall['compliant_jobs'] );
		$this->assertEquals( 1, $overall['violated_jobs'] );
		$this->assertEquals( 80.0, $overall['compliance_rate'] );
		$this->assertContains( $overall['health_status'], array( 'excellent', 'good', 'warning', 'critical', 'unknown' ) );
	}

	/**
	 * Test overall health status calculation.
	 */
	public function test_overall_health_status() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SLA_Manager' );
		$method     = $reflection->getMethod( 'get_overall_health_status' );
		$method->setAccessible( true );

		// 99% compliance = excellent.
		$status = $method->invoke( null, 99, 1 );
		$this->assertEquals( 'excellent', $status );

		// 95% compliance = good.
		$status = $method->invoke( null, 95, 5 );
		$this->assertEquals( 'good', $status );

		// 90% compliance = warning.
		$status = $method->invoke( null, 90, 10 );
		$this->assertEquals( 'warning', $status );

		// 80% compliance = critical.
		$status = $method->invoke( null, 80, 20 );
		$this->assertEquals( 'critical', $status );

		// No data = unknown.
		$status = $method->invoke( null, 0, 0 );
		$this->assertEquals( 'unknown', $status );
	}

	/**
	 * Test compliance log size limit.
	 */
	public function test_compliance_log_size_limit() {
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		// Add 1005 entries (over the 1000 limit).
		for ( $i = 0; $i < 1005; $i++ ) {
			WP_MCP_AI_SLA_Manager::track_sla_compliance( "job{$i}", 'batch', 100.0, 300.0, true );
		}

		$log = get_option( 'wp_mcp_ai_sla_compliance_log', array() );

		// Should be capped at 1000.
		$this->assertLessThanOrEqual( 1000, count( $log ) );
	}

	/**
	 * Test statistics filtering by time window.
	 */
	public function test_statistics_time_window_filtering() {
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		// Add recent entry.
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'recent', 'realtime', 0.5, 1.0, true );

		// Manually add old entry (25 hours ago).
		$log   = get_option( 'wp_mcp_ai_sla_compliance_log', array() );
		$log[] = array(
			'job_id'      => 'old_job',
			'tier'        => 'realtime',
			'actual_time' => 0.6,
			'target_time' => 1.0,
			'success'     => true,
			'compliant'   => true,
			'timestamp'   => date( 'Y-m-d H:i:s', strtotime( '-25 hours' ) ),
		);
		update_option( 'wp_mcp_ai_sla_compliance_log', $log, false );

		// Get stats for last 24 hours.
		$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics( 'realtime', 24 );

		// Should only include recent entry.
		$this->assertEquals( 1, $stats['total_jobs'] );
	}

	/**
	 * Test empty statistics.
	 */
	public function test_empty_statistics() {
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics( 'realtime', 24 );

		$this->assertEquals( 0, $stats['total_jobs'] );
		$this->assertEquals( 0, $stats['compliant_jobs'] );
		$this->assertEquals( 0, $stats['violated_jobs'] );
		$this->assertEquals( 0, $stats['compliance_rate'] );
	}

	/**
	 * Test tier filtering in statistics.
	 */
	public function test_statistics_tier_filtering() {
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		// Add jobs from different tiers.
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'rt1', 'realtime', 0.5, 1.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'nrt1', 'near_realtime', 20.0, 30.0, true );
		WP_MCP_AI_SLA_Manager::track_sla_compliance( 'b1', 'batch', 200.0, 300.0, true );

		// Filter by realtime tier.
		$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics( 'realtime', 24 );
		$this->assertEquals( 1, $stats['total_jobs'] );

		// Filter by near_realtime tier.
		$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics( 'near_realtime', 24 );
		$this->assertEquals( 1, $stats['total_jobs'] );

		// No filter (all tiers) - get_sla_statistics doesn't support this directly.
		// But dashboard data should show all.
		$dashboard = WP_MCP_AI_SLA_Manager::get_dashboard_data();
		$this->assertEquals( 3, $dashboard['overall']['total_jobs'] );
	}
}
