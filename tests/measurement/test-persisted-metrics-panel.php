<?php
/**
 * Tests for the PR 9.1 persisted-metrics dashboard panel helpers.
 *
 * The panel itself is a WP admin render path; the heart of the panel is
 * the pure `bucket_events()` aggregation. We exercise that directly so
 * the bucketing contract stays stable independent of WP_Admin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bucketing tests.
 */
class Test_WP_MCP_AI_Persisted_Metrics_Panel extends WP_UnitTestCase {

	/**
	 * Ensure the admin dashboard class is loaded — the bootstrap loader
	 * skips it when `is_admin()` is false (the PHPUnit default).
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'WP_MCP_AI_Admin_Measurement_Dashboard' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php';
		}
	}

	/**
	 * Build a fake row in the shape returned by `query_by_metric()`.
	 *
	 * @param int   $ts    UTC timestamp.
	 * @param float $value Metric value.
	 * @return array<string,mixed>
	 */
	private function row( $ts, $value ) {
		return array(
			'recorded_at'  => gmdate( 'Y-m-d H:i:s', (int) $ts ),
			'metric_value' => (float) $value,
		);
	}

	/**
	 * Empty input produces all-zero means and min==0, max==1 (flat guard).
	 */
	public function test_empty_events_produces_flat_buckets() {
		$out = WP_MCP_AI_Admin_Measurement_Dashboard::bucket_events( array(), 1000, 2000, 4 );
		$this->assertCount( 4, $out['means'] );
		$this->assertCount( 4, $out['counts'] );
		foreach ( $out['means'] as $m ) {
			$this->assertSame( 0.0, (float) $m );
		}
		foreach ( $out['counts'] as $c ) {
			$this->assertSame( 0, (int) $c );
		}
		$this->assertSame( 0.0, $out['min'] );
		// Flat-line guard kicks min==max apart to (min, min+1).
		$this->assertSame( 1.0, $out['max'] );
	}

	/**
	 * One row in each of two buckets — means match, rest are zero, min/max
	 * reflect observed values only (not the synthetic zeros).
	 */
	public function test_rows_are_assigned_to_their_time_bucket() {
		$since = 1000;
		$until = 1100;
		$rows  = array(
			$this->row( 1005, 10.0 ),  // Bucket 0.
			$this->row( 1055, 20.0 ),  // Bucket 5.
			$this->row( 1058, 40.0 ),  // Bucket 5 too → mean 30.
		);
		$out   = WP_MCP_AI_Admin_Measurement_Dashboard::bucket_events( $rows, $since, $until, 10 );
		$this->assertSame( 10.0, (float) $out['means'][0] );
		$this->assertSame( 30.0, (float) $out['means'][5] );
		$this->assertSame( 1, (int) $out['counts'][0] );
		$this->assertSame( 2, (int) $out['counts'][5] );
		$this->assertSame( 10.0, (float) $out['min'] );
		$this->assertSame( 30.0, (float) $out['max'] );
	}

	/**
	 * Rows outside the requested range are dropped — they must not
	 * pull the scale or show up in counts.
	 */
	public function test_rows_outside_range_are_dropped() {
		$since = 1000;
		$until = 1100;
		$rows  = array(
			$this->row( 500, 999.0 ),   // Before since.
			$this->row( 2000, 999.0 ),  // After until.
			$this->row( 1050, 5.0 ),    // In range.
		);
		$out   = WP_MCP_AI_Admin_Measurement_Dashboard::bucket_events( $rows, $since, $until, 4 );
		$this->assertSame( 5.0, (float) $out['min'] );
		$total = array_sum( $out['counts'] );
		$this->assertSame( 1, (int) $total );
	}

	/**
	 * A uniform series (all identical values) must survive the flat-line
	 * guard so the SVG still has a non-zero vertical range.
	 */
	public function test_flat_line_guard_preserves_visual_range() {
		$since = 1000;
		$until = 1100;
		$rows  = array(
			$this->row( 1010, 7.0 ),
			$this->row( 1050, 7.0 ),
			$this->row( 1090, 7.0 ),
		);
		$out   = WP_MCP_AI_Admin_Measurement_Dashboard::bucket_events( $rows, $since, $until, 4 );
		$this->assertGreaterThan( $out['min'], $out['max'] );
	}

	/**
	 * Bucket-count clamping: a <1 bucket count is clamped to 1.
	 */
	public function test_bucket_count_clamped_to_minimum_one() {
		$rows = array( $this->row( 1000, 3.0 ) );
		$out  = WP_MCP_AI_Admin_Measurement_Dashboard::bucket_events( $rows, 999, 1001, 0 );
		$this->assertCount( 1, $out['means'] );
		$this->assertCount( 1, $out['counts'] );
	}
}
