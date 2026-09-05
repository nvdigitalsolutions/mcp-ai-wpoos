<?php
/**
 * SLA manager port tests (Wave E2).
 *
 * Characterization suite for the ported `SlaManager`: byte-identical
 * tier/priority/SLA-target/concurrency constants, tier inference from
 * tool capabilities, Little's Law capacity math, queue-metrics analysis
 * (including the unavailable-manager error envelope), tuning
 * recommendations, compliance tracking/statistics (time-window and tier
 * filters), percentile interpolation, health grades, dashboard shape,
 * per-install-mode seam resolution, and the `JobQueueManager` SLA
 * wiring (tier→priority on enqueue) against the real custom table.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\JobQueueManager;
use NvoosContentGraphAiPlatform\Queues\SlaManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixtures share this file with their test cases.

/**
 * Seam subclass exposing protected members for contract testing.
 */
class SlaManagerSeam extends SlaManager {

	/**
	 * Expose calculate_percentile().
	 *
	 * @param array $sorted_values Sorted values.
	 * @param float $percentile    Percentile (0-100).
	 * @return float Percentile value.
	 */
	public static function seam_calculate_percentile( $sorted_values, $percentile ) {
		return self::calculate_percentile( $sorted_values, $percentile );
	}

	/**
	 * Expose get_overall_health_status().
	 *
	 * @param int $compliant_count Compliant jobs.
	 * @param int $violated_count  Violated jobs.
	 * @return string Health status.
	 */
	public static function seam_health_status( $compliant_count, $violated_count ) {
		return self::get_overall_health_status( $compliant_count, $violated_count );
	}

	/**
	 * Expose job_queue_manager_class().
	 *
	 * @return string|null Resolved queue manager class.
	 */
	public static function seam_job_queue_manager_class() {
		return self::job_queue_manager_class();
	}
}

/**
 * Seam subclass forcing the unavailable-queue-manager error envelope.
 */
class SlaManagerNoQueueSeam extends SlaManager {

	/**
	 * Force null resolution so analyze_queue_metrics() degrades honestly.
	 *
	 * @return string|null
	 */
	protected static function job_queue_manager_class(): ?string {
		return null;
	}
}

/**
 * Seam subclass exposing the JobQueueManager SLA resolution seams.
 */
class JobQueueManagerSlaSeam extends JobQueueManager {

	/**
	 * Expose sla_available().
	 *
	 * @return bool
	 */
	public static function seam_sla_available(): bool {
		return self::sla_available();
	}

	/**
	 * Expose sla_class().
	 *
	 * @return string
	 */
	public static function seam_sla_class(): string {
		return self::sla_class();
	}
}

/**
 * Fixture tool with a configurable capability set.
 */
class SlaToolFixture {

	/**
	 * Capability payload.
	 *
	 * @var mixed
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param mixed $capabilities Capability payload (array or non-array).
	 */
	public function __construct( $capabilities ) {
		$this->capabilities = $capabilities;
	}

	/**
	 * Capability accessor mirroring the tool-interface contract.
	 *
	 * @return mixed
	 */
	public function get_capabilities() {
		return $this->capabilities;
	}
}

/**
 * Fixture callable for queue jobs (static — PHP 8+ callable contract).
 */
class SlaJobQueueFixture {

	/**
	 * Success result.
	 *
	 * @return string
	 */
	public static function run() {
		return 'fixture-ran';
	}
}

/**
 * @group queues
 */
