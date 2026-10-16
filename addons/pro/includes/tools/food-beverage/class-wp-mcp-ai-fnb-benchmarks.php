<?php
/**
 * Food & Beverage Management Toolkit — Benchmarks.
 *
 * Industry-standard benchmark ranges and threshold flags for F&B metrics,
 * modelled on the Healthcare Vitals `reference_ranges()` / `flag_value()`
 * engine. Centralises the performance targets (food cost %, beverage cost %,
 * labour %, prime cost %, inventory variance) that the original F&B proposal
 * promised but left scattered, so the metric engine, report builder and
 * `fnb_flag_*` tools read one filterable source of truth instead of
 * hard-coded thresholds.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * F&B benchmark ranges + threshold flagging.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Benchmarks {

	/**
	 * Default benchmark ranges (conservative adult / generic restaurant values).
	 *
	 * Each metric maps to { min, max, unit, direction } where `direction` is
	 * 'within' (in-range ideal) — the basis for `flag_threshold()`.
	 *
	 * @return array<string,array{min:float,max:float,unit:string}>
	 */
	public static function ranges() {
		$default = array(
			'food_cost_pct'       => array(
				'min'  => 0.28,
				'max'  => 0.35,
				'unit' => 'ratio',
			),
			'beverage_cost_pct'   => array(
				'min'  => 0.18,
				'max'  => 0.24,
				'unit' => 'ratio',
			),
			'labour_pct'          => array(
				'min'  => 0.25,
				'max'  => 0.35,
				'unit' => 'ratio',
			),
			'prime_cost_pct'      => array(
				'min'  => 0.55,
				'max'  => 0.65,
				'unit' => 'ratio',
			),
			'inventory_variance'  => array(
				'min'  => 0.0,
				'max'  => 0.03,
				'unit' => 'ratio',
			),
		);

		/**
		 * Filter the resolved F&B benchmark table.
		 *
		 * @param array $default Default table { metric_slug => { min, max, unit } }.
		 */
		$ranges = apply_filters( 'wp_mcp_ai_fnb_benchmarks', $default );

		return is_array( $ranges ) ? $ranges : $default;
	}

	/**
	 * Flag a metric value against a benchmark range.
	 *
	 * Returns one of: 'low', 'high', 'in_range', 'unknown'.
	 *
	 * @param string $metric Benchmark metric slug (e.g. 'food_cost_pct').
	 * @param float  $value  Computed value.
	 * @return string Flag.
	 */
	public static function flag_threshold( $metric, $value ) {
		$ranges = self::ranges();
		$metric = sanitize_key( $metric );
		if ( ! isset( $ranges[ $metric ] ) ) {
			return 'unknown';
		}

		$range = $ranges[ $metric ];
		$value = (float) $value;
		if ( isset( $range['min'] ) && $value < (float) $range['min'] ) {
			return 'low';
		}
		if ( isset( $range['max'] ) && $value > (float) $range['max'] ) {
			return 'high';
		}

		return 'in_range';
	}

	/**
	 * Human-readable description of a benchmark metric.
	 *
	 * @param string $metric Benchmark metric slug.
	 * @return string Description, or the slug when unknown.
	 */
	public static function label( $metric ) {
		$labels = array(
			'food_cost_pct'      => __( 'Food cost percentage', 'mcp-ai-wpoos-pro' ),
			'beverage_cost_pct'  => __( 'Beverage cost percentage', 'mcp-ai-wpoos-pro' ),
			'labour_pct'         => __( 'Labour cost percentage', 'mcp-ai-wpoos-pro' ),
			'prime_cost_pct'     => __( 'Prime cost percentage', 'mcp-ai-wpoos-pro' ),
			'inventory_variance' => __( 'Inventory variance', 'mcp-ai-wpoos-pro' ),
		);

		return isset( $labels[ $metric ] ) ? $labels[ $metric ] : $metric;
	}
}
