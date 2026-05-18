<?php
/**
 * Insurance Needs Analyzer Tool
 *
 * Calculate life and disability insurance needs based on income,
 * dependents, debts, and financial obligations.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for analyzing insurance needs.
 *
 * Supports:
 * - Life insurance calculation (DIME method)
 * - Disability insurance needs
 * - Income replacement analysis
 * - Debt coverage assessment
 * - Family obligation planning
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Insurance_Needs_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Insurance needs analyzer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'insurance_needs_analyzer';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Insurance Needs Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate life and disability insurance needs based on income, dependents, and obligations. Uses DIME method (Debt, Income, Mortgage, Education) for life insurance. Provides coverage recommendations and gap analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'analysis_type'                => array(
					'type'        => 'string',
					'description' => __( 'Type of insurance analysis', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'life', 'disability', 'both' ),
					'default'     => 'both',
				),
				'annual_income'                => array(
					'type'        => 'number',
					'description' => __( 'Annual gross income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'age'                          => array(
					'type'        => 'integer',
					'description' => __( 'Current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 18,
					'maximum'     => 80,
				),
				'dependents'                   => array(
					'type'        => 'integer',
					'description' => __( 'Number of dependents', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'total_debt'                   => array(
					'type'        => 'number',
					'description' => __( 'Total debt (excluding mortgage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'mortgage_balance'             => array(
					'type'        => 'number',
					'description' => __( 'Remaining mortgage balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'education_costs'              => array(
					'type'        => 'number',
					'description' => __( 'Estimated future education costs for children', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'funeral_costs'                => array(
					'type'        => 'number',
					'description' => __( 'Estimated funeral/final expenses', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 15000,
				),
				'years_income_replacement'     => array(
					'type'        => 'integer',
					'description' => __( 'Years of income to replace', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 30,
					'default'     => 10,
				),
				'current_life_insurance'       => array(
					'type'        => 'number',
					'description' => __( 'Current life insurance coverage', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'current_disability_insurance' => array(
					'type'        => 'number',
					'description' => __( 'Current monthly disability insurance benefit', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'has_spouse'                   => array(
					'type'        => 'boolean',
					'description' => __( 'Has spouse/partner', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'spouse_income'                => array(
					'type'        => 'number',
					'description' => __( 'Spouse annual income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
			'required'   => array( 'annual_income', 'age' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the insurance needs analyzer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$analysis_type                = isset( $arguments['analysis_type'] ) ? sanitize_text_field( $arguments['analysis_type'] ) : 'both';
		$annual_income                = isset( $arguments['annual_income'] ) ? floatval( $arguments['annual_income'] ) : 0;
		$age                          = isset( $arguments['age'] ) ? absint( $arguments['age'] ) : 0;
		$dependents                   = isset( $arguments['dependents'] ) ? absint( $arguments['dependents'] ) : 0;
		$total_debt                   = isset( $arguments['total_debt'] ) ? floatval( $arguments['total_debt'] ) : 0;
		$mortgage_balance             = isset( $arguments['mortgage_balance'] ) ? floatval( $arguments['mortgage_balance'] ) : 0;
		$education_costs              = isset( $arguments['education_costs'] ) ? floatval( $arguments['education_costs'] ) : 0;
		$funeral_costs                = isset( $arguments['funeral_costs'] ) ? floatval( $arguments['funeral_costs'] ) : 15000;
		$years_income_replacement     = isset( $arguments['years_income_replacement'] ) ? absint( $arguments['years_income_replacement'] ) : 10;
		$current_life_insurance       = isset( $arguments['current_life_insurance'] ) ? floatval( $arguments['current_life_insurance'] ) : 0;
		$current_disability_insurance = isset( $arguments['current_disability_insurance'] ) ? floatval( $arguments['current_disability_insurance'] ) : 0;
		$has_spouse                   = isset( $arguments['has_spouse'] ) ? (bool) $arguments['has_spouse'] : false;
		$spouse_income                = isset( $arguments['spouse_income'] ) ? floatval( $arguments['spouse_income'] ) : 0;

		if ( $annual_income <= 0 ) {
			return new WP_Error( 'invalid_income', __( 'Annual income must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = array( 'success' => true );

		if ( 'life' === $analysis_type || 'both' === $analysis_type ) {
			$debt_component      = $total_debt;
			$income_component    = $annual_income * $years_income_replacement;
			$mortgage_component  = $mortgage_balance;
			$education_component = $education_costs;

			$recommended_life_coverage = $debt_component + $income_component + $mortgage_component + $education_component + $funeral_costs;

			$life_insurance_gap       = max( 0, $recommended_life_coverage - $current_life_insurance );
			$life_coverage_percentage = $recommended_life_coverage > 0 ? ( $current_life_insurance / $recommended_life_coverage ) * 100 : 0;

			$result['life_insurance'] = array(
				'recommended_coverage' => round( $recommended_life_coverage, 2 ),
				'current_coverage'     => $current_life_insurance,
				'coverage_gap'         => round( $life_insurance_gap, 2 ),
				'coverage_percentage'  => round( $life_coverage_percentage, 1 ),
				'breakdown'            => array(
					'debt'      => round( $debt_component, 2 ),
					'income'    => round( $income_component, 2 ),
					'mortgage'  => round( $mortgage_component, 2 ),
					'education' => round( $education_component, 2 ),
					'funeral'   => round( $funeral_costs, 2 ),
				),
			);
		}

		if ( 'disability' === $analysis_type || 'both' === $analysis_type ) {
			$recommended_monthly_benefit    = ( $annual_income * 0.60 ) / 12;
			$disability_gap                 = max( 0, $recommended_monthly_benefit - $current_disability_insurance );
			$disability_coverage_percentage = $recommended_monthly_benefit > 0 ? ( $current_disability_insurance / $recommended_monthly_benefit ) * 100 : 0;

			$result['disability_insurance'] = array(
				'recommended_monthly_benefit' => round( $recommended_monthly_benefit, 2 ),
				'current_monthly_benefit'     => $current_disability_insurance,
				'monthly_gap'                 => round( $disability_gap, 2 ),
				'coverage_percentage'         => round( $disability_coverage_percentage, 1 ),
				'replacement_rate'            => 60,
			);
		}

		$recommendations = array();
		if ( isset( $result['life_insurance'] ) && $result['life_insurance']['coverage_gap'] > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %s: Gap amount */
				__( 'Increase life insurance coverage by $%s to protect your family adequately.', 'mcp-ai-wpoos-pro' ),
				number_format( $result['life_insurance']['coverage_gap'], 2 )
			);
		}

		if ( isset( $result['disability_insurance'] ) && $result['disability_insurance']['monthly_gap'] > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %s: Gap amount */
				__( 'Consider disability insurance to replace $%s/month (60%% of income).', 'mcp-ai-wpoos-pro' ),
				number_format( $result['disability_insurance']['monthly_gap'], 2 )
			);
		}

		if ( $dependents > 0 && ! isset( $result['life_insurance'] ) ) {
			$recommendations[] = __( 'With dependents, life insurance is essential for financial protection.', 'mcp-ai-wpoos-pro' );
		}

		$recommendations[] = __( 'Review insurance needs annually and after major life events.', 'mcp-ai-wpoos-pro' );
		$recommendations[] = __( 'Consider term life insurance for affordable coverage during working years.', 'mcp-ai-wpoos-pro' );

		$result['recommendations'] = $recommendations;
		$result['disclaimer']      = __( 'This analysis provides general guidance only and does not constitute insurance advice. Insurance needs are highly individual. Consult with a licensed insurance professional for personalized recommendations.', 'mcp-ai-wpoos-pro' );

		$message_parts = array();
		if ( isset( $result['life_insurance'] ) ) {
			$message_parts[] = sprintf(
				/* translators: %s: Recommended coverage */
				__( 'Life insurance: $%s recommended', 'mcp-ai-wpoos-pro' ),
				number_format( $result['life_insurance']['recommended_coverage'], 0 )
			);
		}
		if ( isset( $result['disability_insurance'] ) ) {
			$message_parts[] = sprintf(
				/* translators: %s: Monthly benefit */
				__( 'Disability: $%s/month benefit', 'mcp-ai-wpoos-pro' ),
				number_format( $result['disability_insurance']['recommended_monthly_benefit'], 0 )
			);
		}

		$result['message'] = implode( '. ', $message_parts ) . '.';

		return $result;
	}
}
