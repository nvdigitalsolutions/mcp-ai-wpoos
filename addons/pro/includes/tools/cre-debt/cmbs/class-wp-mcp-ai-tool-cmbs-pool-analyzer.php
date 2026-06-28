<?php
/**
 * CMBS Pool Analyzer — Analyze a CMBS collateral pool for concentration and credit metrics
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
 * Analyzes a CMBS collateral pool: WA DSCR, WA LTV, WA rate, WA maturity,
 * property type and geographic concentration, top-10 loans, DSCR/LTV
 * distribution buckets, and Herfindahl index.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Pool_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cmbs_pool_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Pool Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze a CMBS loan pool for weighted average metrics, property type and geographic concentration, top-10 loans, and DSCR/LTV distribution buckets.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Array of loan objects in the pool.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'loan_name'     => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'       => array(
								'type'        => 'number',
								'description' => __( 'Current loan balance.', 'mcp-ai-wpoos-pro' ),
							),
							'property_type' => array(
								'type'        => 'string',
								'description' => __( 'Property type (office, retail, multifamily, industrial, hotel, mixed_use, other).', 'mcp-ai-wpoos-pro' ),
							),
							'state'         => array(
								'type'        => 'string',
								'description' => __( 'State abbreviation (e.g. CA, NY, TX).', 'mcp-ai-wpoos-pro' ),
							),
							'dscr'          => array(
								'type'        => 'number',
								'description' => __( 'Debt Service Coverage Ratio.', 'mcp-ai-wpoos-pro' ),
							),
							'ltv'           => array(
								'type'        => 'number',
								'description' => __( 'Loan-to-Value ratio as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'maturity_date' => array(
								'type'        => 'string',
								'description' => __( 'Maturity date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
							),
							'rate'          => array(
								'type'        => 'number',
								'description' => __( 'Interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'loan_name', 'balance', 'property_type', 'dscr', 'ltv', 'rate' ),
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
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

		$total_balance        = 0.0;
		$wa_dscr_num          = 0.0;
		$wa_ltv_num           = 0.0;
		$wa_rate_num          = 0.0;
		$wa_maturity_num      = 0.0;
		$maturity_count       = 0;
		$property_type_totals = array();
		$geo_totals           = array();
		$parsed_loans         = array();

		$now = time();

		foreach ( $loans as $loan ) {
			$balance       = (float) ( $loan['balance'] ?? 0 );
			$dscr          = (float) ( $loan['dscr'] ?? 0 );
			$ltv           = (float) ( $loan['ltv'] ?? 0 );
			$rate          = (float) ( $loan['rate'] ?? 0 );
			$property_type = sanitize_text_field( $loan['property_type'] ?? 'other' );
			$state         = strtoupper( sanitize_text_field( $loan['state'] ?? 'N/A' ) );
			$loan_name     = sanitize_text_field( $loan['loan_name'] ?? 'Unnamed' );
			$maturity      = sanitize_text_field( $loan['maturity_date'] ?? '' );

			if ( $balance <= 0 ) {
				continue;
			}

			$total_balance += $balance;
			$wa_dscr_num   += $dscr * $balance;
			$wa_ltv_num    += $ltv * $balance;
			$wa_rate_num   += $rate * $balance;

			if ( ! empty( $maturity ) ) {
				$mat_ts = strtotime( $maturity );
				if ( false !== $mat_ts ) {
					$months_to_mat    = max( 0, ( $mat_ts - $now ) / ( 30.44 * 86400 ) );
					$wa_maturity_num += $months_to_mat * $balance;
					++$maturity_count;
				}
			}

			if ( ! isset( $property_type_totals[ $property_type ] ) ) {
				$property_type_totals[ $property_type ] = 0.0;
			}
			$property_type_totals[ $property_type ] += $balance;

			if ( ! isset( $geo_totals[ $state ] ) ) {
				$geo_totals[ $state ] = 0.0;
			}
			$geo_totals[ $state ] += $balance;

			$parsed_loans[] = array(
				'loan_name'     => $loan_name,
				'balance'       => $balance,
				'property_type' => $property_type,
				'state'         => $state,
				'dscr'          => $dscr,
				'ltv'           => $ltv,
				'rate'          => $rate,
				'maturity_date' => $maturity,
			);
		}

		if ( $total_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Total pool balance must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		// Weighted averages.
		$wa_dscr     = $wa_dscr_num / $total_balance;
		$wa_ltv      = $wa_ltv_num / $total_balance;
		$wa_rate     = $wa_rate_num / $total_balance;
		$wa_maturity = ( $maturity_count > 0 ) ? $wa_maturity_num / $total_balance : 0;

		// Top 10 loans by balance.
		usort(
			$parsed_loans,
			function ( $a, $b ) {
				return $b['balance'] <=> $a['balance'];
			}
		);

		$top_10         = array_slice( $parsed_loans, 0, 10 );
		$top_10_balance = 0.0;
		$top_10_output  = array();
		foreach ( $top_10 as $tl ) {
			$top_10_balance += $tl['balance'];
			$top_10_output[] = array(
				'loan_name' => $tl['loan_name'],
				'balance'   => $calc::format_currency( $tl['balance'] ),
				'pct_pool'  => $calc::format_percentage( $tl['balance'] / $total_balance ),
				'dscr'      => round( $tl['dscr'], 2 ),
				'ltv'       => $calc::format_percentage( $tl['ltv'] ),
				'type'      => $tl['property_type'],
			);
		}

		// Property type concentration.
		arsort( $property_type_totals );
		$property_concentration = array();
		foreach ( $property_type_totals as $type => $bal ) {
			$property_concentration[] = array(
				'property_type' => $type,
				'balance'       => $calc::format_currency( $bal ),
				'pct_pool'      => $calc::format_percentage( $bal / $total_balance ),
			);
		}

		// Geographic concentration.
		arsort( $geo_totals );
		$geo_concentration = array();
		foreach ( $geo_totals as $st => $bal ) {
			$geo_concentration[] = array(
				'state'    => $st,
				'balance'  => $calc::format_currency( $bal ),
				'pct_pool' => $calc::format_percentage( $bal / $total_balance ),
			);
		}

		// DSCR distribution buckets.
		$dscr_buckets = array(
			'below_1.00' => 0.0,
			'1.00_1.10'  => 0.0,
			'1.10_1.25'  => 0.0,
			'1.25_1.50'  => 0.0,
			'1.50_2.00'  => 0.0,
			'above_2.00' => 0.0,
		);
		foreach ( $parsed_loans as $pl ) {
			$d = $pl['dscr'];
			if ( $d < 1.00 ) {
				$dscr_buckets['below_1.00'] += $pl['balance'];
			} elseif ( $d < 1.10 ) {
				$dscr_buckets['1.00_1.10'] += $pl['balance'];
			} elseif ( $d < 1.25 ) {
				$dscr_buckets['1.10_1.25'] += $pl['balance'];
			} elseif ( $d < 1.50 ) {
				$dscr_buckets['1.25_1.50'] += $pl['balance'];
			} elseif ( $d < 2.00 ) {
				$dscr_buckets['1.50_2.00'] += $pl['balance'];
			} else {
				$dscr_buckets['above_2.00'] += $pl['balance'];
			}
		}

		$dscr_distribution = array();
		foreach ( $dscr_buckets as $bucket => $bal ) {
			$dscr_distribution[] = array(
				'bucket'   => $bucket,
				'balance'  => $calc::format_currency( $bal ),
				'pct_pool' => $calc::format_percentage( $bal / $total_balance ),
			);
		}

		// LTV distribution buckets.
		$ltv_buckets = array(
			'below_50' => 0.0,
			'50_60'    => 0.0,
			'60_70'    => 0.0,
			'70_75'    => 0.0,
			'75_80'    => 0.0,
			'above_80' => 0.0,
		);
		foreach ( $parsed_loans as $pl ) {
			$l = $pl['ltv'];
			if ( $l < 0.50 ) {
				$ltv_buckets['below_50'] += $pl['balance'];
			} elseif ( $l < 0.60 ) {
				$ltv_buckets['50_60'] += $pl['balance'];
			} elseif ( $l < 0.70 ) {
				$ltv_buckets['60_70'] += $pl['balance'];
			} elseif ( $l < 0.75 ) {
				$ltv_buckets['70_75'] += $pl['balance'];
			} elseif ( $l < 0.80 ) {
				$ltv_buckets['75_80'] += $pl['balance'];
			} else {
				$ltv_buckets['above_80'] += $pl['balance'];
			}
		}

		$ltv_distribution = array();
		foreach ( $ltv_buckets as $bucket => $bal ) {
			$ltv_distribution[] = array(
				'bucket'   => $bucket,
				'balance'  => $calc::format_currency( $bal ),
				'pct_pool' => $calc::format_percentage( $bal / $total_balance ),
			);
		}

		// Herfindahl index for loan concentration.
		$herfindahl = 0.0;
		foreach ( $parsed_loans as $pl ) {
			$share       = $pl['balance'] / $total_balance;
			$herfindahl += $share * $share;
		}

		$pool_stats = array(
			'num_loans'          => count( $parsed_loans ),
			'total_balance'      => $calc::format_currency( $total_balance ),
			'wa_dscr'            => round( $wa_dscr, 2 ),
			'wa_ltv'             => $calc::format_percentage( $wa_ltv ),
			'wa_rate'            => $calc::format_percentage( $wa_rate ),
			'wa_maturity_months' => round( $wa_maturity, 1 ),
			'avg_loan_size'      => $calc::format_currency( $total_balance / count( $parsed_loans ) ),
			'top_10_pct'         => $calc::format_percentage( $top_10_balance / $total_balance ),
			'herfindahl_index'   => round( $herfindahl, 4 ),
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: loan count, 2: total balance */
				__( 'Pool analysis complete: %1$d loans totaling %2$s.', 'mcp-ai-wpoos-pro' ),
				count( $parsed_loans ),
				$calc::format_currency( $total_balance )
			),
			'data'    => array(
				'pool_stats'               => $pool_stats,
				'top_10_loans'             => $top_10_output,
				'property_concentration'   => $property_concentration,
				'geographic_concentration' => $geo_concentration,
				'dscr_distribution'        => $dscr_distribution,
				'ltv_distribution'         => $ltv_distribution,
				'disclaimer'               => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
