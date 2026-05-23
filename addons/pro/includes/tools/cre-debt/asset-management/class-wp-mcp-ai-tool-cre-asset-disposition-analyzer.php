<?php
/**
 * CRE Asset Disposition Analyzer — Analyze REO and note sale disposition strategies
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
 * Analyzes REO and note sale disposition strategies with carrying cost projections,
 * net proceeds calculations, recovery rate analysis, and NPV comparison at a 10%
 * discount rate.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Asset_Disposition_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const DISCOUNT_RATE = 0.10;

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
		return 'cre_asset_disposition_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Asset Disposition Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze REO and note sale disposition strategies with carrying cost projections, net proceeds calculations, recovery rate analysis, and NPV comparison at a 10% discount rate.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'asset_type'                => array(
					'type'        => 'string',
					'description' => __( 'Disposition strategy type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'reo', 'note_sale' ),
				),
				'loan_balance'              => array(
					'type'        => 'number',
					'description' => __( 'Outstanding loan balance.', 'mcp-ai-wpoos-pro' ),
				),
				'appraised_value'           => array(
					'type'        => 'number',
					'description' => __( 'Current appraised value of the property.', 'mcp-ai-wpoos-pro' ),
				),
				'broker_opinion_value'      => array(
					'type'        => 'number',
					'description' => __( 'Broker opinion of value. Used as sale estimate if provided. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'marketing_timeline_months' => array(
					'type'        => 'integer',
					'description' => __( 'Expected marketing and sale timeline in months. Default 6.', 'mcp-ai-wpoos-pro' ),
					'default'     => 6,
				),
				'carrying_costs_monthly'    => array(
					'type'        => 'number',
					'description' => __( 'Monthly carrying costs (insurance, maintenance, utilities, etc.).', 'mcp-ai-wpoos-pro' ),
				),
				'selling_costs_pct'         => array(
					'type'        => 'number',
					'description' => __( 'Selling costs as percentage of sale price (e.g. 3 for 3%). Default 3.', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
				),
				'legal_costs'               => array(
					'type'        => 'number',
					'description' => __( 'Legal and closing costs. Default 25000.', 'mcp-ai-wpoos-pro' ),
					'default'     => 25000,
				),
				'property_taxes_annual'     => array(
					'type'        => 'number',
					'description' => __( 'Annual property taxes. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'asset_type', 'loan_balance', 'appraised_value', 'carrying_costs_monthly' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$asset_type                = sanitize_text_field( $arguments['asset_type'] ?? '' );
		$loan_balance              = (float) ( $arguments['loan_balance'] ?? 0 );
		$appraised_value           = (float) ( $arguments['appraised_value'] ?? 0 );
		$broker_opinion_value      = (float) ( $arguments['broker_opinion_value'] ?? 0 );
		$marketing_timeline_months = absint( $arguments['marketing_timeline_months'] ?? 6 );
		$carrying_costs_monthly    = (float) ( $arguments['carrying_costs_monthly'] ?? 0 );
		$selling_costs_pct         = (float) ( $arguments['selling_costs_pct'] ?? 3 );
		$legal_costs               = (float) ( $arguments['legal_costs'] ?? 25000 );
		$property_taxes_annual     = (float) ( $arguments['property_taxes_annual'] ?? 0 );

		if ( ! in_array( $asset_type, array( 'reo', 'note_sale' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'asset_type must be "reo" or "note_sale".', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $loan_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'loan_balance must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $appraised_value <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'appraised_value must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Sale price estimate.
		$sale_price_estimate = ( $broker_opinion_value > 0 ) ? $broker_opinion_value : $appraised_value;

		// Holding costs.
		$total_carrying        = $carrying_costs_monthly * $marketing_timeline_months;
		$property_taxes_during = $property_taxes_annual / 12 * $marketing_timeline_months;
		$total_holding_costs   = $total_carrying + $property_taxes_during + $legal_costs;

		// Proceeds.
		$gross_sale_proceeds = $sale_price_estimate;
		$selling_costs       = $sale_price_estimate * $selling_costs_pct / 100;
		$net_sale_proceeds   = $gross_sale_proceeds - $selling_costs - $total_holding_costs;

		// Loss and recovery.
		$total_loss    = $loan_balance - $net_sale_proceeds;
		$recovery_rate = $net_sale_proceeds / $loan_balance;
		$severity_rate = 1 - $recovery_rate;

		// NPV at 10% discount rate.
		$monthly_discount_rate = pow( 1 + self::DISCOUNT_RATE, 1.0 / 12 ) - 1;
		$npv_disposition       = $net_sale_proceeds / pow( 1 + $monthly_discount_rate, $marketing_timeline_months );

		// Breakeven analysis.
		$selling_divisor        = 1 - $selling_costs_pct / 100;
		$breakeven_price        = ( $selling_divisor > 0 )
			? ( $loan_balance + $total_holding_costs + $legal_costs ) / $selling_divisor
			: 0;
		$breakeven_vs_appraisal = ( $appraised_value > 0 ) ? $breakeven_price / $appraised_value : 0;

		// Month-by-month carrying cost timeline (month 1, every 3rd, final).
		$timeline         = array();
		$cumulative_costs = 0.0;
		for ( $m = 1; $m <= $marketing_timeline_months; $m++ ) {
			$cumulative_costs += $carrying_costs_monthly + ( $property_taxes_annual / 12 );
			$show_month        = ( 1 === $m )
				|| ( 0 === $m % 3 )
				|| ( $m === $marketing_timeline_months );
			if ( $show_month ) {
				$timeline[] = array(
					'month'            => $m,
					'monthly_cost'     => $calc::format_currency( $carrying_costs_monthly + ( $property_taxes_annual / 12 ) ),
					'cumulative_costs' => $calc::format_currency( $cumulative_costs ),
				);
			}
		}

		$data = array(
			'asset_type'             => $asset_type,
			'sale_estimate'          => array(
				'source'              => ( $broker_opinion_value > 0 )
					? __( 'Broker Opinion of Value', 'mcp-ai-wpoos-pro' )
					: __( 'Appraised Value', 'mcp-ai-wpoos-pro' ),
				'sale_price_estimate' => $calc::format_currency( $sale_price_estimate ),
				'appraised_value'     => $calc::format_currency( $appraised_value ),
				'broker_opinion'      => $calc::format_currency( $broker_opinion_value ),
			),
			'holding_costs'          => array(
				'carrying_costs_monthly' => $calc::format_currency( $carrying_costs_monthly ),
				'total_carrying'         => $calc::format_currency( $total_carrying ),
				'property_taxes_during'  => $calc::format_currency( $property_taxes_during ),
				'legal_costs'            => $calc::format_currency( $legal_costs ),
				'total_holding_costs'    => $calc::format_currency( $total_holding_costs ),
				'marketing_months'       => $marketing_timeline_months,
			),
			'proceeds'               => array(
				'gross_sale_proceeds' => $calc::format_currency( $gross_sale_proceeds ),
				'selling_costs'       => $calc::format_currency( $selling_costs ),
				'net_sale_proceeds'   => $calc::format_currency( $net_sale_proceeds ),
			),
			'recovery_analysis'      => array(
				'loan_balance'  => $calc::format_currency( $loan_balance ),
				'total_loss'    => $calc::format_currency( $total_loss ),
				'recovery_rate' => $calc::format_percentage( $recovery_rate ),
				'severity_rate' => $calc::format_percentage( $severity_rate ),
			),
			'npv_analysis'           => array(
				'discount_rate'   => $calc::format_percentage( self::DISCOUNT_RATE ),
				'timeline_months' => $marketing_timeline_months,
				'npv_disposition' => $calc::format_currency( $npv_disposition ),
			),
			'breakeven_analysis'     => array(
				'breakeven_price'        => $calc::format_currency( $breakeven_price ),
				'breakeven_vs_appraisal' => round( $breakeven_vs_appraisal, 2 ) . 'x',
			),
			'carrying_cost_timeline' => $timeline,
			'disclaimer'             => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: asset type */
				__( '%s disposition analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				strtoupper( $asset_type )
			),
			'data'       => $data,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