class Test_Sla_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_sla_compliance_log' );
	}

	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_sla_compliance_log' );

		parent::tearDown();
	}

	// ─── Constants ──────────────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'realtime', SlaManager::TIER_REALTIME );
		$this->assertSame( 'near_realtime', SlaManager::TIER_NEAR_REALTIME );
		$this->assertSame( 'batch', SlaManager::TIER_BATCH );

		$this->assertSame( 100, SlaManager::PRIORITY_REALTIME );
		$this->assertSame( 50, SlaManager::PRIORITY_NEAR_REALTIME );
		$this->assertSame( 10, SlaManager::PRIORITY_BATCH );

		$this->assertSame( 1, SlaManager::SLA_REALTIME_MAX );
		$this->assertSame( 30, SlaManager::SLA_NEAR_REALTIME_MAX );
		$this->assertSame( 300, SlaManager::SLA_BATCH_MAX );

		$this->assertSame( 5, SlaManager::DEFAULT_REALTIME_CONCURRENT );
		$this->assertSame( 3, SlaManager::DEFAULT_NEAR_REALTIME_CONCURRENT );
		$this->assertSame( 2, SlaManager::DEFAULT_BATCH_CONCURRENT );
	}

	// ─── Tier math ──────────────────────────────────────────────────

	public function test_get_priority_known_and_unknown(): void {
		$this->assertSame( 100, SlaManager::get_priority( 'realtime' ) );
		$this->assertSame( 50, SlaManager::get_priority( 'near_realtime' ) );
		$this->assertSame( 10, SlaManager::get_priority( 'batch' ) );
		$this->assertSame( 10, SlaManager::get_priority( 'unknown-tier' ) );
	}

	public function test_get_sla_target_known_and_unknown(): void {
		$this->assertSame( 1, SlaManager::get_sla_target( 'realtime' ) );
		$this->assertSame( 30, SlaManager::get_sla_target( 'near_realtime' ) );
		$this->assertSame( 300, SlaManager::get_sla_target( 'batch' ) );
		$this->assertSame( 300, SlaManager::get_sla_target( 'unknown-tier' ) );
	}

	public function test_get_default_concurrent_defaults(): void {
		$this->assertSame( 5, SlaManager::get_default_concurrent( 'realtime' ) );
		$this->assertSame( 3, SlaManager::get_default_concurrent( 'near_realtime' ) );
		$this->assertSame( 2, SlaManager::get_default_concurrent( 'batch' ) );
		$this->assertSame( 2, SlaManager::get_default_concurrent( 'unknown-tier' ) );
	}

	public function test_get_default_concurrent_settings_override_and_clamp(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'sla_realtime_concurrent' => 7,
				'sla_batch_concurrent'    => 0,
			)
		);

		$this->assertSame( 7, SlaManager::get_default_concurrent( 'realtime' ) );
		$this->assertSame( 1, SlaManager::get_default_concurrent( 'batch' ) );
		$this->assertSame( 3, SlaManager::get_default_concurrent( 'near_realtime' ) );
	}

	public function test_is_enabled_defaults_true(): void {
		$this->assertTrue( SlaManager::is_enabled() );
	}

	public function test_is_enabled_follows_setting_gate(): void {
		update_option( 'wp_mcp_ai_settings', array( 'sla_prioritization_enabled' => 0 ) );
		$this->assertFalse( SlaManager::is_enabled() );

		update_option( 'wp_mcp_ai_settings', array( 'sla_prioritization_enabled' => 1 ) );
		$this->assertTrue( SlaManager::is_enabled() );
	}

	public function test_get_valid_tiers(): void {
		$this->assertSame(
			array( 'realtime', 'near_realtime', 'batch' ),
			SlaManager::get_valid_tiers()
		);
	}

	public function test_get_tier_info_and_all_tiers(): void {
		$info = SlaManager::get_tier_info( 'realtime' );

		$this->assertSame( 'realtime', $info['tier'] );
		$this->assertSame( 100, $info['priority'] );
		$this->assertSame( 1, $info['sla_target'] );
		$this->assertSame( 5, $info['concurrent'] );
		$this->assertNotEmpty( $info['description'] );

		$all = SlaManager::get_all_tiers_info();
		$this->assertSame( array( 'realtime', 'near_realtime', 'batch' ), array_keys( $all ) );
		foreach ( $all as $tier_info ) {
			$this->assertArrayHasKey( 'tier', $tier_info );
			$this->assertArrayHasKey( 'priority', $tier_info );
			$this->assertArrayHasKey( 'sla_target', $tier_info );
			$this->assertArrayHasKey( 'concurrent', $tier_info );
			$this->assertArrayHasKey( 'description', $tier_info );
		}
	}

	// ─── Tier inference from tool capabilities ──────────────────────

	public function test_get_tier_for_tool_explicit_sla_tier(): void {
		$tool = new SlaToolFixture( array( 'sla_tier' => 'near_realtime' ) );
		$this->assertSame( 'near_realtime', SlaManager::get_tier_for_tool( $tool ) );
	}

	public function test_get_tier_for_tool_invalid_explicit_tier_falls_back_to_flags(): void {
		$tool = new SlaToolFixture(
			array(
				'sla_tier' => 'warp',
				0          => 'realtime',
			)
		);
		$this->assertSame( 'realtime', SlaManager::get_tier_for_tool( $tool ) );
	}

	public function test_get_tier_for_tool_realtime_flags(): void {
		foreach ( array( 'realtime', 'interactive', 'ui-blocking' ) as $flag ) {
			$this->assertSame(
				'realtime',
				SlaManager::get_tier_for_tool( new SlaToolFixture( array( $flag ) ) ),
				"Flag {$flag} must infer the realtime tier."
			);
		}
	}

	public function test_get_tier_for_tool_batch_flags(): void {
		foreach ( array( 'background-only', 'long-running' ) as $flag ) {
			$this->assertSame(
				'batch',
				SlaManager::get_tier_for_tool( new SlaToolFixture( array( $flag ) ) ),
				"Flag {$flag} must infer the batch tier."
			);
		}
	}

	public function test_get_tier_for_tool_near_realtime_flags(): void {
		foreach ( array( 'async', 'may-timeout' ) as $flag ) {
			$this->assertSame(
				'near_realtime',
				SlaManager::get_tier_for_tool( new SlaToolFixture( array( $flag ) ) ),
				"Flag {$flag} must infer the near-realtime tier."
			);
		}
	}

	public function test_get_tier_for_tool_unknown_defaults_to_batch(): void {
		$this->assertSame( 'batch', SlaManager::get_tier_for_tool( new SlaToolFixture( array() ) ) );
		$this->assertSame( 'batch', SlaManager::get_tier_for_tool( new SlaToolFixture( array( 'some-cap' ) ) ) );
		$this->assertSame( 'batch', SlaManager::get_tier_for_tool( new SlaToolFixture( 'not-an-array' ) ) );
		$this->assertSame( 'batch', SlaManager::get_tier_for_tool( null ) );
		$this->assertSame( 'batch', SlaManager::get_tier_for_tool( 'not-an-object' ) );
	}

	// ─── Little's Law capacity math ─────────────────────────────────

	public function test_calculate_capacity_littles_law(): void {
		$capacity = SlaManager::calculate_capacity( 'realtime', 0.5, 0.2 );

		$this->assertSame( 'realtime', $capacity['tier'] );
		$this->assertSame( 1, $capacity['sla_target'] );
		$this->assertEqualsWithDelta( 0.5, $capacity['arrival_rate'], 0.0001 );
		$this->assertEqualsWithDelta( 0.2, $capacity['service_time'], 0.0001 );
		$this->assertEqualsWithDelta( 0.8, $capacity['wait_time'], 0.0001 );
		$this->assertEqualsWithDelta( 0.4, $capacity['queue_length'], 0.0001 );
		$this->assertEqualsWithDelta( 0.5, $capacity['system_capacity'], 0.0001 );
		$this->assertEqualsWithDelta( 0.1, $capacity['utilization'], 0.0001 );
		$this->assertSame( 1, $capacity['required_workers'] );
		$this->assertSame( 5, $capacity['recommended_workers'] );
	}

	public function test_calculate_capacity_wait_time_floor_and_worker_floor(): void {
		// Service time above the SLA target floors the wait time at zero.
		$capacity = SlaManager::calculate_capacity( 'realtime', 2.0, 5.0 );

		$this->assertSame( 0, $capacity['wait_time'] );
		$this->assertSame( 0.0, $capacity['queue_length'] );
		$this->assertSame( 2.0, $capacity['system_capacity'] );
		$this->assertSame( 10.0, $capacity['utilization'] );
		$this->assertSame( 10.0, $capacity['required_workers'] );
		$this->assertSame( 10.0, $capacity['recommended_workers'] );
	}

	// ─── Queue metrics analysis ─────────────────────────────────────

	public function test_analyze_queue_metrics_shape(): void {
		// The metrics analysis reads live queue counts from the real
		// concurrent-jobs table — open it or every SELECT prints a DB error.
		$this->open_queue_table();

		try {
			$metrics = SlaManager::analyze_queue_metrics( 'realtime' );

			$this->assertArrayNotHasKey( 'error', $metrics );
			$this->assertSame( 'realtime', $metrics['tier'] );
			$this->assertArrayHasKey( 'sla_target', $metrics );
			$this->assertArrayHasKey( 'arrival_rate', $metrics );
			$this->assertArrayHasKey( 'service_time', $metrics );
			$this->assertArrayHasKey( 'wait_time', $metrics );
			$this->assertArrayHasKey( 'queue_length', $metrics );
			$this->assertArrayHasKey( 'system_capacity', $metrics );
			$this->assertArrayHasKey( 'utilization', $metrics );
			$this->assertArrayHasKey( 'required_workers', $metrics );
			$this->assertArrayHasKey( 'recommended_workers', $metrics );
			$this->assertSame( 5, $metrics['max_concurrent'] );

			$this->assertIsArray( $metrics['current_stats'] );
			$this->assertArrayHasKey( 'pending', $metrics['current_stats'] );
			$this->assertIsBool( $metrics['over_capacity'] );
			$this->assertIsBool( $metrics['meets_sla'] );
		} finally {
			$this->close_queue_table();
		}
	}

	public function test_analyze_queue_metrics_error_envelope_when_manager_missing(): void {
		$metrics = SlaManagerNoQueueSeam::analyze_queue_metrics( 'realtime' );

		$this->assertArrayHasKey( 'error', $metrics );
		$this->assertIsString( $metrics['error'] );
		$this->assertNotEmpty( $metrics['error'] );
	}

	public function test_tuning_recommendations_shape(): void {
		// Recommendations embed the live queue metrics per tier — open the
		// real concurrent-jobs table so the embedded analysis is quiet.
		$this->open_queue_table();

		try {
			$recommendations = SlaManager::get_tuning_recommendations();

			$this->assertSame( array( 'realtime', 'near_realtime', 'batch' ), array_keys( $recommendations ) );

			foreach ( $recommendations as $tier => $rec ) {
				$this->assertSame( $tier, $rec['tier'] );
				$this->assertArrayHasKey( 'current', $rec );
				$this->assertArrayHasKey( 'recommended', $rec );
				$this->assertContains( $rec['status'], array( 'ok', 'warning', 'critical' ) );
				$this->assertArrayHasKey( 'message', $rec );
			}
		} finally {
			$this->close_queue_table();
		}
	}

	// ─── Compliance tracking + statistics ───────────────────────────

	public function test_get_sla_statistics_empty_shape(): void {
		$stats = SlaManager::get_sla_statistics();

		$this->assertSame(
			array(
				'total_jobs'      => 0,
				'compliant_jobs'  => 0,
				'violated_jobs'   => 0,
				'compliance_rate' => 0,
				'avg_actual_time' => 0,
				'avg_target_time' => 0,
				'p50_actual_time' => 0,
				'p95_actual_time' => 0,
				'p99_actual_time' => 0,
			),
			$stats
		);
	}

	public function test_track_sla_compliance_and_statistics(): void {
		SlaManager::track_sla_compliance( 'job-1', 'realtime', 0.4, 1.0, true );
		SlaManager::track_sla_compliance( 'job-2', 'realtime', 1.6, 1.0, true );
		SlaManager::track_sla_compliance( 'job-3', 'batch', 10.0, 300.0, true );

		$stats = SlaManager::get_sla_statistics();
		$this->assertSame( 3, $stats['total_jobs'] );
		$this->assertSame( 2, $stats['compliant_jobs'] );
		$this->assertSame( 1, $stats['violated_jobs'] );
		$this->assertEqualsWithDelta( 66.6667, $stats['compliance_rate'], 0.001 );

		// Tier filter.
		$realtime_stats = SlaManager::get_sla_statistics( 'realtime', 24 );
		$this->assertSame( 2, $realtime_stats['total_jobs'] );
		$this->assertSame( 1, $realtime_stats['compliant_jobs'] );
		$this->assertSame( 1, $realtime_stats['violated_jobs'] );

		$batch_stats = SlaManager::get_sla_statistics( 'batch', 24 );
		$this->assertSame( 1, $batch_stats['total_jobs'] );
	}

	public function test_get_sla_statistics_time_window_filter(): void {
		update_option(
			'wp_mcp_ai_sla_compliance_log',
			array(
				array(
					'job_id'      => 'old-job',
					'tier'        => 'batch',
					'actual_time' => 1.0,
					'target_time' => 300.0,
					'success'     => true,
					'compliant'   => true,
					'timestamp'   => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				),
			)
		);

		$stats = SlaManager::get_sla_statistics( '', 24 );
		$this->assertSame( 0, $stats['total_jobs'] );
	}

	public function test_get_sla_statistics_tier_without_matching_entries(): void {
		SlaManager::track_sla_compliance( 'job-1', 'realtime', 0.4, 1.0, true );

		$stats = SlaManager::get_sla_statistics( 'batch', 24 );
		$this->assertSame( 0, $stats['total_jobs'] );
	}

	// ─── Percentile + health (seams) ────────────────────────────────

	public function test_calculate_percentile_edge_cases(): void {
		$this->assertSame( 0, SlaManagerSeam::seam_calculate_percentile( array(), 50 ) );
		$this->assertSame( 7.0, SlaManagerSeam::seam_calculate_percentile( array( 7.0 ), 50 ) );
	}

	public function test_calculate_percentile_interpolation(): void {
		// p50 of [1,2,3,4]: index 1.5 → 2 + 0.5 × (3 − 2) = 2.5.
		$this->assertEqualsWithDelta( 2.5, SlaManagerSeam::seam_calculate_percentile( array( 1, 2, 3, 4 ), 50 ), 0.0001 );
		// p95: index 2.85 → 3 + 0.85 × (4 − 3) = 3.85.
		$this->assertEqualsWithDelta( 3.85, SlaManagerSeam::seam_calculate_percentile( array( 1, 2, 3, 4 ), 95 ), 0.0001 );
		// p100: index 3 → exact last element.
		$this->assertSame( 4, SlaManagerSeam::seam_calculate_percentile( array( 1, 2, 3, 4 ), 100 ) );
	}

	public function test_overall_health_status_grades(): void {
		$this->assertSame( 'unknown', SlaManagerSeam::seam_health_status( 0, 0 ) );
		$this->assertSame( 'excellent', SlaManagerSeam::seam_health_status( 99, 1 ) );
		$this->assertSame( 'good', SlaManagerSeam::seam_health_status( 95, 5 ) );
		$this->assertSame( 'warning', SlaManagerSeam::seam_health_status( 90, 10 ) );
		$this->assertSame( 'critical', SlaManagerSeam::seam_health_status( 80, 20 ) );
	}

	// ─── Dashboard shape ────────────────────────────────────────────

	public function test_dashboard_data_shape(): void {
		// The dashboard embeds per-tier queue metrics — open the real
		// concurrent-jobs table so the embedded analysis is quiet.
		$this->open_queue_table();

		try {
			$data = SlaManager::get_dashboard_data();

			$this->assertSame( array( 'realtime', 'near_realtime', 'batch' ), array_keys( $data['tiers'] ) );
			foreach ( $data['tiers'] as $tier_data ) {
				$this->assertArrayHasKey( 'queue_metrics', $tier_data );
				$this->assertArrayHasKey( 'compliance_rate', $tier_data );
			}

			$this->assertArrayHasKey( 'total_jobs', $data['overall'] );
			$this->assertArrayHasKey( 'compliant_jobs', $data['overall'] );
			$this->assertArrayHasKey( 'violated_jobs', $data['overall'] );
			$this->assertArrayHasKey( 'compliance_rate', $data['overall'] );
			$this->assertSame( 'unknown', $data['overall']['health_status'] );

			$this->assertSame( array( 'realtime', 'near_realtime', 'batch' ), array_keys( $data['recommendations'] ) );
		} finally {
			$this->close_queue_table();
		}
	}

	// ─── Per-install-mode seams ─────────────────────────────────────

	public function test_job_queue_manager_seam_resolves_per_install_mode(): void {
		$resolved = SlaManagerSeam::seam_job_queue_manager_class();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Job_Queue_Manager', $resolved );
		} else {
			$this->assertSame( JobQueueManager::class, $resolved );
		}
	}

	public function test_job_queue_manager_sla_class_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_SLA_Manager', JobQueueManagerSlaSeam::seam_sla_class() );
		} else {
			$this->assertSame( SlaManager::class, JobQueueManagerSlaSeam::seam_sla_class() );
		}
	}

	public function test_job_queue_manager_sla_available_follows_enabled_flag(): void {
		$this->assertTrue( JobQueueManagerSlaSeam::seam_sla_available() );

		update_option( 'wp_mcp_ai_settings', array( 'sla_prioritization_enabled' => 0 ) );
		$this->assertFalse( JobQueueManagerSlaSeam::seam_sla_available() );

		delete_option( 'wp_mcp_ai_settings' );
		$this->assertTrue( JobQueueManagerSlaSeam::seam_sla_available() );
	}

	// ─── JobQueueManager SLA wiring (real queue table) ──────────────

	/**
	 * Suspend the WP test framework's CREATE/DROP TABLE → TEMPORARY rewrite
	 * and create the real concurrent-jobs table for one test.
	 *
	 * @return void
	 */
	private function open_queue_table(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$table_name = $wpdb->prefix . JobQueueManager::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );

		JobQueueManager::create_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name; test isolation on the custom table.
		$wpdb->query( 'TRUNCATE TABLE ' . $table_name );
	}

	/**
	 * Drop the real concurrent-jobs table and re-arm the TEMPORARY-table
	 * rewrite.
	 *
	 * @return void
	 */
	private function close_queue_table(): void {
		global $wpdb;

		// Drop the real table BEFORE re-arming the framework rewrite so the
		// cleanup is not redirected to a TEMPORARY table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . JobQueueManager::TABLE_NAME );

		// The drop happens behind the class's back — reset the memoized
		// table-existence probe so any later probe re-checks instead of
		// trusting the now-stale cache.
		JobQueueManager::reset_table_exists_cache();

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Fetch a raw queue row by job ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Raw row.
	 */
	private function queue_row( string $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test read on the custom table.
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . JobQueueManager::TABLE_NAME . ' WHERE job_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name.
				$job_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function test_enqueue_job_applies_sla_tier_priority(): void {
		$this->open_queue_table();

		$result = JobQueueManager::enqueue_job(
			'sla-tier-job',
			array(
				'callable' => array( SlaJobQueueFixture::class, 'run' ),
				'sla_tier' => 'realtime',
			)
		);
		$this->assertTrue( $result );

		$row = $this->queue_row( 'sla-tier-job' );
		$this->assertNotNull( $row );
		$this->assertSame( 'realtime', $row['sla_tier'] );
		$this->assertSame( 100, (int) $row['priority'] );

		$this->close_queue_table();
	}

	public function test_enqueue_job_infers_tier_from_tool_capabilities(): void {
		$this->open_queue_table();

		$tool = new SlaToolFixture( array( 'async' ) );

		$result = JobQueueManager::enqueue_job(
			'sla-tool-job',
			array(
				'callable' => array( SlaJobQueueFixture::class, 'run' ),
				'tool'     => $tool,
			)
		);
		$this->assertTrue( $result );

		$row = $this->queue_row( 'sla-tool-job' );
		$this->assertNotNull( $row );
		$this->assertSame( 'near_realtime', $row['sla_tier'] );
		$this->assertSame( 50, (int) $row['priority'] );

		$this->close_queue_table();
	}
}
