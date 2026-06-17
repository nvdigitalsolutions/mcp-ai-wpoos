<?php
/**
 * CMBS Surveillance Monitor — Monitor deal performance and delinquency trends
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
 * Monitors CMBS deal performance: delinquency rate breakdowns, watchlist analysis,
 * maturity schedules by year, deteriorating credit metrics, and loans approaching
 * maturity in 12/24 months.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Surveillance_Monitor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cmbs_surveillance_monitor';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Surveillance Monitor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Monitor CMBS deal performance including delinquency rates, watchlist analysis, maturity schedules, deteriorating credit metrics, and loans approaching maturity in 12/24 months.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Array of loan objects in the deal.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'loan_name'      => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'        => array(
								'type'        => 'number',
								'description' => __( 'Current loan balance.', 'mcp-ai-wpoos-pro' ),
							),
							'status'         => array(
								'type'        => 'string',
								'description' => __( 'Delinquency status.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'current', '30day', '60day', '90plus', 'foreclosure', 'reo' ),
							),
							'dscr'           => array(
								'type'        => 'number',
								'description' => __( 'Current DSCR.', 'mcp-ai-wpoos-pro' ),
							),
							'occupancy_pct'  => array(
								'type'        => 'number',
								'description' => __( 'Current occupancy as decimal (e.g. 0.92 for 92%).', 'mcp-ai-wpoos-pro' ),
							),
							'maturity_date'  => array(
								'type'        => 'string',
								'description' => __( 'Maturity date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
							),
							'watchlist_flag' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether the loan is on the servicer watchlist.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'loan_name', 'balance', 'status', 'dscr', 'maturity_date' ),
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loans = $arguments['loans'] ?? array();

		if ( empty( $loans ) || ! is_array( $loans ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one loan is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$total_balance    = 0.0;
		$status_buckets   = array(
			'current'     => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'30day'       => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'60day'       => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'90plus'      => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'foreclosure' => array(
				'count'   => 0,
				'balance' => 0.0,
			),
			'reo'         => array(
				'count'   => 0,
				'balance' => 0.0,
			),
		);
		$watchlist_loans  = array();
		$maturity_by_year = array();
		$deteriorating    = array();
		$maturing_12      = array();
		$maturing_24      = array();

		$now       = time();
		$months_12 = $now + ( 365.25 * 86400 );
		$months_24 = $now + ( 730.5 * 86400 );

		foreach ( $loans as $loan ) {
			$balance       = (float) ( $loan['balance'] ?? 0 );
			$status        = sanitize_text_field( $loan['status'] ?? 'current' );
			$dscr          = (float) ( $loan['dscr'] ?? 0 );
			$occupancy     = (float) ( $loan['occupancy_pct'] ?? 0 );
			$maturity_date = sanitize_text_field( $loan['maturity_date'] ?? '' );
			$watchlist     = ! empty( $loan['watchlist_flag'] );
			$loan_name     = sanitize_text_field( $loan['loan_name'] ?? 'Unnamed' );

			if ( $balance <= 0 ) {
				continue;
			}

			$total_balance += $balance;

			// Delinquency buckets.
			if ( isset( $status_buckets[ $status ] ) ) {
				$status_buckets[ $status ]['count']   += 1;
				$status_buckets[ $status ]['balance'] += $balance;
			}

			// Watchlist.
			if ( $watchlist ) {
				$watchlist_loans[] = array(
					'loan_name'   => $loan_name,
					'balance'     => $calc::format_currency( $balance ),
					'balance_raw' => $balance,
					'dscr'        => round( $dscr, 2 ),
					'occupancy'   => $calc::format_percentage( $occupancy ),
					'status'      => $status,
				);
			}

			// Maturity analysis.
			if ( ! empty( $maturity_date ) ) {
				$mat_ts = strtotime( $maturity_date );
				if ( false !== $mat_ts ) {
					$mat_year = (int) gmdate( 'Y', $mat_ts );
					if ( ! isset( $maturity_by_year[ $mat_year ] ) ) {
						$maturity_by_year[ $mat_year ] = array(
							'count'   => 0,
							'balance' => 0.0,
						);
					}
					$maturity_by_year[ $mat_year ]['count']   += 1;
					$maturity_by_year[ $mat_year ]['balance'] += $balance;

					// Approaching maturity.
					if ( $mat_ts <= $months_12 && $mat_ts > $now ) {
						$maturing_12[] = array(
							'loan_name'     => $loan_name,
							'balance'       => $calc::format_currency( $balance ),
							'maturity_date' => $maturity_date,
							'dscr'          => round( $dscr, 2 ),
						);
					} elseif ( $mat_ts <= $months_24 && $mat_ts > $months_12 ) {
						$maturing_24[] = array(
							'loan_name'     => $loan_name,
							'balance'       => $calc::format_currency( $balance ),
							'maturity_date' => $maturity_date,
							'dscr'          => round( $dscr, 2 ),
						);
					}
				}
			}

			// Deteriorating credit (DSCR below 1.10 or occupancy below 80%).
			if ( $dscr < 1.10 || $occupancy < 0.80 ) {
				$flags = array();
				if ( $dscr < 1.0 ) {
					$flags[] = __( 'DSCR below 1.0x - cash flow negative', 'mcp-ai-wpoos-pro' );
				} elseif ( $dscr < 1.10 ) {
					$flags[] = __( 'DSCR below 1.10x - thin margin', 'mcp-ai-wpoos-pro' );
				}
				if ( $occupancy > 0 && $occupancy < 0.80 ) {
					$flags[] = sprintf(
						/* translators: %s: occupancy percentage */
						__( 'Low occupancy: %s', 'mcp-ai-wpoos-pro' ),
						$calc::format_percentage( $occupancy )
					);
				}
				$deteriorating[] = array(
					'loan_name'  => $loan_name,
					'balance'    => $calc::format_currency( $balance ),
					'dscr'       => round( $dscr, 2 ),
					'occupancy'  => $calc::format_percentage( $occupancy ),
					'risk_flags' => $flags,
				);
			}
		}

		if ( $total_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Total pool balance must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		// Format delinquency summary.
		$delinquency      = array();
		$total_delinquent = 0.0;
		foreach ( $status_buckets as $status => $data ) {
			$delinquency[ $status ] = array(
				'count'    => $data['count'],
				'balance'  => $calc::format_currency( $data['balance'] ),
				'pct_pool' => $calc::format_percentage( $data['balance'] / $total_balance ),
			);
			if ( 'current' !== $status ) {
				$total_delinquent += $data['balance'];
			}
		}

		// Format maturity schedule.
		ksort( $maturity_by_year );
		$maturity_schedule = array();
		foreach ( $maturity_by_year as $year => $data ) {
			$maturity_schedule[] = array(
				'year'     => $year,
				'count'    => $data['count'],
				'balance'  => $calc::format_currency( $data['balance'] ),
				'pct_pool' => $calc::format_percentage( $data['balance'] / $total_balance ),
			);
		}

		// Sort watchlist by balance descending.
		usort(
			$watchlist_loans,
			function ( $a, $b ) {
				return $b['balance_raw'] <=> $a['balance_raw'];
			}
		);

		// Remove raw balance from output.
		$watchlist_output = array_map(
			function ( $wl ) {
				unset( $wl['balance_raw'] );
				return $wl;
			},
			$watchlist_loans
		);

		$watchlist_balance = array_sum( array_column( $watchlist_loans, 'balance_raw' ) );

		$summary = array(
			'total_balance'       => $calc::format_currency( $total_balance ),
			'total_delinquent'    => $calc::format_currency( $total_delinquent ),
			'delinquency_rate'    => $calc::format_percentage( $total_delinquent / $total_balance ),
			'watchlist_count'     => count( $watchlist_output ),
			'watchlist_balance'   => $calc::format_currency( $watchlist_balance ),
			'watchlist_pct'       => $calc::format_percentage( $watchlist_balance / $total_balance ),
			'deteriorating_count' => count( $deteriorating ),
			'maturing_12mo_count' => count( $maturing_12 ),
			'maturing_24mo_count' => count( $maturing_24 ),
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: delinquency rate, 2: watchlist count */
				__( 'Surveillance report: %1$s delinquency rate, %2$d loans on watchlist.', 'mcp-ai-wpoos-pro' ),
				$summary['delinquency_rate'],
				$summary['watchlist_count']
			),
			'data'    => array(
				'summary'            => $summary,
				'delinquency'        => $delinquency,
				'watchlist'          => $watchlist_output,
				'maturity_schedule'  => $maturity_schedule,
				'maturing_12_months' => $maturing_12,
				'maturing_24_months' => $maturing_24,
				'deteriorating'      => $deteriorating,
				'disclaimer'         => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
