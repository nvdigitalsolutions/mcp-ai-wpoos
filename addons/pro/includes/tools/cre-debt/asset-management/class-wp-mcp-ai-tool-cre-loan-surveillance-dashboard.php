<?php
/**
 * CRE Loan Surveillance Dashboard — Aggregate loan portfolio metrics for surveillance reporting
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
 * Aggregates loan portfolio metrics for surveillance reporting. Analyzes
 * payment status, DSCR coverage, occupancy, reporting compliance, maturity
 * risk, and inspection schedules across the loan book.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Loan_Surveillance_Dashboard implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_loan_surveillance_dashboard';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Loan Surveillance Dashboard', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Aggregate loan portfolio metrics for surveillance reporting. Analyzes payment status, DSCR coverage, occupancy, reporting compliance, maturity risk, and inspection schedules across the loan book.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Array of loan objects for surveillance analysis.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'                        => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'                     => array(
								'type'        => 'number',
								'description' => __( 'Current outstanding loan balance.', 'mcp-ai-wpoos-pro' ),
							),
							'payment_status'              => array(
								'type'        => 'string',
								'description' => __( 'Payment status of the loan.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'current', '30day', '60day', '90plus', 'default' ),
							),
							'dscr'                        => array(
								'type'        => 'number',
								'description' => __( 'Debt service coverage ratio.', 'mcp-ai-wpoos-pro' ),
							),
							'occupancy_pct'               => array(
								'type'        => 'number',
								'description' => __( 'Current occupancy percentage (0-100).', 'mcp-ai-wpoos-pro' ),
							),
							'financial_reporting_current' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether financial reporting is current. Default true.', 'mcp-ai-wpoos-pro' ),
								'default'     => true,
							),
							'maturity_date'               => array(
								'type'        => 'string',
								'description' => __( 'Loan maturity date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'last_inspection_date'        => array(
								'type'        => 'string',
								'description' => __( 'Date of last property inspection (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance', 'payment_status', 'dscr', 'occupancy_pct', 'maturity_date' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loans = $arguments['loans'] ?? array();
		if ( empty( $loans ) || ! is_array( $loans ) ) {
			return new WP_Error( 'invalid_input', __( 'loans array is required and must not be empty.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc       = WP_MCP_AI_CRE_Debt_Calculator::class;
		$today      = gmdate( 'Y-m-d' );
		$today_ts   = strtotime( $today );
		$loan_count = count( $loans );

		// Accumulators.
		$total_book          = 0.0;
		$delinquent_balance  = 0.0;
		$delinquent_count    = 0;
		$weighted_dscr_sum   = 0.0;
		$below_1x_count      = 0;
		$below_1x_balance    = 0.0;
		$below_1_25x_count   = 0;
		$below_1_25x_balance = 0.0;
		$reporting_current   = 0;
		$non_compliant_loans = array();

		$status_breakdown = array(
			'current' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'30day'   => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'60day'   => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'90plus'  => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'default' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
		);

		$occupancy_dist = array(
			'below_80' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'80_to_90' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'90_to_95' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'above_95' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
		);

		$maturity_6mo  = array(
			'count'   => 0,
			'balance' => 0.0,
		);
		$maturity_12mo = array(
			'count'   => 0,
			'balance' => 0.0,
		);
		$maturity_24mo = array(
			'count'   => 0,
			'balance' => 0.0,
		);
		$overdue_loans = array();
		$loan_details  = array();

		foreach ( $loans as $loan ) {
			$name              = sanitize_text_field( $loan['name'] ?? '' );
			$balance           = (float) ( $loan['balance'] ?? 0 );
			$payment_status    = sanitize_text_field( $loan['payment_status'] ?? 'current' );
			$dscr              = (float) ( $loan['dscr'] ?? 0 );
			$occupancy_pct     = (float) ( $loan['occupancy_pct'] ?? 0 );
			$reporting_flag    = $loan['financial_reporting_current'] ?? true;
			$maturity_date_str = sanitize_text_field( $loan['maturity_date'] ?? '' );
			$inspection_str    = sanitize_text_field( $loan['last_inspection_date'] ?? '' );

			$total_book        += $balance;
			$weighted_dscr_sum += $dscr * $balance;

			// Payment status.
			if ( 'current' !== $payment_status ) {
				$delinquent_balance += $balance;
				++$delinquent_count;
			}
			if ( isset( $status_breakdown[ $payment_status ] ) ) {
				++$status_breakdown[ $payment_status ]['count'];
				$status_breakdown[ $payment_status ]['balance'] += $balance;
			}

			// DSCR coverage.
			if ( $dscr < 1.0 ) {
				++$below_1x_count;
				$below_1x_balance += $balance;
			}
			if ( $dscr < 1.25 ) {
				++$below_1_25x_count;
				$below_1_25x_balance += $balance;
			}

			// Occupancy distribution.
			if ( $occupancy_pct < 80 ) {
				++$occupancy_dist['below_80']['count'];
				$occupancy_dist['below_80']['balance'] += $balance;
			} elseif ( $occupancy_pct < 90 ) {
				++$occupancy_dist['80_to_90']['count'];
				$occupancy_dist['80_to_90']['balance'] += $balance;
			} elseif ( $occupancy_pct < 95 ) {
				++$occupancy_dist['90_to_95']['count'];
				$occupancy_dist['90_to_95']['balance'] += $balance;
			} else {
				++$occupancy_dist['above_95']['count'];
				$occupancy_dist['above_95']['balance'] += $balance;
			}

			// Reporting compliance.
			if ( $reporting_flag ) {
				++$reporting_current;
			} else {
				$non_compliant_loans[] = $name;
			}

			// Maturity analysis.
			$maturity_ts        = strtotime( $maturity_date_str );
			$months_to_maturity = 0;
			if ( $maturity_ts && $today_ts ) {
				$months_to_maturity = (int) round( ( $maturity_ts - $today_ts ) / ( DAY_IN_SECONDS * 30.44 ) );
			}

			if ( $months_to_maturity <= 6 ) {
				++$maturity_6mo['count'];
				$maturity_6mo['balance'] += $balance;
			}
			if ( $months_to_maturity <= 12 ) {
				++$maturity_12mo['count'];
				$maturity_12mo['balance'] += $balance;
			}
			if ( $months_to_maturity <= 24 ) {
				++$maturity_24mo['count'];
				$maturity_24mo['balance'] += $balance;
			}

			// Inspection analysis.
			$inspection_overdue = false;
			if ( empty( $inspection_str ) ) {
				$inspection_overdue = true;
			} else {
				$inspection_ts = strtotime( $inspection_str );
				if ( $inspection_ts && ( $today_ts - $inspection_ts ) > ( 365 * DAY_IN_SECONDS ) ) {
					$inspection_overdue = true;
				}
			}
			if ( $inspection_overdue ) {
				$overdue_loans[] = $name;
			}

			$loan_details[] = array(
				'name'               => $name,
				'balance'            => $calc::format_currency( $balance ),
				'payment_status'     => $payment_status,
				'dscr'               => round( $dscr, 2 ),
				'occupancy_pct'      => round( $occupancy_pct, 1 ) . '%',
				'reporting_current'  => $reporting_flag,
				'maturity_date'      => $maturity_date_str,
				'months_to_maturity' => $months_to_maturity,
				'last_inspection'    => $inspection_str ? $inspection_str : __( 'N/A', 'mcp-ai-wpoos-pro' ),
				'inspection_overdue' => $inspection_overdue,
			);
		}

		// Computed aggregates.
		$delinquency_rate_dollar = ( $total_book > 0 ) ? $delinquent_balance / $total_book : 0;
		$delinquency_rate_count  = ( $loan_count > 0 ) ? $delinquent_count / $loan_count : 0;
		$avg_dscr                = ( $total_book > 0 ) ? $weighted_dscr_sum / $total_book : 0;
		$reporting_compliance    = ( $loan_count > 0 ) ? ( $reporting_current / $loan_count ) * 100 : 0;

		// Format status breakdown.
		$formatted_status = array();
		foreach ( $status_breakdown as $status => $data ) {
			$formatted_status[ $status ] = array(
				'count'       => $data['count'],
				'balance'     => $calc::format_currency( $data['balance'] ),
				'pct_of_book' => ( $total_book > 0 ) ? $calc::format_percentage( $data['balance'] / $total_book ) : '0.00%',
			);
		}

		// Format occupancy distribution.
		$formatted_occupancy = array();
		foreach ( $occupancy_dist as $bucket => $data ) {
			$formatted_occupancy[ $bucket ] = array(
				'count'   => $data['count'],
				'balance' => $calc::format_currency( $data['balance'] ),
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: loan count */
				__( 'Surveillance dashboard generated for %d loans. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				$loan_count
			),
			'data'       => array(
				'portfolio_summary'      => array(
					'total_loans' => $loan_count,
					'total_book'  => $calc::format_currency( $total_book ),
				),
				'delinquency'            => array(
					'delinquent_balance'      => $calc::format_currency( $delinquent_balance ),
					'delinquency_rate_dollar' => $calc::format_percentage( $delinquency_rate_dollar ),
					'delinquent_count'        => $delinquent_count,
					'delinquency_rate_count'  => $calc::format_percentage( $delinquency_rate_count ),
					'status_breakdown'        => $formatted_status,
				),
				'dscr_coverage'          => array(
					'avg_dscr'            => round( $avg_dscr, 2 ) . 'x',
					'loans_below_1x_dscr' => array(
						'count'   => $below_1x_count,
						'balance' => $calc::format_currency( $below_1x_balance ),
					),
					'loans_below_1_25x'   => array(
						'count'   => $below_1_25x_count,
						'balance' => $calc::format_currency( $below_1_25x_balance ),
					),
				),
				'occupancy_distribution' => $formatted_occupancy,
				'reporting_compliance'   => array(
					'compliance_pct'      => round( $reporting_compliance, 1 ) . '%',
					'non_compliant_loans' => $non_compliant_loans,
				),
				'maturity_analysis'      => array(
					'within_6_months'  => array(
						'count'   => $maturity_6mo['count'],
						'balance' => $calc::format_currency( $maturity_6mo['balance'] ),
					),
					'within_12_months' => array(
						'count'   => $maturity_12mo['count'],
						'balance' => $calc::format_currency( $maturity_12mo['balance'] ),
					),
					'within_24_months' => array(
						'count'   => $maturity_24mo['count'],
						'balance' => $calc::format_currency( $maturity_24mo['balance'] ),
					),
				),
				'inspection_analysis'    => array(
					'overdue_count' => count( $overdue_loans ),
					'overdue_loans' => $overdue_loans,
				),
				'loan_details'           => $loan_details,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
