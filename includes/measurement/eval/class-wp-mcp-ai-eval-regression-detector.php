<?php
/**
 * Eval Regression Detector
 *
 * Pure (no-WP, no-DB) helper that decides whether a freshly-completed
 * eval-suite run represents a regression against a baseline window of
 * prior runs. Lives alongside the eval runner so both the CLI command
 * and any future REST/cron surface can share the same threshold logic.
 *
 * Inputs are deliberately small:
 *   - `$baseline`  — the trailing window of run summaries to compare
 *                    against (most-recent-first, exclusive of `$current`).
 *   - `$current`   — the freshly-completed run summary.
 *   - `$config`    — thresholds. All thresholds are absolute deltas
 *                    against the baseline mean; relative thresholds
 *                    were considered but rejected because they
 *                    misbehave near zero (a 10% drop on a 1% pass
 *                    rate is invisible noise).
 *
 * Detection rules — *all* checked, not short-circuited, so the report
 * surfaces every regression dimension at once:
 *   1. `pass_rate` falls by `pass_rate_drop` or more vs. baseline mean.
 *   2. `error_rate` rises by `error_rate_rise` or more vs. baseline mean.
 *   3. `abstention_rate` rises by `abstention_rate_rise` or more vs.
 *      baseline mean. Abstentions are first-class anti-Goodhart
 *      signal — a model dodging cases is a regression even when
 *      pass_rate looks stable.
 *
 * Cold-start contract: when `$baseline` is empty we return `is_regression
 * = false` with `reasons = []`. The first run of any suite cannot be
 * a regression — it *defines* the baseline.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure regression-detection helper.
 */
class WP_MCP_AI_Eval_Regression_Detector {

	/**
	 * Default thresholds. All values are absolute (0..1) rate deltas.
	 *
	 * @var array<string,float>
	 */
	const DEFAULT_THRESHOLDS = array(
		'pass_rate_drop'       => 0.05,
		'error_rate_rise'      => 0.05,
		'abstention_rate_rise' => 0.10,
	);

	/**
	 * Decide whether `$current` is a regression vs. the trailing
	 * `$baseline` window.
	 *
	 * Returns a structured report so the caller (CLI / webhook / log)
	 * can surface *why* a regression triggered, not just *that* one
	 * triggered.
	 *
	 * @param array                    $current   Run summary in the shape produced by
	 *                                            `WP_MCP_AI_Eval_Runner::run()['summary']`.
	 * @param array<int,array>         $baseline  Prior summaries (most-recent-first), excluding `$current`.
	 * @param array<string,float>|null $config  Optional override for thresholds. Missing
	 *                                          keys fall back to `DEFAULT_THRESHOLDS`.
	 * @return array{
	 *     is_regression: bool,
	 *     reasons: array<int,array{metric:string, baseline:float, current:float, delta:float, threshold:float}>,
	 *     baseline_size: int,
	 *     baseline_means: array<string,float>,
	 *     thresholds: array<string,float>
	 * }
	 */
	public static function detect( array $current, array $baseline, $config = null ) {
		$thresholds = self::merge_thresholds( $config );
		$means      = array(
			'pass_rate'       => self::mean_of( $baseline, 'pass_rate' ),
			'error_rate'      => self::mean_of( $baseline, 'error_rate' ),
			'abstention_rate' => self::mean_of( $baseline, 'abstention_rate' ),
		);

		$reasons = array();

		// Cold-start: no baseline → cannot regress.
		if ( empty( $baseline ) ) {
			return array(
				'is_regression'  => false,
				'reasons'        => $reasons,
				'baseline_size'  => 0,
				'baseline_means' => $means,
				'thresholds'     => $thresholds,
			);
		}

		$current_pass       = isset( $current['pass_rate'] ) ? (float) $current['pass_rate'] : 0.0;
		$current_error      = isset( $current['error_rate'] ) ? (float) $current['error_rate'] : 0.0;
		$current_abstention = isset( $current['abstention_rate'] ) ? (float) $current['abstention_rate'] : 0.0;

		// Rule 1: pass_rate fell.
		$delta = $means['pass_rate'] - $current_pass; // Positive when current dropped.
		if ( $delta >= $thresholds['pass_rate_drop'] ) {
			$reasons[] = array(
				'metric'    => 'pass_rate',
				'baseline'  => $means['pass_rate'],
				'current'   => $current_pass,
				'delta'     => $delta,
				'threshold' => $thresholds['pass_rate_drop'],
			);
		}

		// Rule 2: error_rate rose.
		$delta = $current_error - $means['error_rate']; // Positive when current rose.
		if ( $delta >= $thresholds['error_rate_rise'] ) {
			$reasons[] = array(
				'metric'    => 'error_rate',
				'baseline'  => $means['error_rate'],
				'current'   => $current_error,
				'delta'     => $delta,
				'threshold' => $thresholds['error_rate_rise'],
			);
		}

		// Rule 3: abstention_rate rose.
		$delta = $current_abstention - $means['abstention_rate'];
		if ( $delta >= $thresholds['abstention_rate_rise'] ) {
			$reasons[] = array(
				'metric'    => 'abstention_rate',
				'baseline'  => $means['abstention_rate'],
				'current'   => $current_abstention,
				'delta'     => $delta,
				'threshold' => $thresholds['abstention_rate_rise'],
			);
		}

		return array(
			'is_regression'  => ! empty( $reasons ),
			'reasons'        => $reasons,
			'baseline_size'  => count( $baseline ),
			'baseline_means' => $means,
			'thresholds'     => $thresholds,
		);
	}

	/**
	 * Merge user-supplied thresholds over the defaults, dropping any
	 * non-numeric entries silently.
	 *
	 * @param array<string,float>|null $config Caller-supplied thresholds.
	 * @return array<string,float>
	 */
	private static function merge_thresholds( $config ) {
		$out = self::DEFAULT_THRESHOLDS;
		if ( ! is_array( $config ) ) {
			return $out;
		}
		foreach ( $out as $k => $_default ) {
			if ( isset( $config[ $k ] ) && is_numeric( $config[ $k ] ) ) {
				$out[ $k ] = (float) $config[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Arithmetic mean of `$key` across an array of summaries; missing
	 * or non-numeric values count as `0.0` so a missing field can't
	 * silently wave through a regression.
	 *
	 * @param array<int,array> $rows Summaries.
	 * @param string           $key  Field to average.
	 * @return float
	 */
	private static function mean_of( array $rows, $key ) {
		if ( empty( $rows ) ) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ( $rows as $row ) {
			$sum += isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? (float) $row[ $key ] : 0.0;
		}
		return $sum / count( $rows );
	}
}
