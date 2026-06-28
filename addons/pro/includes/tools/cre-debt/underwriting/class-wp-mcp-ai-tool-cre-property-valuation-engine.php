<?php
/**
 * CRE Property Valuation Engine — Three-approach reconciliation
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
 * Three-approach property valuation: income approach (direct cap), sales
 * comparison approach, and cost approach, with weighted reconciliation.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Property_Valuation_Engine implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_property_valuation_engine';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Property Valuation Engine', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Perform a three-approach property valuation: Income Approach (direct capitalization), Sales Comparison Approach (comparable sales), and Cost Approach (replacement cost less depreciation). Supports custom weighting for final reconciled value.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'noi'              => array(
					'type'        => 'number',
					'description' => __( 'Annual NOI for income approach.', 'mcp-ai-wpoos-pro' ),
				),
				'cap_rate'         => array(
					'type'        => 'number',
					'description' => __( 'Market cap rate as decimal for income approach.', 'mcp-ai-wpoos-pro' ),
				),
				'comparable_sales' => array(
					'type'        => 'array',
					'description' => __( 'Array of comparable sale objects for sales comparison approach.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'price'     => array(
								'type'        => 'number',
								'description' => __( 'Sale price.', 'mcp-ai-wpoos-pro' ),
							),
							'sf'        => array(
								'type'        => 'number',
								'description' => __( 'Building square footage.', 'mcp-ai-wpoos-pro' ),
							),
							'sale_date' => array(
								'type'        => 'string',
								'description' => __( 'Sale date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'subject_sf'       => array(
					'type'        => 'number',
					'description' => __( 'Subject property square footage (for applying comps PSF).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'replacement_cost' => array(
					'type'        => 'number',
					'description' => __( 'Estimated replacement/reproduction cost of improvements.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'land_value'       => array(
					'type'        => 'number',
					'description' => __( 'Estimated land value.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'depreciation_pct' => array(
					'type'        => 'number',
					'description' => __( 'Accrued depreciation as decimal (e.g. 0.20 for 20%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'weight_income'    => array(
					'type'        => 'number',
					'description' => __( 'Weight for income approach in reconciliation (0-1).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.50,
				),
				'weight_sales'     => array(
					'type'        => 'number',
					'description' => __( 'Weight for sales comparison approach (0-1).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.30,
				),
				'weight_cost'      => array(
					'type'        => 'number',
					'description' => __( 'Weight for cost approach (0-1).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.20,
				),
			),
			'required'   => array( 'noi', 'cap_rate' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$noi              = (float) ( $arguments['noi'] ?? 0 );
		$cap_rate         = (float) ( $arguments['cap_rate'] ?? 0 );
		$comps            = $arguments['comparable_sales'] ?? array();
		$subject_sf       = (float) ( $arguments['subject_sf'] ?? 0 );
		$replacement_cost = (float) ( $arguments['replacement_cost'] ?? 0 );
		$land_value       = (float) ( $arguments['land_value'] ?? 0 );
		$depreciation_pct = (float) ( $arguments['depreciation_pct'] ?? 0 );
		$w_income         = (float) ( $arguments['weight_income'] ?? 0.50 );
		$w_sales          = (float) ( $arguments['weight_sales'] ?? 0.30 );
		$w_cost           = (float) ( $arguments['weight_cost'] ?? 0.20 );

		if ( $noi <= 0 || $cap_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'NOI and cap rate must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// 1) Income Approach — Direct Capitalization.
		$income_value = $calc::calculate_value_direct_cap( $noi, $cap_rate );

		// 2) Sales Comparison Approach.
		$sales_value  = 0.0;
		$comps_detail = array();
		$has_comps    = ! empty( $comps ) && $subject_sf > 0;

		if ( $has_comps ) {
			$total_psf = 0.0;
			foreach ( $comps as $c ) {
				$c_price    = (float) ( $c['price'] ?? 0 );
				$c_sf       = (float) ( $c['sf'] ?? 0 );
				$c_date     = sanitize_text_field( $c['sale_date'] ?? '' );
				$psf        = ( $c_sf > 0 ) ? $c_price / $c_sf : 0;
				$total_psf += $psf;

				$comps_detail[] = array(
					'sale_price' => $calc::format_currency( $c_price ),
					'sf'         => round( $c_sf, 0 ),
					'price_psf'  => round( $psf, 2 ),
					'sale_date'  => $c_date,
				);
			}

			$avg_psf     = ( count( $comps ) > 0 ) ? $total_psf / count( $comps ) : 0;
			$sales_value = $avg_psf * $subject_sf;
		}

		// 3) Cost Approach.
		$cost_value               = 0.0;
		$has_cost                 = ( $replacement_cost > 0 || $land_value > 0 );
		$depreciation             = $replacement_cost * $depreciation_pct;
		$depreciated_improvements = $replacement_cost - $depreciation;

		if ( $has_cost ) {
			$cost_value = $depreciated_improvements + $land_value;
		}

		// Reconciliation — normalize weights.
		$total_weight = 0.0;
		$approaches   = array();

		if ( $income_value > 0 ) {
			$total_weight += $w_income;
		}
		if ( $has_comps && $sales_value > 0 ) {
			$total_weight += $w_sales;
		}
		if ( $has_cost && $cost_value > 0 ) {
			$total_weight += $w_cost;
		}

		if ( $total_weight <= 0 ) {
			$total_weight = 1.0;
		}

		$reconciled = 0.0;

		if ( $income_value > 0 ) {
			$eff_w        = $w_income / $total_weight;
			$reconciled  += $income_value * $eff_w;
			$approaches[] = array(
				'approach' => __( 'Income Approach (Direct Cap)', 'mcp-ai-wpoos-pro' ),
				'value'    => $calc::format_currency( $income_value ),
				'weight'   => $calc::format_percentage( $eff_w ),
				'weighted' => $calc::format_currency( $income_value * $eff_w ),
			);
		}

		if ( $has_comps && $sales_value > 0 ) {
			$eff_w        = $w_sales / $total_weight;
			$reconciled  += $sales_value * $eff_w;
			$approaches[] = array(
				'approach' => __( 'Sales Comparison Approach', 'mcp-ai-wpoos-pro' ),
				'value'    => $calc::format_currency( $sales_value ),
				'weight'   => $calc::format_percentage( $eff_w ),
				'weighted' => $calc::format_currency( $sales_value * $eff_w ),
			);
		}

		if ( $has_cost && $cost_value > 0 ) {
			$eff_w        = $w_cost / $total_weight;
			$reconciled  += $cost_value * $eff_w;
			$approaches[] = array(
				'approach' => __( 'Cost Approach', 'mcp-ai-wpoos-pro' ),
				'value'    => $calc::format_currency( $cost_value ),
				'weight'   => $calc::format_percentage( $eff_w ),
				'weighted' => $calc::format_currency( $cost_value * $eff_w ),
			);
		}

		$result = array(
			'income_approach' => array(
				'noi'      => $calc::format_currency( $noi ),
				'cap_rate' => $calc::format_percentage( $cap_rate ),
				'value'    => $calc::format_currency( $income_value ),
			),
			'reconciliation'  => array(
				'approaches'       => $approaches,
				'reconciled_value' => $calc::format_currency( $reconciled ),
			),
		);

		if ( $has_comps ) {
			$result['sales_comparison'] = array(
				'comparables'   => $comps_detail,
				'avg_psf'       => round( ( count( $comps ) > 0 ) ? array_sum( array_column( $comps_detail, 'price_psf' ) ) / count( $comps_detail ) : 0, 2 ),
				'subject_sf'    => round( $subject_sf, 0 ),
				'implied_value' => $calc::format_currency( $sales_value ),
			);
		}

		if ( $has_cost ) {
			$result['cost_approach'] = array(
				'replacement_cost'  => $calc::format_currency( $replacement_cost ),
				'depreciation'      => $calc::format_currency( $depreciation ),
				'depreciated_value' => $calc::format_currency( $depreciated_improvements ),
				'land_value'        => $calc::format_currency( $land_value ),
				'total_cost_value'  => $calc::format_currency( $cost_value ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Three-approach valuation complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => $result,
		);
	}
}
