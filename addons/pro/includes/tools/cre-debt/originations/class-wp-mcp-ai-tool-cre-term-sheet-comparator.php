<?php
/**
 * CRE Term Sheet Comparator — Compare multiple lender term sheets and rank by total cost
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
 * Compares multiple lender term sheets side-by-side, calculating all-in cost,
 * DSCR, LTV, total interest, flexibility score, and overall ranking.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_term_sheet_comparator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Term Sheet Comparator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Compare multiple lender term sheets side-by-side. Calculates all-in cost, DSCR, LTV, total interest, flexibility score, and produces a ranked recommendation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_amount'    => array(
					'type'        => 'number',
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'            => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'property_value' => array(
					'type'        => 'number',
					'description' => __( 'Property value.', 'mcp-ai-wpoos-pro' ),
				),
				'term_sheets'    => array(
					'type'        => 'array',
					'description' => __( 'Array of lender term sheets.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'lender'              => array(
								'type'        => 'string',
								'description' => __( 'Lender name.', 'mcp-ai-wpoos-pro' ),
							),
							'rate'                => array(
								'type'        => 'number',
								'description' => __( 'Interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'spread_bps'          => array(
								'type'        => 'number',
								'description' => __( 'Spread in basis points.', 'mcp-ai-wpoos-pro' ),
							),
							'io_months'           => array(
								'type'        => 'integer',
								'description' => __( 'IO period months.', 'mcp-ai-wpoos-pro' ),
							),
							'amort_months'        => array(
								'type'        => 'integer',
								'description' => __( 'Amortization months.', 'mcp-ai-wpoos-pro' ),
							),
							'term_months'         => array(
								'type'        => 'integer',
								'description' => __( 'Loan term months.', 'mcp-ai-wpoos-pro' ),
							),
							'origination_fee_pct' => array(
								'type'        => 'number',
								'description' => __( 'Origination fee %.', 'mcp-ai-wpoos-pro' ),
							),
							'exit_fee_pct'        => array(
								'type'        => 'number',
								'description' => __( 'Exit fee %.', 'mcp-ai-wpoos-pro' ),
							),
							'prepayment_type'     => array(
								'type' => 'string',
								'enum' => array( 'none', 'lockout', 'defeasance', 'yield_maintenance', 'step_down' ),
							),
							'recourse'            => array(
								'type' => 'string',
								'enum' => array( 'full', 'partial', 'non_recourse' ),
							),
						),
					),
				),
			),
			'required'   => array( 'loan_amount', 'noi', 'property_value', 'term_sheets' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loan_amount    = (float) ( $arguments['loan_amount'] ?? 0 );
		$noi            = (float) ( $arguments['noi'] ?? 0 );
		$property_value = (float) ( $arguments['property_value'] ?? 0 );
		$sheets_raw     = $arguments['term_sheets'] ?? array();

		if ( $loan_amount <= 0 || $noi <= 0 || $property_value <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan amount, NOI, and property value must be positive.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $sheets_raw ) || ! is_array( $sheets_raw ) || count( $sheets_raw ) < 2 ) {
			return new WP_Error( 'invalid_input', __( 'At least two term sheets are required for comparison.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;
		$ltv  = $calc::calculate_ltv( $loan_amount, $property_value );

		$comparisons = array();

		foreach ( $sheets_raw as $sheet ) {
			$lender   = sanitize_text_field( $sheet['lender'] ?? 'Unknown' );
			$rate     = (float) ( $sheet['rate'] ?? 0 );
			$spread   = (float) ( $sheet['spread_bps'] ?? 0 );
			$io       = (int) ( $sheet['io_months'] ?? 0 );
			$amort    = (int) ( $sheet['amort_months'] ?? 360 );
			$term     = (int) ( $sheet['term_months'] ?? 120 );
			$orig_fee = (float) ( $sheet['origination_fee_pct'] ?? 0 );
			$exit_fee = (float) ( $sheet['exit_fee_pct'] ?? 0 );
			$prepay   = sanitize_text_field( $sheet['prepayment_type'] ?? 'none' );
			$recourse = sanitize_text_field( $sheet['recourse'] ?? 'non_recourse' );

			if ( $rate <= 0 || $amort <= 0 || $term <= 0 ) {
				continue;
			}

			// Amortization and debt service.
			$schedule       = $calc::generate_amortization_schedule( $loan_amount, $rate, $term, $amort, $io );
			$monthly_pi     = $calc::calculate_monthly_payment( $loan_amount, $rate, $amort );
			$monthly_io     = $calc::calculate_io_payment( $loan_amount, $rate );
			$annual_ds      = ( $io > 0 ) ? $monthly_io * 12 : $monthly_pi * 12;
			$dscr           = $calc::calculate_dscr( $noi, $annual_ds );
			$debt_yield     = $calc::calculate_debt_yield( $noi, $loan_amount );
			$total_interest = $schedule['total_interest'];

			// Fees.
			$orig_cost  = $loan_amount * ( $orig_fee / 100 );
			$exit_cost  = $loan_amount * ( $exit_fee / 100 );
			$total_fees = $orig_cost + $exit_cost;

			// All-in cost = total interest + fees.
			$all_in_cost = $total_interest + $total_fees;

			// Flexibility score (0-100): IO period, prepayment flexibility, recourse.
			$flex_score = $this->calculate_flexibility_score( $io, $term, $prepay, $recourse );

			$comparisons[] = array(
				'lender'          => $lender,
				'rate'            => $calc::format_percentage( $rate ),
				'spread_bps'      => $spread,
				'io_months'       => $io,
				'amort_months'    => $amort,
				'term_months'     => $term,
				'dscr'            => round( $dscr, 2 ) . 'x',
				'ltv'             => $calc::format_percentage( $ltv ),
				'debt_yield'      => $calc::format_percentage( $debt_yield ),
				'monthly_payment' => $calc::format_currency( ( $io > 0 ) ? $monthly_io : $monthly_pi ),
				'total_interest'  => $calc::format_currency( $total_interest ),
				'total_fees'      => $calc::format_currency( $total_fees ),
				'all_in_cost'     => $calc::format_currency( $all_in_cost ),
				'balloon'         => $calc::format_currency( $schedule['balloon_payment'] ),
				'prepayment'      => $prepay,
				'recourse'        => $recourse,
				'flexibility'     => $flex_score,
				'_sort_cost'      => $all_in_cost,
				'_sort_flex'      => $flex_score,
			);
		}

		if ( empty( $comparisons ) ) {
			return new WP_Error( 'invalid_input', __( 'No valid term sheets to compare.', 'mcp-ai-wpoos-pro' ) );
		}

		// Rank by all-in cost (lower is better).
		usort(
			$comparisons,
			function ( $a, $b ) {
				return $a['_sort_cost'] <=> $b['_sort_cost'];
			}
		);

		// Assign ranks and clean up sort keys.
		$ranked = array();
		foreach ( $comparisons as $rank => $comp ) {
			unset( $comp['_sort_cost'], $comp['_sort_flex'] );
			$comp['cost_rank']      = $rank + 1;
			$comp['recommendation'] = ( 0 === $rank ) ? __( 'BEST VALUE', 'mcp-ai-wpoos-pro' ) : '';
			$ranked[]               = $comp;
		}

		// Find best flexibility.
		$best_flex        = 0;
		$best_flex_lender = '';
		foreach ( $ranked as $r ) {
			if ( $r['flexibility'] > $best_flex ) {
				$best_flex        = $r['flexibility'];
				$best_flex_lender = $r['lender'];
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Term sheet comparison complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'comparison_count'     => count( $ranked ),
				'best_value_lender'    => $ranked[0]['lender'],
				'most_flexible_lender' => $best_flex_lender,
				'term_sheets'          => $ranked,
			),
		);
	}

	/**
	 * Calculate flexibility score (0-100).
	 *
	 * @param int    $io_months       IO period months.
	 * @param int    $term_months     Loan term months.
	 * @param string $prepayment_type Prepayment type.
	 * @param string $recourse        Recourse type.
	 * @return int
	 */
	private function calculate_flexibility_score( int $io_months, int $term_months, string $prepayment_type, string $recourse ): int {
		$score = 0;

		// IO period (up to 30 pts) — more IO = more flexibility.
		$io_ratio = ( $term_months > 0 ) ? $io_months / $term_months : 0;
		if ( $io_ratio >= 0.50 ) {
			$score += 30;
		} elseif ( $io_ratio >= 0.25 ) {
			$score += 20;
		} elseif ( $io_months > 0 ) {
			$score += 10;
		}

		// Prepayment flexibility (up to 40 pts).
		$prepay_scores = array(
			'none'              => 40,
			'step_down'         => 30,
			'yield_maintenance' => 20,
			'defeasance'        => 10,
			'lockout'           => 0,
		);
		$score        += $prepay_scores[ $prepayment_type ] ?? 15;

		// Recourse (up to 30 pts) — non-recourse most favorable to borrower.
		$recourse_scores = array(
			'non_recourse' => 30,
			'partial'      => 20,
			'full'         => 5,
		);
		$score          += $recourse_scores[ $recourse ] ?? 10;

		return min( 100, $score );
	}
}
