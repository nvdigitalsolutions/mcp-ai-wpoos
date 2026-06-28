<?php
/**
 * CRE Fund Portfolio Dashboard — Aggregate portfolio metrics and exposure analysis
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
 * Calculates aggregate fund portfolio metrics including AUM, weighted average
 * rate/DSCR/LTV, concentration exposures, maturity schedule, and performance breakdown.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Fund_Portfolio_Dashboard implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_fund_portfolio_dashboard';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Fund Portfolio Dashboard', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate a comprehensive fund portfolio dashboard with total AUM, weighted average rate/DSCR/LTV, exposure breakdowns by property type and geography, maturity schedule, and performing vs non-performing analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'fund_name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the fund.', 'mcp-ai-wpoos-pro' ),
				),
				'loans'     => array(
					'type'        => 'array',
					'description' => __( 'Array of loan objects in the portfolio.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'       => array(
								'type'        => 'number',
								'description' => __( 'Current outstanding balance.', 'mcp-ai-wpoos-pro' ),
							),
							'property_type' => array(
								'type'        => 'string',
								'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
							),
							'state'         => array(
								'type'        => 'string',
								'description' => __( 'State abbreviation.', 'mcp-ai-wpoos-pro' ),
							),
							'rate'          => array(
								'type'        => 'number',
								'description' => __( 'Annual interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'dscr'          => array(
								'type'        => 'number',
								'description' => __( 'Debt Service Coverage Ratio.', 'mcp-ai-wpoos-pro' ),
							),
							'ltv'           => array(
								'type'        => 'number',
								'description' => __( 'Loan-to-Value as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'maturity_date' => array(
								'type'        => 'string',
								'description' => __( 'Maturity date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'status'        => array(
								'type'        => 'string',
								'description' => __( 'Loan status.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'performing', 'watchlist', 'special_servicing', 'non_performing', 'reo' ),
							),
						),
						'required'   => array( 'name', 'balance', 'property_type', 'state', 'rate', 'dscr', 'ltv', 'maturity_date', 'status' ),
					),
				),
			),
			'required'   => array( 'fund_name', 'loans' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$fund_name = sanitize_text_field( $arguments['fund_name'] ?? '' );
		$loans     = $arguments['loans'] ?? array();

		if ( empty( $loans ) || ! is_array( $loans ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one loan is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$total_balance     = 0.0;
		$wa_rate_num       = 0.0;
		$wa_dscr_num       = 0.0;
		$wa_ltv_num        = 0.0;
		$property_exposure = array();
		$state_exposure    = array();
		$maturity_schedule = array();
		$status_breakdown  = array();
		$loan_count        = count( $loans );

		foreach ( $loans as $loan ) {
			$balance       = (float) ( $loan['balance'] ?? 0 );
			$rate          = (float) ( $loan['rate'] ?? 0 );
			$dscr          = (float) ( $loan['dscr'] ?? 0 );
			$ltv           = (float) ( $loan['ltv'] ?? 0 );
			$property_type = sanitize_text_field( $loan['property_type'] ?? 'other' );
			$state         = sanitize_text_field( $loan['state'] ?? 'unknown' );
			$maturity      = sanitize_text_field( $loan['maturity_date'] ?? '' );
			$status        = sanitize_text_field( $loan['status'] ?? 'performing' );

			$total_balance += $balance;
			$wa_rate_num   += $rate * $balance;
			$wa_dscr_num   += $dscr * $balance;
			$wa_ltv_num    += $ltv * $balance;

			// Property type exposure.
			if ( ! isset( $property_exposure[ $property_type ] ) ) {
				$property_exposure[ $property_type ] = 0.0;
			}
			$property_exposure[ $property_type ] += $balance;

			// State exposure.
			if ( ! isset( $state_exposure[ $state ] ) ) {
				$state_exposure[ $state ] = 0.0;
			}
			$state_exposure[ $state ] += $balance;

			// Status breakdown.
			if ( ! isset( $status_breakdown[ $status ] ) ) {
				$status_breakdown[ $status ] = array(
					'count'   => 0,
					'balance' => 0.0,
				);
			}
			++$status_breakdown[ $status ]['count'];
			$status_breakdown[ $status ]['balance'] += $balance;

			// Maturity schedule (bucket by year).
			if ( $maturity ) {
				$year = substr( $maturity, 0, 4 );
				if ( ! isset( $maturity_schedule[ $year ] ) ) {
					$maturity_schedule[ $year ] = array(
						'count'   => 0,
						'balance' => 0.0,
					);
				}
				++$maturity_schedule[ $year ]['count'];
				$maturity_schedule[ $year ]['balance'] += $balance;
			}
		}

		$wa_rate       = ( $total_balance > 0 ) ? $wa_rate_num / $total_balance : 0;
		$wa_dscr       = ( $total_balance > 0 ) ? $wa_dscr_num / $total_balance : 0;
		$wa_ltv        = ( $total_balance > 0 ) ? $wa_ltv_num / $total_balance : 0;
		$avg_loan_size = ( $loan_count > 0 ) ? $total_balance / $loan_count : 0;

		// Convert exposures to percentages.
		$property_pct = array();
		foreach ( $property_exposure as $type => $bal ) {
			$property_pct[ $type ] = array(
				'balance'    => $calc::format_currency( $bal ),
				'percentage' => ( $total_balance > 0 ) ? $calc::format_percentage( $bal / $total_balance ) : '0.00%',
			);
		}

		$state_pct = array();
		foreach ( $state_exposure as $st => $bal ) {
			$state_pct[ $st ] = array(
				'balance'    => $calc::format_currency( $bal ),
				'percentage' => ( $total_balance > 0 ) ? $calc::format_percentage( $bal / $total_balance ) : '0.00%',
			);
		}

		// Format status breakdown.
		$status_fmt = array();
		foreach ( $status_breakdown as $st => $info ) {
			$status_fmt[ $st ] = array(
				'count'      => $info['count'],
				'balance'    => $calc::format_currency( $info['balance'] ),
				'percentage' => ( $total_balance > 0 ) ? $calc::format_percentage( $info['balance'] / $total_balance ) : '0.00%',
			);
		}

		// Performing vs non-performing.
		$performing_balance     = ( $status_breakdown['performing']['balance'] ?? 0 ) + ( $status_breakdown['watchlist']['balance'] ?? 0 );
		$non_performing_balance = $total_balance - $performing_balance;

		// Format maturity schedule.
		ksort( $maturity_schedule );
		$maturity_fmt = array();
		foreach ( $maturity_schedule as $year => $info ) {
			$maturity_fmt[ $year ] = array(
				'count'      => $info['count'],
				'balance'    => $calc::format_currency( $info['balance'] ),
				'percentage' => ( $total_balance > 0 ) ? $calc::format_percentage( $info['balance'] / $total_balance ) : '0.00%',
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Portfolio dashboard generated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'fund_name'              => $fund_name,
				'total_aum'              => $calc::format_currency( $total_balance ),
				'total_aum_raw'          => round( $total_balance, 2 ),
				'num_loans'              => $loan_count,
				'avg_loan_size'          => $calc::format_currency( $avg_loan_size ),
				'wa_rate'                => $calc::format_percentage( $wa_rate ),
				'wa_dscr'                => round( $wa_dscr, 2 ) . 'x',
				'wa_ltv'                 => $calc::format_percentage( $wa_ltv ),
				'property_type_exposure' => $property_pct,
				'geographic_exposure'    => $state_pct,
				'maturity_schedule'      => $maturity_fmt,
				'status_breakdown'       => $status_fmt,
				'performing_balance'     => $calc::format_currency( $performing_balance ),
				'non_performing_balance' => $calc::format_currency( $non_performing_balance ),
				'performing_pct'         => ( $total_balance > 0 ) ? $calc::format_percentage( $performing_balance / $total_balance ) : '0.00%',
			),
		);
	}
}
