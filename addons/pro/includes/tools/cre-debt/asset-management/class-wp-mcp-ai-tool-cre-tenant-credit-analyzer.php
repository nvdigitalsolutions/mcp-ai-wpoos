<?php
/**
 * CRE Tenant Credit Analyzer — Analyze tenant credit quality with concentration risk scoring
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
 * Analyzes tenant credit quality across a property portfolio with
 * concentration risk scoring, investment-grade classification, and
 * early warning flags for at-risk tenants.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Tenant_Credit_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_tenant_credit_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Tenant Credit Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze tenant credit quality across a property portfolio with concentration risk scoring, investment-grade classification, and early warning flags for at-risk tenants.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tenants' => array(
					'type'        => 'array',
					'description' => __( 'Array of tenant objects to analyze.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'                     => array(
								'type'        => 'string',
								'description' => __( 'Tenant name.', 'mcp-ai-wpoos-pro' ),
							),
							'credit_rating'            => array(
								'type'        => 'string',
								'description' => __( 'Tenant credit rating.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'NR' ),
							),
							'annual_rent'              => array(
								'type'        => 'number',
								'description' => __( 'Annual rent amount.', 'mcp-ai-wpoos-pro' ),
							),
							'lease_remaining_years'    => array(
								'type'        => 'number',
								'description' => __( 'Remaining lease term in years.', 'mcp-ai-wpoos-pro' ),
							),
							'industry'                 => array(
								'type'        => 'string',
								'description' => __( 'Tenant industry sector.', 'mcp-ai-wpoos-pro' ),
							),
							'pct_of_total_rent'        => array(
								'type'        => 'number',
								'description' => __( 'Percentage of total portfolio rent (e.g. 15 for 15%).', 'mcp-ai-wpoos-pro' ),
							),
							'financial_coverage_ratio' => array(
								'type'        => 'number',
								'description' => __( 'Financial coverage ratio (e.g. FCCR). Default 0.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
						),
						'required'   => array( 'name', 'credit_rating', 'annual_rent', 'lease_remaining_years', 'industry', 'pct_of_total_rent' ),
					),
				),
			),
			'required'   => array( 'tenants' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$raw_tenants = $arguments['tenants'] ?? array();
		if ( empty( $raw_tenants ) || ! is_array( $raw_tenants ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one tenant entry is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$credit_score_map = array(
			'AAA' => 100,
			'AA'  => 90,
			'A'   => 80,
			'BBB' => 70,
			'BB'  => 50,
			'B'   => 30,
			'CCC' => 10,
			'NR'  => 25,
		);

		$investment_grade_ratings = array( 'AAA', 'AA', 'A', 'BBB' );

		$tenant_details         = array();
		$weighted_score_sum     = 0.0;
		$pct_sum                = 0.0;
		$investment_grade_count = 0;
		$ig_rent_pct            = 0.0;
		$concentration_risks    = array();
		$at_risk_tenants        = array();
		$industry_breakdown     = array();

		foreach ( $raw_tenants as $raw ) {
			$name              = sanitize_text_field( $raw['name'] ?? '' );
			$credit_rating     = sanitize_text_field( $raw['credit_rating'] ?? '' );
			$annual_rent       = (float) ( $raw['annual_rent'] ?? 0 );
			$lease_remaining   = (float) ( $raw['lease_remaining_years'] ?? 0 );
			$industry          = sanitize_text_field( $raw['industry'] ?? '' );
			$pct_of_total_rent = (float) ( $raw['pct_of_total_rent'] ?? 0 );
			$coverage_ratio    = (float) ( $raw['financial_coverage_ratio'] ?? 0 );

			if ( empty( $name ) || empty( $credit_rating ) ) {
				continue;
			}

			$is_investment_grade = in_array( $credit_rating, $investment_grade_ratings, true );
			$credit_score        = isset( $credit_score_map[ $credit_rating ] ) ? $credit_score_map[ $credit_rating ] : 25;

			// Determine risk level.
			if ( $is_investment_grade ) {
				$risk_level = 'low';
			} elseif ( 'BB' === $credit_rating ) {
				$risk_level = 'moderate';
			} elseif ( 'NR' === $credit_rating ) {
				$risk_level = 'elevated';
			} else {
				$risk_level = 'high';
			}

			// Concentration risk.
			if ( $pct_of_total_rent > 20 ) {
				$concentration_risk = 'high';
			} elseif ( $pct_of_total_rent > 10 ) {
				$concentration_risk = 'moderate';
			} else {
				$concentration_risk = 'low';
			}

			// Early warning flags.
			$early_warnings = array();
			if ( $lease_remaining < 2 && ! $is_investment_grade ) {
				$early_warnings[] = __( 'Short remaining lease term with sub-investment-grade credit.', 'mcp-ai-wpoos-pro' );
			}
			if ( $pct_of_total_rent > 25 ) {
				$early_warnings[] = __( 'Tenant represents over 25% of total rent — high concentration.', 'mcp-ai-wpoos-pro' );
			}
			if ( $coverage_ratio > 0 && $coverage_ratio < 1.25 ) {
				$early_warnings[] = __( 'Financial coverage ratio below 1.25x — potential credit stress.', 'mcp-ai-wpoos-pro' );
			}

			$tenant_details[] = array(
				'name'                     => $name,
				'credit_rating'            => $credit_rating,
				'credit_score'             => $credit_score,
				'is_investment_grade'      => $is_investment_grade,
				'risk_level'               => $risk_level,
				'annual_rent'              => $calc::format_currency( $annual_rent ),
				'lease_remaining_years'    => $lease_remaining,
				'industry'                 => $industry,
				'pct_of_total_rent'        => $calc::format_percentage( $pct_of_total_rent / 100 ),
				'financial_coverage_ratio' => $coverage_ratio,
				'concentration_risk'       => $concentration_risk,
				'early_warnings'           => $early_warnings,
			);

			$weighted_score_sum += $credit_score * $pct_of_total_rent;
			$pct_sum            += $pct_of_total_rent;

			if ( $is_investment_grade ) {
				++$investment_grade_count;
				$ig_rent_pct += $pct_of_total_rent;
			}

			if ( $pct_of_total_rent > 20 ) {
				$concentration_risks[] = $name;
			}

			if ( ! empty( $early_warnings ) ) {
				$at_risk_tenants[] = $name;
			}

			// Industry breakdown.
			if ( ! isset( $industry_breakdown[ $industry ] ) ) {
				$industry_breakdown[ $industry ] = 0.0;
			}
			$industry_breakdown[ $industry ] += $annual_rent;
		}

		if ( empty( $tenant_details ) ) {
			return new WP_Error( 'invalid_input', __( 'No valid tenant entries provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$total_tenants          = count( $tenant_details );
		$sub_investment_grade   = $total_tenants - $investment_grade_count;
		$portfolio_credit_score = ( $pct_sum > 0 ) ? $weighted_score_sum / $pct_sum : 0;

		// Format industry breakdown.
		$industry_summary = array();
		foreach ( $industry_breakdown as $ind => $rent ) {
			$industry_summary[] = array(
				'industry'   => $ind,
				'total_rent' => $calc::format_currency( $rent ),
			);
		}

		return array(
			'success'    => true,
			'message'    => __( 'Tenant credit analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'portfolio_summary' => array(
					'total_tenants'                => $total_tenants,
					'investment_grade_count'       => $investment_grade_count,
					'sub_investment_grade_count'   => $sub_investment_grade,
					'investment_grade_pct_of_rent' => $calc::format_percentage( $ig_rent_pct / 100 ),
					'portfolio_credit_score'       => round( $portfolio_credit_score, 1 ),
					'concentration_risks'          => $concentration_risks,
					'at_risk_tenants'              => $at_risk_tenants,
					'industry_breakdown'           => $industry_summary,
				),
				'tenant_details'    => $tenant_details,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
