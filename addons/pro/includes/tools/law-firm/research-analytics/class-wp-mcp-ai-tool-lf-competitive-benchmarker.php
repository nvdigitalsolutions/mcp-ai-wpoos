<?php
/**
 * Competitive Benchmarker Tool
 *
 * Compares firm performance against industry benchmarks based on
 * firm size, practice areas, and region.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares firm metrics against industry benchmarks by firm size, practice area, and region.
 */
class WP_MCP_AI_Tool_LF_Competitive_Benchmarker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Industry benchmarks by firm size (based on published legal industry surveys).
	 *
	 * @var array
	 */
	private static $benchmarks = array(
		'solo'     => array(
			'avg_billing_rate'       => 250,
			'avg_realization_rate'   => 85,
			'avg_utilization'        => 60,
			'avg_revenue_per_lawyer' => 200000,
			'avg_overhead_ratio'     => 45,
			'avg_collection_rate'    => 80,
		),
		'small'    => array(
			'avg_billing_rate'       => 325,
			'avg_realization_rate'   => 88,
			'avg_utilization'        => 65,
			'avg_revenue_per_lawyer' => 300000,
			'avg_overhead_ratio'     => 50,
			'avg_collection_rate'    => 85,
		),
		'mid_size' => array(
			'avg_billing_rate'       => 425,
			'avg_realization_rate'   => 90,
			'avg_utilization'        => 70,
			'avg_revenue_per_lawyer' => 500000,
			'avg_overhead_ratio'     => 55,
			'avg_collection_rate'    => 88,
		),
		'large'    => array(
			'avg_billing_rate'       => 600,
			'avg_realization_rate'   => 92,
			'avg_utilization'        => 75,
			'avg_revenue_per_lawyer' => 800000,
			'avg_overhead_ratio'     => 60,
			'avg_collection_rate'    => 90,
		),
	);

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_competitive_benchmarker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Competitive Benchmarker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Compares firm performance against industry benchmarks by firm size, practice areas, and region. Returns benchmark data alongside actual firm metrics.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'firm_size'      => array(
					'type'        => 'string',
					'enum'        => array( 'solo', 'small', 'mid_size', 'large' ),
					'description' => __( 'Firm size category for benchmark comparison.', 'mcp-ai-wpoos-pro' ),
				),
				'practice_areas' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Practice areas to include in analysis.', 'mcp-ai-wpoos-pro' ),
				),
				'region'         => array(
					'type'        => 'string',
					'description' => __( 'Geographic region (e.g., "northeast", "west_coast", "midwest").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$firm_size      = isset( $arguments['firm_size'] ) ? sanitize_text_field( $arguments['firm_size'] ) : 'small';
		$practice_areas = isset( $arguments['practice_areas'] ) && is_array( $arguments['practice_areas'] ) ? array_map( 'sanitize_text_field', $arguments['practice_areas'] ) : array();
		$region         = isset( $arguments['region'] ) ? sanitize_text_field( $arguments['region'] ) : '';

		$allowed_sizes = array( 'solo', 'small', 'mid_size', 'large' );
		if ( ! in_array( $firm_size, $allowed_sizes, true ) ) {
			$firm_size = 'small';
		}

		$industry_benchmarks = self::$benchmarks[ $firm_size ];

		// Apply regional adjustments.
		$regional_multiplier = 1.0;
		$region_lower        = strtolower( $region );
		if ( in_array( $region_lower, array( 'northeast', 'west_coast', 'new_york', 'san_francisco' ), true ) ) {
			$regional_multiplier = 1.15;
		} elseif ( in_array( $region_lower, array( 'midwest', 'south', 'southeast' ), true ) ) {
			$regional_multiplier = 0.90;
		}

		$adjusted_benchmarks = array(
			'avg_billing_rate'       => round( $industry_benchmarks['avg_billing_rate'] * $regional_multiplier, 2 ),
			'avg_realization_rate'   => $industry_benchmarks['avg_realization_rate'],
			'avg_utilization'        => $industry_benchmarks['avg_utilization'],
			'avg_revenue_per_lawyer' => round( $industry_benchmarks['avg_revenue_per_lawyer'] * $regional_multiplier, 2 ),
			'avg_overhead_ratio'     => $industry_benchmarks['avg_overhead_ratio'],
			'avg_collection_rate'    => $industry_benchmarks['avg_collection_rate'],
		);

		// Gather actual firm data from the last 12 months.
		$one_year_ago = gmdate( 'Y-m-d', strtotime( '-12 months' ) );

		$entry_meta_query = array(
			array(
				'key'     => '_lf_entry_date',
				'value'   => $one_year_ago,
				'compare' => '>=',
				'type'    => 'DATE',
			),
		);

		if ( ! empty( $practice_areas ) ) {
			$entry_meta_query[] = array(
				'key'     => '_lf_practice_area',
				'value'   => $practice_areas,
				'compare' => 'IN',
			);
		}

		$entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_competitive_benchmarker', 0, 1000 ) : 1000,
				'post_status'    => 'publish',
				'meta_query'     => $entry_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$total_revenue        = 0;
		$total_standard       = 0;
		$total_collected      = 0;
		$total_billable_hours = 0;
		$total_hours          = 0;
		$attorneys            = array();
		$rates                = array();

		foreach ( $entries as $entry ) {
			$hours        = (float) get_post_meta( $entry->ID, '_lf_hours', true );
			$rate         = (float) get_post_meta( $entry->ID, '_lf_rate', true );
			$amount       = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$collected    = (float) get_post_meta( $entry->ID, '_lf_collected_amount', true );
			$billing_type = get_post_meta( $entry->ID, '_lf_billing_type', true );
			$author_id    = $entry->post_author;

			$total_standard  += $hours * $rate;
			$total_revenue   += $amount;
			$total_collected += $collected;
			$total_hours     += $hours;

			if ( 'billable' === $billing_type ) {
				$total_billable_hours += $hours;
			}

			if ( $rate > 0 ) {
				$rates[] = $rate;
			}

			$attorneys[ $author_id ] = true;
		}

		$attorney_count     = max( count( $attorneys ), 1 );
		$avg_billing_rate   = ! empty( $rates ) ? round( array_sum( $rates ) / count( $rates ), 2 ) : 0;
		$realization_rate   = $total_standard > 0 ? round( ( $total_revenue / $total_standard ) * 100, 1 ) : 0;
		$collection_rate    = $total_revenue > 0 ? round( ( $total_collected / $total_revenue ) * 100, 1 ) : 0;
		$utilization_rate   = $total_hours > 0 ? round( ( $total_billable_hours / $total_hours ) * 100, 1 ) : 0;
		$revenue_per_lawyer = round( $total_revenue / $attorney_count, 2 );

		$firm_metrics = array(
			'avg_billing_rate'   => $avg_billing_rate,
			'realization_rate'   => $realization_rate,
			'collection_rate'    => $collection_rate,
			'utilization_rate'   => $utilization_rate,
			'revenue_per_lawyer' => $revenue_per_lawyer,
			'attorney_count'     => $attorney_count,
			'total_revenue'      => round( $total_revenue, 2 ),
		);

		// Compare firm vs benchmarks.
		$comparisons = array(
			'billing_rate'       => array(
				'firm'       => $avg_billing_rate,
				'benchmark'  => $adjusted_benchmarks['avg_billing_rate'],
				'variance'   => round( $avg_billing_rate - $adjusted_benchmarks['avg_billing_rate'], 2 ),
				'percentile' => $this->calculate_percentile( $avg_billing_rate, $adjusted_benchmarks['avg_billing_rate'] ),
			),
			'realization_rate'   => array(
				'firm'       => $realization_rate,
				'benchmark'  => $adjusted_benchmarks['avg_realization_rate'],
				'variance'   => round( $realization_rate - $adjusted_benchmarks['avg_realization_rate'], 1 ),
				'percentile' => $this->calculate_percentile( $realization_rate, $adjusted_benchmarks['avg_realization_rate'] ),
			),
			'utilization'        => array(
				'firm'       => $utilization_rate,
				'benchmark'  => $adjusted_benchmarks['avg_utilization'],
				'variance'   => round( $utilization_rate - $adjusted_benchmarks['avg_utilization'], 1 ),
				'percentile' => $this->calculate_percentile( $utilization_rate, $adjusted_benchmarks['avg_utilization'] ),
			),
			'revenue_per_lawyer' => array(
				'firm'       => $revenue_per_lawyer,
				'benchmark'  => $adjusted_benchmarks['avg_revenue_per_lawyer'],
				'variance'   => round( $revenue_per_lawyer - $adjusted_benchmarks['avg_revenue_per_lawyer'], 2 ),
				'percentile' => $this->calculate_percentile( $revenue_per_lawyer, $adjusted_benchmarks['avg_revenue_per_lawyer'] ),
			),
			'collection_rate'    => array(
				'firm'       => $collection_rate,
				'benchmark'  => $adjusted_benchmarks['avg_collection_rate'],
				'variance'   => round( $collection_rate - $adjusted_benchmarks['avg_collection_rate'], 1 ),
				'percentile' => $this->calculate_percentile( $collection_rate, $adjusted_benchmarks['avg_collection_rate'] ),
			),
		);

		// Identify strengths and improvement areas.
		$strengths    = array();
		$improvements = array();
		foreach ( $comparisons as $metric_name => $comp ) {
			if ( $comp['variance'] > 0 ) {
				$strengths[] = str_replace( '_', ' ', $metric_name );
			} else {
				$improvements[] = str_replace( '_', ' ', $metric_name );
			}
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: firm size, 2: strength count, 3: improvement count */
				__( 'Benchmark comparison (%1$s firm): %2$d areas above benchmark, %3$d areas for improvement. ', 'mcp-ai-wpoos-pro' ),
				str_replace( '_', ' ', $firm_size ),
				count( $strengths ),
				count( $improvements )
			) . self::DISCLAIMER,
			'data'       => array(
				'firm_size'           => $firm_size,
				'practice_areas'      => $practice_areas,
				'region'              => $region,
				'industry_benchmarks' => $adjusted_benchmarks,
				'firm_metrics'        => $firm_metrics,
				'comparisons'         => $comparisons,
				'strengths'           => $strengths,
				'improvements'        => $improvements,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Calculates an approximate percentile ranking of a firm value against benchmark.
	 *
	 * @param float $firm_value      Firm's actual metric value.
	 * @param float $benchmark_value Industry benchmark (represents ~50th percentile).
	 * @return int Estimated percentile (0-100).
	 */
	private function calculate_percentile( $firm_value, $benchmark_value ) {
		if ( $benchmark_value <= 0 ) {
			return 50;
		}
		$ratio      = $firm_value / $benchmark_value;
		$percentile = 50 + ( ( $ratio - 1 ) * 100 );
		return max( 0, min( 100, (int) round( $percentile ) ) );
	}
}
