<?php
/**
 * CRE Operating Expense Benchmarker — Actual vs market benchmark comparison
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Compares actual operating expenses by category against market benchmarks
 * on a per-SF basis, flags outliers, and estimates potential savings.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Operating_Expense_Benchmarker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_operating_expense_benchmarker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Operating Expense Benchmarker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Benchmark a property\'s operating expenses against market data. Provide actual expense categories with amounts and corresponding market benchmarks per SF. Returns per-SF comparison, variance analysis, and savings opportunity estimate.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'property_type'     => array(
					'type'        => 'string',
					'description' => __( 'Property type (e.g. office, multifamily, industrial, retail).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'office', 'multifamily', 'industrial', 'retail', 'hospitality', 'mixed_use' ),
				),
				'total_sf'          => array(
					'type'        => 'number',
					'description' => __( 'Total rentable / net leasable square footage.', 'mcp-ai-wpoos-pro' ),
				),
				'opex_categories'   => array(
					'type'        => 'array',
					'description' => __( 'Array of actual expense category objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'category' => array(
								'type'        => 'string',
								'description' => __( 'Expense category name (e.g. "Real Estate Taxes", "Insurance").', 'mcp-ai-wpoos-pro' ),
							),
							'amount'   => array(
								'type'        => 'number',
								'description' => __( 'Annual expense amount.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'market_benchmarks' => array(
					'type'        => 'array',
					'description' => __( 'Array of market benchmark objects (same categories).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'category'         => array(
								'type'        => 'string',
								'description' => __( 'Expense category name (must match opex_categories).', 'mcp-ai-wpoos-pro' ),
							),
							'benchmark_per_sf' => array(
								'type'        => 'number',
								'description' => __( 'Market benchmark cost per SF for this category.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'required'   => array( 'property_type', 'total_sf', 'opex_categories', 'market_benchmarks' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$property_type = sanitize_text_field( $arguments['property_type'] ?? '' );
		$total_sf      = (float) ( $arguments['total_sf'] ?? 0 );
		$opex_cats     = $arguments['opex_categories'] ?? array();
		$benchmarks    = $arguments['market_benchmarks'] ?? array();

		if ( $total_sf <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Total SF must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $opex_cats ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one expense category is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Index benchmarks by category for O(1) lookup.
		$bench_map = array();
		foreach ( $benchmarks as $b ) {
			$cat = sanitize_text_field( $b['category'] ?? '' );
			if ( $cat ) {
				$bench_map[ $cat ] = (float) ( $b['benchmark_per_sf'] ?? 0 );
			}
		}

		$comparisons       = array();
		$total_actual      = 0.0;
		$total_benchmark   = 0.0;
		$total_variance    = 0.0;
		$savings_potential = 0.0;
		$outlier_threshold = 0.20; // 20% above benchmark = outlier

		foreach ( $opex_cats as $cat ) {
			$category      = sanitize_text_field( $cat['category'] ?? '' );
			$amount        = (float) ( $cat['amount'] ?? 0 );
			$actual_psf    = $amount / $total_sf;
			$total_actual += $amount;

			$bench_psf   = $bench_map[ $category ] ?? null;
			$bench_total = ( null !== $bench_psf ) ? $bench_psf * $total_sf : null;

			if ( null !== $bench_psf ) {
				$total_benchmark += $bench_total;
			}

			$variance_psf = ( null !== $bench_psf ) ? $actual_psf - $bench_psf : null;
			$variance_pct = ( null !== $bench_psf && $bench_psf > 0 )
				? ( $actual_psf - $bench_psf ) / $bench_psf
				: null;

			$is_outlier = ( null !== $variance_pct && $variance_pct > $outlier_threshold );

			if ( null !== $variance_psf ) {
				$total_variance += $variance_psf * $total_sf;
			}

			// Savings potential if we could reduce to benchmark.
			if ( null !== $variance_psf && 0 < $variance_psf ) {
				$savings_potential += $variance_psf * $total_sf;
			}

			$row = array(
				'category'     => $category,
				'actual_total' => $calc::format_currency( $amount ),
				'actual_psf'   => round( $actual_psf, 2 ),
			);

			if ( null !== $bench_psf ) {
				$row['benchmark_psf']   = round( $bench_psf, 2 );
				$row['benchmark_total'] = $calc::format_currency( $bench_total );
				$row['variance_psf']    = round( $variance_psf, 2 );
				$row['variance_pct']    = $calc::format_percentage( $variance_pct );
				$row['flag']            = $is_outlier ? 'ABOVE BENCHMARK' : 'WITHIN RANGE';
			} else {
				$row['benchmark_psf'] = 'N/A';
				$row['flag']          = 'NO BENCHMARK';
			}

			$comparisons[] = $row;
		}

		return array(
			'success' => true,
			'message' => __( 'Operating expense benchmark analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'property_info'     => array(
					'property_type' => $property_type,
					'total_sf'      => round( $total_sf, 2 ),
				),
				'totals'            => array(
					'total_actual_opex'    => $calc::format_currency( $total_actual ),
					'total_benchmark_opex' => $calc::format_currency( $total_benchmark ),
					'total_variance'       => $calc::format_currency( $total_variance ),
					'actual_opex_psf'      => round( $total_actual / $total_sf, 2 ),
					'benchmark_opex_psf'   => ( $total_benchmark > 0 ) ? round( $total_benchmark / $total_sf, 2 ) : 'N/A',
				),
				'category_detail'   => $comparisons,
				'savings_potential' => array(
					'annual_savings_if_at_benchmark' => $calc::format_currency( $savings_potential ),
					'savings_per_sf'                 => round( $savings_potential / $total_sf, 2 ),
				),
			),
		);
	}
}
