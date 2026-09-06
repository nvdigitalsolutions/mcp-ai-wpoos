<?php
/**
 * Analytics engine port tests (Wave D3c).
 *
 * Characterization suite for `AnalyticsEngine`. The pure math functions
 * (trend regression, statistics, Z-score) are characterized against the
 * base implementation's documented behaviour; the usage-dependent
 * methods are exercised through a testable subclass that pins the usage
 * seam deterministically in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Analytics\AnalyticsEngine;

/**
 * Test double pinning the per-user usage seam.
 */
class Testable_Analytics_Engine extends AnalyticsEngine {

	/** @var array */
	public static $usage = array();

	public static function set_usage( array $usage ): void {
		self::$usage = $usage;
	}

	protected static function get_user_tool_usage( $user_id ) {
		return isset( self::$usage[ $user_id ] ) ? self::$usage[ $user_id ] : array();
	}
}

/**
 * @group analytics
 */
class Test_Analytics_Engine extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Testable_Analytics_Engine::set_usage( array() );
	}

	// ─── Pure math ──────────────────────────────────────────────────

	public function test_calculate_trend_increasing(): void {
		$now  = strtotime( '2026-01-01 00:00:00' );
		$data = array(
			$now                 => 100,
			$now + DAY_IN_SECONDS   => 200,
			$now + 2 * DAY_IN_SECONDS => 300,
		);

		$trend = AnalyticsEngine::calculate_trend( $data );

		$this->assertSame( 100.0, $trend['slope'] );
		$this->assertSame( 100.0, $trend['intercept'] );
		$this->assertSame( 1.0, $trend['r_squared'] );
		$this->assertSame( 'stable', $trend['direction'] ); // abs(slope) not > 100 threshold.
		$this->assertSame( 100, $trend['confidence'] );

		// Steeper series flips the direction threshold.
		$steep = array(
			$now                 => 100,
			$now + DAY_IN_SECONDS   => 300,
			$now + 2 * DAY_IN_SECONDS => 500,
		);
		$trend = AnalyticsEngine::calculate_trend( $steep );
		$this->assertSame( 'increasing', $trend['direction'] );
		$this->assertSame( 200.0, $trend['slope'] );
	}

	public function test_calculate_trend_decreasing_and_empty(): void {
		$now = strtotime( '2026-01-01 00:00:00' );
		$data = array(
			$now                 => 500,
			$now + DAY_IN_SECONDS   => 300,
			$now + 2 * DAY_IN_SECONDS => 100,
		);

		$trend = AnalyticsEngine::calculate_trend( $data );
		$this->assertSame( 'decreasing', $trend['direction'] );
		$this->assertSame( -200.0, $trend['slope'] );

		$empty = AnalyticsEngine::calculate_trend( array() );
		$this->assertSame( 0, $empty['slope'] );
		$this->assertSame( 'stable', $empty['direction'] );
		$this->assertSame( 0, $empty['confidence'] );

		$single = AnalyticsEngine::calculate_trend( array( $now => 100 ) );
		$this->assertSame( 'stable', $single['direction'] );
	}

	public function test_calculate_statistics(): void {
		$stats = AnalyticsEngine::calculate_statistics( array( 2, 4, 4, 4, 5, 5, 7, 9 ) );

		$this->assertSame( 5.0, $stats['mean'] );
		$this->assertSame( 4.5, $stats['median'] );
		$this->assertSame( 2.0, $stats['std_dev'] );
		$this->assertSame( 4.0, $stats['variance'] );
		$this->assertSame( 2, $stats['min'] );
		$this->assertSame( 9, $stats['max'] );
		$this->assertSame( 8, $stats['count'] );
		$this->assertSame( 40.0, $stats['coefficient_of_variation'] );

		$empty = AnalyticsEngine::calculate_statistics( array() );
		$this->assertSame( 0, $empty['mean'] );
		$this->assertSame( 0, $empty['count'] );
	}

	public function test_calculate_z_score(): void {
		$this->assertSame( 2.5, AnalyticsEngine::calculate_z_score( 10, 5, 2 ) );
		$this->assertSame( -1.5, AnalyticsEngine::calculate_z_score( 2, 5, 2 ) );
		$this->assertSame( 0, AnalyticsEngine::calculate_z_score( 10, 5, 0 ) );
	}

	// ─── Usage-dependent methods (pinned seam) ──────────────────────

	public function test_detect_patterns_aggregates_hourly_and_daily(): void {
		Testable_Analytics_Engine::set_usage(
			array(
				1 => array(
					'tool_a' => array(
						'hourly' => array(
							'2026-01-05-09' => 100,
							'2026-01-05-10' => 50,
							'2026-01-06-09' => 100,
							'2026-01-06-10' => 50,
						),
					),
				),
			)
		);

		$patterns = Testable_Analytics_Engine::detect_patterns( 1 );

		$this->assertSame( 9, $patterns['peak_hours'][0] );
		$this->assertSame( 10, $patterns['peak_hours'][1] );
		// 2026-01-05 is a Monday (numeric 1), 2026-01-06 a Tuesday (2).
		$this->assertSame( 150, $patterns['daily_pattern'][1] );
		$this->assertSame( 150, $patterns['daily_pattern'][2] );
		$this->assertContains( 'Monday', $patterns['peak_days'] );
		$this->assertContains( 'Tuesday', $patterns['peak_days'] );
		$this->assertSame( 'bursty', $patterns['usage_type'] );
	}

	public function test_detect_patterns_empty_usage_shape(): void {
		$patterns = Testable_Analytics_Engine::detect_patterns( 999 );

		$this->assertSame( array(), $patterns['peak_hours'] );
		$this->assertSame( array(), $patterns['peak_days'] );
		$this->assertSame( 'consistent', $patterns['usage_type'] );
	}

	public function test_detect_anomalies_flags_outlier(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$two_days  = gmdate( 'Y-m-d', strtotime( '-2 days' ) );

		Testable_Analytics_Engine::set_usage(
			array(
				1 => array(
					'tool_a' => array(
						'daily' => array(
							$two_days  => 100,
							$yesterday => 100,
							$today     => 5000,
						),
					),
				),
			)
		);

		$anomalies = Testable_Analytics_Engine::detect_anomalies( 1, 1.0 );

		$this->assertCount( 1, $anomalies );
		$this->assertSame( $today, $anomalies[0]['date'] );
		$this->assertSame( 5000, $anomalies[0]['tokens'] );
		$this->assertArrayHasKey( 'severity', $anomalies[0] );
	}

	public function test_get_user_trends_with_seeded_usage(): void {
		$base = strtotime( 'today 00:00:00' );

		$daily = array(
			gmdate( 'Y-m-d', $base - 3 * DAY_IN_SECONDS ) => 100,
			gmdate( 'Y-m-d', $base - 2 * DAY_IN_SECONDS ) => 300,
			gmdate( 'Y-m-d', $base - 1 * DAY_IN_SECONDS ) => 500,
			gmdate( 'Y-m-d', $base )                      => 700,
		);

		Testable_Analytics_Engine::set_usage(
			array(
				1 => array(
					'tool_a' => array(
						'daily'        => $daily,
						'hourly'       => array(),
						'total_tokens' => 1600,
					),
				),
			)
		);

		$trends = Testable_Analytics_Engine::get_user_trends( 1, 30 );

		$this->assertCount( 4, $trends['daily_usage'] );
		$this->assertSame( 'increasing', $trends['trend']['direction'] );
		$this->assertSame( 200.0, $trends['trend']['slope'] );
		$this->assertSame( 4, $trends['statistics']['count'] );
		$this->assertGreaterThanOrEqual( 0, $trends['projected_7d'] );
		$this->assertGreaterThanOrEqual( $trends['projected_7d'], $trends['projected_30d'] );
	}

	public function test_compare_users_with_seeded_usage(): void {
		$today = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		Testable_Analytics_Engine::set_usage(
			array(
				1 => array(
					'tool_a' => array(
						'daily' => array(
							$today     => 100,
							$yesterday => 200,
						),
					),
				),
				2 => array(
					'tool_a' => array(
						'daily' => array(
							$today => 50,
						),
					),
				),
			)
		);

		$comparison = Testable_Analytics_Engine::compare_users( 1, 2, 30 );

		$this->assertSame( 6.0, $comparison['usage_ratio'] );
		$this->assertSame( 1, $comparison['higher_user'] );
		$this->assertSame( 142.86, $comparison['difference_pct'] );
		// min/max are unrounded in the base implementation.
		$this->assertSame( 200, $comparison['user1_stats']['max'] );
		$this->assertSame( 50, $comparison['user2_stats']['max'] );
	}

	public function test_get_user_trends_empty_usage(): void {
		$trends = Testable_Analytics_Engine::get_user_trends( 123, 30 );

		$this->assertSame( array(), $trends['daily_usage'] );
		$this->assertSame( 0, $trends['projected_7d'] );
		$this->assertSame( 0, $trends['projected_30d'] );
		$this->assertSame( 'stable', $trends['trend']['direction'] );
	}

	public function test_rebuild_without_transcript_storage(): void {
		$results = AnalyticsEngine::rebuild_usage_from_transcripts( 1 );

		$this->assertSame( 0, $results['transcripts_processed'] );
		$this->assertSame( 0, $results['tokens_recovered'] );
		$this->assertNotEmpty( $results['errors'] );
	}
}
