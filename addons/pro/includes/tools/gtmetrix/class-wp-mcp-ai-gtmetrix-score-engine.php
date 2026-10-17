<?php
/**
 * GTmetrix Toolkit — Score Engine.
 *
 * Deterministic performance assessment: Core Web Vitals classification
 * (2026 thresholds), GTmetrix grade banding, page-weight/request budgets,
 * LCP phase breakdown, impact-ordered audit sorting and before/after
 * regression deltas. Pure static methods — no I/O, no model maths.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GTmetrix score engine.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Score_Engine {

	/**
	 * Core Web Vitals thresholds (2026): good / poor boundaries, ms.
	 *
	 * @var array<string, array{good: float, poor: float}>
	 */
	const THRESHOLDS = array(
		'lcp'          => array(
			'good' => 2500,
			'poor' => 4000,
		),
		'cls'          => array(
			'good' => 0.1,
			'poor' => 0.25,
		),
		'tbt'          => array(
			'good' => 200,
			'poor' => 600,
		),
		'ttfb'         => array(
			'good' => 600,
			'poor' => 1800,
		),
		'speed_index'  => array(
			'good' => 3400,
			'poor' => 5800,
		),
		'onload'       => array(
			'good' => 3000,
			'poor' => 5000,
		),
		'fully_loaded' => array(
			'good' => 3000,
			'poor' => 5000,
		),
	);

	/**
	 * Page-weight budget classes (bytes, requests).
	 *
	 * @var array<string, array{max_bytes: int, max_requests: int}>
	 */
	const BUDGETS = array(
		'lean'       => array(
			'max_bytes'    => 1048576,
			'max_requests' => 50,
		),
		'acceptable' => array(
			'max_bytes'    => 2621440,
			'max_requests' => 90,
		),
		'heavy'      => array(
			'max_bytes'    => 5242880,
			'max_requests' => 150,
		),
	);

	/**
	 * Classify a metric value against its thresholds.
	 *
	 * @param string $metric Metric key (lcp, cls, tbt, ttfb, speed_index, onload, fully_loaded).
	 * @param mixed  $value  Raw value; -1 or null classify as unknown.
	 * @return array{status: string, good: float, poor: float}
	 */
	public static function assess_metric( $metric, $value ) {
		$thresholds = isset( self::THRESHOLDS[ $metric ] ) ? self::THRESHOLDS[ $metric ] : array(
			'good' => 0,
			'poor' => 0,
		);

		if ( null === $value || '' === $value || (float) $value < 0 ) {
			return array(
				'status' => 'unknown',
				'good'   => $thresholds['good'],
				'poor'   => $thresholds['poor'],
			);
		}

		$value = (float) $value;
		if ( $value <= $thresholds['good'] ) {
			$status = 'good';
		} elseif ( $value < $thresholds['poor'] ) {
			$status = 'needs-improvement';
		} else {
			$status = 'poor';
		}

		return array(
			'status' => $status,
			'good'   => $thresholds['good'],
			'poor'   => $thresholds['poor'],
		);
	}

	/**
	 * Assess Core Web Vitals from a report snapshot.
	 *
	 * @param array $report Report attributes (snapshot or API shape).
	 * @return array
	 */
	public static function assess_cwv( $report ) {
		$metrics = array(
			'lcp'  => array(
				'label' => __( 'Largest Contentful Paint', 'mcp-ai-wpoos-pro' ),
				'value' => self::report_metric( $report, 'largest_contentful_paint' ),
			),
			'tbt'  => array(
				'label' => __( 'Total Blocking Time (INP lab proxy)', 'mcp-ai-wpoos-pro' ),
				'value' => self::report_metric( $report, 'total_blocking_time' ),
			),
			'cls'  => array(
				'label' => __( 'Cumulative Layout Shift', 'mcp-ai-wpoos-pro' ),
				'value' => self::report_metric( $report, 'cumulative_layout_shift' ),
			),
			'ttfb' => array(
				'label' => __( 'Time to First Byte', 'mcp-ai-wpoos-pro' ),
				'value' => self::report_metric( $report, 'time_to_first_byte' ),
			),
		);

		$assessed = array();
		$known    = 0;
		$passing  = 0;
		foreach ( $metrics as $key => $meta ) {
			$row              = self::assess_metric( $key, $meta['value'] );
			$row['label']     = $meta['label'];
			$row['value']     = $meta['value'];
			$assessed[ $key ] = $row;
			if ( 'unknown' !== $row['status'] ) {
				++$known;
				if ( 'good' === $row['status'] ) {
					++$passing;
				}
			}
		}

		if ( 0 === $known ) {
			$overall = 'unknown';
		} elseif ( $passing === $known ) {
			$overall = 'pass';
		} else {
			$overall = 'fail';
		}

		return array(
			'metrics' => $assessed,
			'overall' => $overall,
			'note'    => __( 'INP is a field-data metric; TBT is the lab proxy. Lab data cannot certify INP.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Map a 0–1 score to a GTmetrix letter grade (band floors inclusive).
	 *
	 * @param float $score Score between 0 and 1.
	 * @return string
	 */
	public static function band_grade( $score ) {
		$score = (float) $score;

		if ( $score >= 0.9 ) {
			return 'A';
		}
		if ( $score >= 0.8 ) {
			return 'B';
		}
		if ( $score >= 0.7 ) {
			return 'C';
		}
		if ( $score >= 0.6 ) {
			return 'D';
		}
		if ( $score >= 0.5 ) {
			return 'E';
		}

		return 'F';
	}

	/**
	 * Classify a page's weight against the budget classes.
	 *
	 * @param int $page_bytes    Total transferred bytes.
	 * @param int $page_requests Total requests.
	 * @return array
	 */
	public static function budget_class( $page_bytes, $page_requests ) {
		$page_bytes    = absint( $page_bytes );
		$page_requests = absint( $page_requests );

		foreach ( self::BUDGETS as $class => $limits ) {
			if ( $page_bytes <= $limits['max_bytes'] && $page_requests <= $limits['max_requests'] ) {
				return array(
					'class'         => $class,
					'bytes'         => $page_bytes,
					'requests'      => $page_requests,
					'next_class'    => $class,
					'bytes_to_next' => 0,
					'reqs_to_next'  => 0,
				);
			}
		}

		return array(
			'class'         => 'very_heavy',
			'bytes'         => $page_bytes,
			'requests'      => $page_requests,
			'next_class'    => 'heavy',
			'bytes_to_next' => max( 0, $page_bytes - self::BUDGETS['heavy']['max_bytes'] ),
			'reqs_to_next'  => max( 0, $page_requests - self::BUDGETS['heavy']['max_requests'] ),
		);
	}

	/**
	 * LCP phase breakdown from a Lighthouse audit set.
	 *
	 * @param array $lighthouse Lighthouse JSON (decoded).
	 * @return array
	 */
	public static function lcp_phases( $lighthouse ) {
		$audit = isset( $lighthouse['audits']['largest-contentful-paint'] ) ? $lighthouse['audits']['largest-contentful-paint'] : array();
		$item  = isset( $audit['details']['items'][0] ) ? $audit['details']['items'][0] : array();

		if ( isset( $item['phaseBreakdown'] ) && is_array( $item['phaseBreakdown'] ) ) {
			$phases = $item['phaseBreakdown'];

			return array(
				'ttfb'         => isset( $phases['ttfb'] ) ? (int) $phases['ttfb'] : 0,
				'load_delay'   => isset( $phases['loadDelay'] ) ? (int) $phases['loadDelay'] : 0,
				'load_time'    => isset( $phases['loadTime'] ) ? (int) $phases['loadTime'] : 0,
				'render_delay' => isset( $phases['renderDelay'] ) ? (int) $phases['renderDelay'] : 0,
				'estimated'    => false,
			);
		}

		// Fallback: TTFB + remainder flagged as an estimate.
		$lcp = isset( $lighthouse['audits']['largest-contentful-paint'] ) ? $lighthouse['audits']['largest-contentful-paint'] : array();
		$lcp = isset( $lcp['numericValue'] ) ? (int) $lcp['numericValue'] : 0;

		return array(
			'ttfb'         => 0,
			'load_delay'   => 0,
			'load_time'    => 0,
			'render_delay' => 0,
			'lcp'          => $lcp,
			'estimated'    => true,
		);
	}

	/**
	 * Impact band label for a 0–1 impact score (GTmetrix's own table).
	 *
	 * @param mixed $impact Impact score (may be null for diagnostics).
	 * @return string
	 */
	public static function impact_band( $impact ) {
		if ( null === $impact ) {
			return 'None';
		}
		$impact = (float) $impact;
		if ( $impact <= 0 ) {
			return 'None';
		}
		if ( $impact <= 0.12 ) {
			return 'Low';
		}
		if ( $impact <= 0.24 ) {
			return 'Med-Low';
		}
		if ( $impact <= 0.36 ) {
			return 'Med';
		}
		if ( $impact <= 0.48 ) {
			return 'Med-High';
		}

		return 'High';
	}

	/**
	 * Sort Lighthouse audits by impact, descending.
	 *
	 * @param array $audits Lighthouse `audits` block.
	 * @return array
	 */
	public static function impact_order( $audits ) {
		$rows = array();
		foreach ( $audits as $id => $audit ) {
			$impact = isset( $audit['_impactScore'] ) ? $audit['_impactScore'] : null;
			$rows[] = array(
				'id'              => $id,
				'title'           => isset( $audit['title'] ) ? $audit['title'] : $id,
				'display_value'   => isset( $audit['displayValue'] ) ? $audit['displayValue'] : '',
				'score'           => isset( $audit['score'] ) ? $audit['score'] : null,
				'impact'          => $impact,
				'impact_band'     => self::impact_band( $impact ),
				'structure_score' => isset( $audit['_structureScore'] ) ? $audit['_structureScore'] : null,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				$ai = null === $a['impact'] ? -1.0 : (float) $a['impact'];
				$bi = null === $b['impact'] ? -1.0 : (float) $b['impact'];

				return $bi <=> $ai;
			}
		);

		return $rows;
	}

	/**
	 * Before/after delta verdicts per metric.
	 *
	 * @param array $before Report snapshot.
	 * @param array $after  Report snapshot.
	 * @return array
	 */
	public static function report_delta( $before, $after ) {
		$rules = array(
			'performance_score'        => array(
				'kind'      => 'score',
				'threshold' => 5,
				'direction' => 'down',
			),
			'structure_score'          => array(
				'kind'      => 'score',
				'threshold' => 5,
				'direction' => 'down',
			),
			'largest_contentful_paint' => array(
				'kind'      => 'percent',
				'threshold' => 20,
				'direction' => 'up',
			),
			'total_blocking_time'      => array(
				'kind'      => 'percent',
				'threshold' => 20,
				'direction' => 'up',
			),
			'cumulative_layout_shift'  => array(
				'kind'      => 'absolute',
				'threshold' => 0.05,
				'direction' => 'up',
			),
			'page_bytes'               => array(
				'kind'      => 'percent',
				'threshold' => 10,
				'direction' => 'up',
			),
			'page_requests'            => array(
				'kind'      => 'percent',
				'threshold' => 15,
				'direction' => 'up',
			),
			'time_to_first_byte'       => array(
				'kind'      => 'percent',
				'threshold' => 20,
				'direction' => 'up',
			),
		);

		$rows = array();
		foreach ( $rules as $metric => $rule ) {
			$before_v = self::report_metric( $before, $metric );
			$after_v  = self::report_metric( $after, $metric );
			if ( null === $before_v || null === $after_v ) {
				continue;
			}

			$rows[ $metric ] = self::verdict( $metric, $before_v, $after_v, $rule );
		}

		return $rows;
	}

	/**
	 * Single-metric verdict.
	 *
	 * @param string $metric  Metric key.
	 * @param mixed  $before  Before value.
	 * @param mixed  $after   After value.
	 * @param array  $rule    Delta rule.
	 * @return array
	 */
	private static function verdict( $metric, $before, $after, $rule ) {
		$before = (float) $before;
		$after  = (float) $after;

		$delta = $after - $before;
		if ( 0.0 === $before ) {
			$pct = 0.0;
		} else {
			$pct = ( $delta / $before ) * 100;
		}

		if ( 'absolute' === $rule['kind'] ) {
			$exceeded = abs( $delta ) >= $rule['threshold'];
		} else {
			$exceeded = abs( $pct ) >= $rule['threshold'];
		}

		if ( ! $exceeded ) {
			$status = 'steady';
		} elseif ( 'score' === $rule['kind'] ) {
			$status = $delta < 0 ? 'regressed' : 'improved';
		} else {
			// For timing/size metrics, an increase is a regression.
			$status = $delta > 0 ? 'regressed' : 'improved';
		}

		return array(
			'metric'         => $metric,
			'before'         => $before,
			'after'          => $after,
			'delta'          => $delta,
			'percent'        => $pct,
			'status'         => $status,
			'threshold'      => $rule['threshold'],
			'threshold_kind' => $rule['kind'],
		);
	}

	/**
	 * Read a metric from a report (API attributes or snapshot) safely.
	 *
	 * @param array  $report Report data.
	 * @param string $metric Attribute name.
	 * @return mixed Null when absent.
	 */
	public static function report_metric( $report, $metric ) {
		if ( isset( $report[ $metric ] ) ) {
			return $report[ $metric ];
		}
		if ( isset( $report['attributes'][ $metric ] ) ) {
			return $report['attributes'][ $metric ];
		}

		return null;
	}

	/**
	 * Normalise a report (API resource or snapshot) to a flat attribute map.
	 *
	 * @param array $report Report data.
	 * @return array
	 */
	public static function flat_report( $report ) {
		$attrs = isset( $report['attributes'] ) && is_array( $report['attributes'] ) ? $report['attributes'] : $report;
		$flat  = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$flat[ $key ] = $value;
			}
		}
		if ( isset( $report['id'] ) ) {
			$flat['id'] = $report['id'];
		}
		if ( isset( $attrs['id'] ) ) {
			$flat['id'] = $attrs['id'];
		}

		return $flat;
	}
}
