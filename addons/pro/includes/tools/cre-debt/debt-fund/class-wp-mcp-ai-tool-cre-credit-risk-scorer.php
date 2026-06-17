<?php
/**
 * CRE Credit Risk Scorer — Loan-level PD, LGD, expected loss, and risk-weighted asset analysis
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
 * Scores individual loan credit risk using LTV-based PD, DSCR-adjustment,
 * property-type LGD, and calculates expected loss and risk-weighted assets.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Credit_Risk_Scorer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_credit_risk_scorer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Credit Risk Scorer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Score loan-level credit risk with probability of default (PD) by LTV bucket, DSCR adjustment, property-type LGD, expected loss, and risk-weighted assets for a CRE debt portfolio.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loans' => array(
					'type'        => 'array',
					'description' => __( 'Array of loan objects to score.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'            => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'         => array(
								'type'        => 'number',
								'description' => __( 'Current outstanding balance.', 'mcp-ai-wpoos-pro' ),
							),
							'ltv'             => array(
								'type'        => 'number',
								'description' => __( 'Loan-to-Value as decimal (e.g. 0.65 for 65%).', 'mcp-ai-wpoos-pro' ),
							),
							'dscr'            => array(
								'type'        => 'number',
								'description' => __( 'Debt Service Coverage Ratio.', 'mcp-ai-wpoos-pro' ),
							),
							'property_type'   => array(
								'type'        => 'string',
								'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
							),
							'market_tier'     => array(
								'type'        => 'string',
								'description' => __( 'Market tier.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'primary', 'secondary', 'tertiary' ),
							),
							'sponsor_quality' => array(
								'type'        => 'integer',
								'description' => __( 'Sponsor quality rating 1 (worst) to 5 (best).', 'mcp-ai-wpoos-pro' ),
							),
							'loan_age_months' => array(
								'type'        => 'integer',
								'description' => __( 'Loan age in months since origination.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance', 'ltv', 'dscr', 'property_type' ),
					),
				),
			),
			'required'   => array( 'loans' ),
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
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loans = $arguments['loans'] ?? array();
		if ( empty( $loans ) || ! is_array( $loans ) ) {
			return new \WP_Error( 'invalid_input', __( 'At least one loan is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// LGD by property type.
		$lgd_map = array(
			'multifamily' => 0.25,
			'industrial'  => 0.30,
			'office'      => 0.35,
			'retail'      => 0.40,
			'hotel'       => 0.45,
			'other'       => 0.35,
		);

		// Risk-weight map by LTV bucket (simplified Basel-style).
		$rw_map = array(
			60  => 0.35,
			70  => 0.50,
			75  => 0.75,
			80  => 1.00,
			100 => 1.50,
		);

		$scored_loans        = array();
		$total_balance       = 0.0;
		$total_expected_loss = 0.0;
		$total_rwa           = 0.0;

		foreach ( $loans as $loan ) {
			$name          = sanitize_text_field( $loan['name'] ?? '' );
			$balance       = (float) ( $loan['balance'] ?? 0 );
			$ltv           = (float) ( $loan['ltv'] ?? 0 );
			$dscr          = (float) ( $loan['dscr'] ?? 0 );
			$property_type = sanitize_text_field( $loan['property_type'] ?? 'other' );
			$market_tier   = sanitize_text_field( $loan['market_tier'] ?? 'secondary' );
			$sponsor_q     = (int) ( $loan['sponsor_quality'] ?? 3 );
			$age_months    = (int) ( $loan['loan_age_months'] ?? 0 );

			$ltv_pct = $ltv * 100;

			// PD by LTV bucket.
			if ( $ltv_pct < 60 ) {
				$base_pd = 0.01;
			} elseif ( $ltv_pct < 70 ) {
				$base_pd = 0.02;
			} elseif ( $ltv_pct < 75 ) {
				$base_pd = 0.03;
			} elseif ( $ltv_pct < 80 ) {
				$base_pd = 0.05;
			} else {
				$base_pd = 0.08;
			}

			// DSCR adjustment.
			if ( $dscr >= 1.5 ) {
				$dscr_adj = 0.7;
			} elseif ( $dscr >= 1.25 ) {
				$dscr_adj = 1.0;
			} elseif ( $dscr >= 1.0 ) {
				$dscr_adj = 1.5;
			} else {
				$dscr_adj = 2.0;
			}

			$adjusted_pd = $base_pd * $dscr_adj;

			// Market tier adjustment.
			if ( 'primary' === $market_tier ) {
				$adjusted_pd *= 0.85;
			} elseif ( 'tertiary' === $market_tier ) {
				$adjusted_pd *= 1.20;
			}

			// Sponsor quality adjustment (1=worst, 5=best).
			$sponsor_q    = max( 1, min( 5, $sponsor_q ) );
			$sponsor_adj  = 1.0 + ( ( 3 - $sponsor_q ) * 0.10 );
			$adjusted_pd *= $sponsor_adj;

			// Cap PD at 100%.
			$adjusted_pd = min( 1.0, $adjusted_pd );

			// LGD.
			$lgd = $lgd_map[ $property_type ] ?? 0.35;

			// Expected loss.
			$expected_loss = $adjusted_pd * $lgd * $balance;

			// Risk weight.
			$risk_weight = 1.0;
			foreach ( $rw_map as $threshold => $rw ) {
				if ( $ltv_pct <= $threshold ) {
					$risk_weight = $rw;
					break;
				}
			}
			$rwa = $balance * $risk_weight;

			// Risk rating.
			if ( $adjusted_pd < 0.02 ) {
				$rating = 'low';
			} elseif ( $adjusted_pd < 0.05 ) {
				$rating = 'moderate';
			} elseif ( $adjusted_pd < 0.10 ) {
				$rating = 'elevated';
			} else {
				$rating = 'high';
			}

			$total_balance       += $balance;
			$total_expected_loss += $expected_loss;
			$total_rwa           += $rwa;

			$scored_loans[] = array(
				'name'          => $name,
				'balance'       => $calc::format_currency( $balance ),
				'ltv'           => $calc::format_percentage( $ltv ),
				'dscr'          => round( $dscr, 2 ) . 'x',
				'property_type' => $property_type,
				'pd'            => $calc::format_percentage( $adjusted_pd ),
				'lgd'           => $calc::format_percentage( $lgd ),
				'expected_loss' => $calc::format_currency( $expected_loss ),
				'risk_weight'   => round( $risk_weight, 2 ),
				'rwa'           => $calc::format_currency( $rwa ),
				'risk_rating'   => $rating,
			);
		}

		$wa_pd  = ( $total_balance > 0 ) ? $total_expected_loss / $total_balance : 0;
		$wa_rwa = ( $total_balance > 0 ) ? $total_rwa / $total_balance : 0;

		return array(
			'success'    => true,
			'message'    => __( 'Credit risk scoring complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'scored_loans'      => $scored_loans,
				'portfolio_summary' => array(
					'total_balance'       => $calc::format_currency( $total_balance ),
					'total_expected_loss' => $calc::format_currency( $total_expected_loss ),
					'total_rwa'           => $calc::format_currency( $total_rwa ),
					'wa_expected_loss'    => $calc::format_percentage( $wa_pd ),
					'wa_risk_weight'      => round( $wa_rwa, 2 ),
					'num_loans'           => count( $scored_loans ),
				),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
