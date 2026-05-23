<?php
/**
 * CMBS Investor Reporting Generator — Generate CREFC-style investor reports
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
 * Generates a CREFC-style (CRE Finance Council) investor reporting template
 * with all key metrics: deal summary, collateral performance, delinquency,
 * special servicing, loss analysis, credit support, and top-10 loans.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Investor_Reporting_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cmbs_investor_reporting_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Investor Reporting Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate a CREFC-style investor reporting template with deal summary, delinquency breakdown, special servicing, loss analysis, credit support, and top-10 loan details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_name'                 => array(
					'type'        => 'string',
					'description' => __( 'Name of the CMBS deal.', 'mcp-ai-wpoos-pro' ),
				),
				'reporting_date'            => array(
					'type'        => 'string',
					'description' => __( 'Reporting date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'pool_balance'              => array(
					'type'        => 'number',
					'description' => __( 'Current pool balance.', 'mcp-ai-wpoos-pro' ),
				),
				'original_balance'          => array(
					'type'        => 'number',
					'description' => __( 'Original pool balance at issuance.', 'mcp-ai-wpoos-pro' ),
				),
				'num_loans'                 => array(
					'type'        => 'integer',
					'description' => __( 'Number of loans in pool.', 'mcp-ai-wpoos-pro' ),
				),
				'delinquency_summary'       => array(
					'type'        => 'array',
					'description' => __( 'Array of delinquency status objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'status'  => array(
								'type'        => 'string',
								'description' => __( 'Delinquency status (current, 30day, 60day, 90plus, foreclosure, reo).', 'mcp-ai-wpoos-pro' ),
							),
							'count'   => array(
								'type'        => 'integer',
								'description' => __( 'Number of loans in this status.', 'mcp-ai-wpoos-pro' ),
							),
							'balance' => array(
								'type'        => 'number',
								'description' => __( 'Total balance of loans in this status.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'status', 'count', 'balance' ),
					),
				),
				'special_servicing_count'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of loans in special servicing.', 'mcp-ai-wpoos-pro' ),
				),
				'special_servicing_balance' => array(
					'type'        => 'number',
					'description' => __( 'Total balance of specially serviced loans.', 'mcp-ai-wpoos-pro' ),
				),
				'losses_to_date'            => array(
					'type'        => 'number',
					'description' => __( 'Cumulative realized losses to date.', 'mcp-ai-wpoos-pro' ),
				),
				'credit_support_pct'        => array(
					'type'        => 'number',
					'description' => __( 'Current credit support/enhancement as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_dscr'                   => array(
					'type'        => 'number',
					'description' => __( 'Weighted average DSCR.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_ltv'                    => array(
					'type'        => 'number',
					'description' => __( 'Weighted average LTV as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'top_10_loans'              => array(
					'type'        => 'array',
					'description' => __( 'Top 10 loans by balance.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'       => array(
								'type'        => 'number',
								'description' => __( 'Current balance.', 'mcp-ai-wpoos-pro' ),
							),
							'property_type' => array(
								'type'        => 'string',
								'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
							),
							'state'         => array(
								'type'        => 'string',
								'description' => __( 'State location.', 'mcp-ai-wpoos-pro' ),
							),
							'dscr'          => array(
								'type'        => 'number',
								'description' => __( 'Current DSCR.', 'mcp-ai-wpoos-pro' ),
							),
							'ltv'           => array(
								'type'        => 'number',
								'description' => __( 'Current LTV as decimal.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance' ),
					),
				),
			),
			'required'   => array( 'deal_name', 'reporting_date', 'pool_balance', 'original_balance', 'num_loans' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$deal_name        = sanitize_text_field( $arguments['deal_name'] ?? '' );
		$reporting_date   = sanitize_text_field( $arguments['reporting_date'] ?? '' );
		$pool_balance     = (float) ( $arguments['pool_balance'] ?? 0 );
		$original_balance = (float) ( $arguments['original_balance'] ?? 0 );
		$num_loans        = (int) ( $arguments['num_loans'] ?? 0 );
		$delinq_summary   = $arguments['delinquency_summary'] ?? array();
		$ss_count         = (int) ( $arguments['special_servicing_count'] ?? 0 );
		$ss_balance       = (float) ( $arguments['special_servicing_balance'] ?? 0 );
		$losses           = (float) ( $arguments['losses_to_date'] ?? 0 );
		$credit_support   = (float) ( $arguments['credit_support_pct'] ?? 0 );
		$wa_dscr          = (float) ( $arguments['wa_dscr'] ?? 0 );
		$wa_ltv           = (float) ( $arguments['wa_ltv'] ?? 0 );
		$top_10           = $arguments['top_10_loans'] ?? array();

		if ( empty( $deal_name ) ) {
			return new WP_Error( 'invalid_input', __( 'Deal name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $pool_balance <= 0 || $original_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Pool and original balances must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Pool factor.
		$pool_factor = $pool_balance / $original_balance;
		$paydown     = $original_balance - $pool_balance - $losses;
		$paydown_pct = ( $original_balance > 0 ) ? $paydown / $original_balance : 0;

		// Section 1: Deal Summary.
		$deal_summary = array(
			'deal_name'        => $deal_name,
			'reporting_date'   => $reporting_date,
			'original_balance' => $calc::format_currency( $original_balance ),
			'current_balance'  => $calc::format_currency( $pool_balance ),
			'pool_factor'      => round( $pool_factor, 4 ),
			'num_loans'        => $num_loans,
			'total_paydown'    => $calc::format_currency( max( 0, $paydown ) ),
			'paydown_pct'      => $calc::format_percentage( max( 0, $paydown_pct ) ),
		);

		// Section 2: Credit Metrics.
		$credit_metrics = array(
			'wa_dscr'        => round( $wa_dscr, 2 ),
			'wa_ltv'         => $calc::format_percentage( $wa_ltv ),
			'credit_support' => $calc::format_percentage( $credit_support ),
		);

		// DSCR health assessment.
		if ( $wa_dscr >= 1.50 ) {
			$dscr_health = __( 'Strong', 'mcp-ai-wpoos-pro' );
		} elseif ( $wa_dscr >= 1.25 ) {
			$dscr_health = __( 'Adequate', 'mcp-ai-wpoos-pro' );
		} elseif ( $wa_dscr >= 1.10 ) {
			$dscr_health = __( 'Thin', 'mcp-ai-wpoos-pro' );
		} else {
			$dscr_health = __( 'Stressed', 'mcp-ai-wpoos-pro' );
		}
		$credit_metrics['dscr_health'] = $dscr_health;

		// Section 3: Delinquency Breakdown.
		$total_delinquent = 0.0;
		$delinquency_rows = array();

		if ( ! empty( $delinq_summary ) && is_array( $delinq_summary ) ) {
			foreach ( $delinq_summary as $entry ) {
				$status  = sanitize_text_field( $entry['status'] ?? '' );
				$count   = (int) ( $entry['count'] ?? 0 );
				$balance = (float) ( $entry['balance'] ?? 0 );

				if ( 'current' !== $status ) {
					$total_delinquent += $balance;
				}

				$delinquency_rows[] = array(
					'status'   => $status,
					'count'    => $count,
					'balance'  => $calc::format_currency( $balance ),
					'pct_pool' => $calc::format_percentage( ( $pool_balance > 0 ) ? $balance / $pool_balance : 0 ),
				);
			}
		}

		$delinquency_section = array(
			'total_delinquent' => $calc::format_currency( $total_delinquent ),
			'delinquency_rate' => $calc::format_percentage( ( $pool_balance > 0 ) ? $total_delinquent / $pool_balance : 0 ),
			'breakdown'        => $delinquency_rows,
		);

		// Section 4: Special Servicing.
		$ss_pct            = ( $pool_balance > 0 ) ? $ss_balance / $pool_balance : 0;
		$special_servicing = array(
			'count'    => $ss_count,
			'balance'  => $calc::format_currency( $ss_balance ),
			'pct_pool' => $calc::format_percentage( $ss_pct ),
		);

		if ( $ss_pct > 0.10 ) {
			$special_servicing['assessment'] = __( 'Elevated - material credit concern', 'mcp-ai-wpoos-pro' );
		} elseif ( $ss_pct > 0.05 ) {
			$special_servicing['assessment'] = __( 'Moderate - above market average', 'mcp-ai-wpoos-pro' );
		} elseif ( $ss_pct > 0.02 ) {
			$special_servicing['assessment'] = __( 'Normal - within market range', 'mcp-ai-wpoos-pro' );
		} else {
			$special_servicing['assessment'] = __( 'Low - minimal special servicing', 'mcp-ai-wpoos-pro' );
		}

		// Section 5: Loss Analysis.
		$loss_pct     = ( $original_balance > 0 ) ? $losses / $original_balance : 0;
		$loss_section = array(
			'cumulative_losses'           => $calc::format_currency( $losses ),
			'loss_pct_of_original'        => $calc::format_percentage( $loss_pct ),
			'remaining_credit_support'    => $calc::format_percentage( $credit_support ),
			'credit_support_after_losses' => $calc::format_percentage( max( 0, $credit_support - $loss_pct ) ),
		);

		// Section 6: Top 10 Loans.
		$top_10_rows    = array();
		$top_10_balance = 0.0;
		if ( ! empty( $top_10 ) && is_array( $top_10 ) ) {
			foreach ( $top_10 as $idx => $loan ) {
				$l_name    = sanitize_text_field( $loan['name'] ?? '' );
				$l_balance = (float) ( $loan['balance'] ?? 0 );
				$l_type    = sanitize_text_field( $loan['property_type'] ?? 'N/A' );
				$l_state   = sanitize_text_field( $loan['state'] ?? 'N/A' );
				$l_dscr    = (float) ( $loan['dscr'] ?? 0 );
				$l_ltv     = (float) ( $loan['ltv'] ?? 0 );

				$top_10_balance += $l_balance;

				$top_10_rows[] = array(
					'rank'          => $idx + 1,
					'name'          => $l_name,
					'balance'       => $calc::format_currency( $l_balance ),
					'pct_pool'      => $calc::format_percentage( ( $pool_balance > 0 ) ? $l_balance / $pool_balance : 0 ),
					'property_type' => $l_type,
					'state'         => $l_state,
					'dscr'          => round( $l_dscr, 2 ),
					'ltv'           => ( $l_ltv > 0 ) ? $calc::format_percentage( $l_ltv ) : 'N/A',
				);
			}
		}

		$top_10_section = array(
			'total_balance' => $calc::format_currency( $top_10_balance ),
			'pct_pool'      => $calc::format_percentage( ( $pool_balance > 0 ) ? $top_10_balance / $pool_balance : 0 ),
			'concentration' => ( ( $pool_balance > 0 ) ? $top_10_balance / $pool_balance : 0 ) > 0.50
				? __( 'Concentrated - top 10 exceed 50% of pool', 'mcp-ai-wpoos-pro' )
				: __( 'Diversified - top 10 below 50% of pool', 'mcp-ai-wpoos-pro' ),
			'loans'         => $top_10_rows,
		);

		// Overall deal health.
		$risk_flags = array();
		if ( ( $pool_balance > 0 ) && ( $total_delinquent / $pool_balance ) > 0.05 ) {
			$risk_flags[] = __( 'Delinquency rate exceeds 5%', 'mcp-ai-wpoos-pro' );
		}
		if ( $ss_pct > 0.10 ) {
			$risk_flags[] = __( 'Special servicing exceeds 10% of pool', 'mcp-ai-wpoos-pro' );
		}
		if ( $wa_dscr < 1.20 ) {
			$risk_flags[] = __( 'WA DSCR below 1.20x', 'mcp-ai-wpoos-pro' );
		}
		if ( $wa_ltv > 0.75 ) {
			$risk_flags[] = __( 'WA LTV exceeds 75%', 'mcp-ai-wpoos-pro' );
		}
		if ( $loss_pct > 0.02 ) {
			$risk_flags[] = __( 'Cumulative losses exceed 2% of original balance', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: deal name, 2: reporting date */
				__( 'CREFC-style investor report generated for %1$s as of %2$s.', 'mcp-ai-wpoos-pro' ),
				$deal_name,
				$reporting_date
			),
			'data'    => array(
				'report_type'       => 'CREFC Investor Report',
				'deal_summary'      => $deal_summary,
				'credit_metrics'    => $credit_metrics,
				'delinquency'       => $delinquency_section,
				'special_servicing' => $special_servicing,
				'loss_analysis'     => $loss_section,
				'top_10_loans'      => $top_10_section,
				'risk_flags'        => $risk_flags,
				'disclaimer'        => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
