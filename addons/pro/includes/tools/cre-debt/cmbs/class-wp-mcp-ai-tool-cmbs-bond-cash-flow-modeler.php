<?php
/**
 * CMBS Bond Cash Flow Modeler — Project monthly cash flows for a CMBS tranche
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
 * Models monthly cash flows for a specific CMBS tranche given pool performance
 * assumptions: scheduled principal, prepayments, defaults, losses, and net
 * cash flow to the tranche using standard CDR/CPR/severity methodology.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Bond_Cash_Flow_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cmbs_bond_cash_flow_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Bond Cash Flow Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Model monthly cash flows for a CMBS tranche using CDR/CPR/loss severity assumptions. Projects scheduled principal, prepayments, defaults, losses, and net cash flow to the tranche.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tranche_balance'   => array(
					'type'        => 'number',
					'description' => __( 'Current tranche balance.', 'mcp-ai-wpoos-pro' ),
				),
				'coupon_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Tranche coupon rate as decimal (e.g. 0.045 for 4.5%).', 'mcp-ai-wpoos-pro' ),
				),
				'pool_balance'      => array(
					'type'        => 'number',
					'description' => __( 'Current pool balance (collateral UPB).', 'mcp-ai-wpoos-pro' ),
				),
				'pool_coupon'       => array(
					'type'        => 'number',
					'description' => __( 'Weighted average coupon on the pool as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'projection_months' => array(
					'type'        => 'integer',
					'description' => __( 'Number of months to project (1-360).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 360,
				),
				'cdr'               => array(
					'type'        => 'number',
					'description' => __( 'Constant Default Rate (annual) as decimal (e.g. 0.02 for 2%).', 'mcp-ai-wpoos-pro' ),
				),
				'cpr'               => array(
					'type'        => 'number',
					'description' => __( 'Constant Prepayment Rate (annual) as decimal (e.g. 0.05 for 5%).', 'mcp-ai-wpoos-pro' ),
				),
				'loss_severity_pct' => array(
					'type'        => 'number',
					'description' => __( 'Loss severity on defaults as decimal (e.g. 0.35 for 35%).', 'mcp-ai-wpoos-pro' ),
				),
				'tranche_position'  => array(
					'type'        => 'string',
					'description' => __( 'Position in the capital stack.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'senior', 'mezzanine', 'junior', 'equity' ),
				),
			),
			'required'   => array( 'tranche_balance', 'coupon_rate', 'pool_balance', 'pool_coupon', 'projection_months', 'cdr', 'cpr', 'loss_severity_pct', 'tranche_position' ),
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

		$tranche_balance   = (float) ( $arguments['tranche_balance'] ?? 0 );
		$coupon_rate       = (float) ( $arguments['coupon_rate'] ?? 0 );
		$pool_balance      = (float) ( $arguments['pool_balance'] ?? 0 );
		$pool_coupon       = (float) ( $arguments['pool_coupon'] ?? 0 );
		$projection_months = (int) ( $arguments['projection_months'] ?? 120 );
		$cdr               = (float) ( $arguments['cdr'] ?? 0.02 );
		$cpr               = (float) ( $arguments['cpr'] ?? 0 );
		$loss_severity     = (float) ( $arguments['loss_severity_pct'] ?? 0.35 );
		$position          = sanitize_text_field( $arguments['tranche_position'] ?? 'senior' );

		if ( $tranche_balance <= 0 || $pool_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Tranche and pool balances must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $pool_coupon <= 0 || $coupon_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Coupon rates must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$projection_months = max( 1, min( 360, $projection_months ) );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Convert annual rates to monthly SMM (Single Monthly Mortality).
		$monthly_default = 1 - pow( 1 - $cdr, 1 / 12 );
		$monthly_prepay  = 1 - pow( 1 - $cpr, 1 / 12 );

		// Loss absorption priority: equity absorbs first, junior second, etc.
		$loss_priority = array(
			'equity'    => 1,
			'junior'    => 2,
			'mezzanine' => 3,
			'senior'    => 4,
		);
		$position_rank = isset( $loss_priority[ $position ] ) ? $loss_priority[ $position ] : 4;

		$current_pool      = $pool_balance;
		$current_tranche   = $tranche_balance;
		$cumulative_loss   = 0.0;
		$sub_below_tranche = $pool_balance - $tranche_balance;
		$total_interest    = 0.0;
		$total_principal   = 0.0;
		$total_loss        = 0.0;
		$schedule          = array();

		// Subordination below this tranche based on position.
		$sub_pct_map = array(
			'senior'    => 0.30,
			'mezzanine' => 0.12,
			'junior'    => 0.04,
			'equity'    => 0.0,
		);
		$sub_below   = $pool_balance * ( $sub_pct_map[ $position ] ?? 0 );

		for ( $month = 1; $month <= $projection_months; $month++ ) {
			if ( $current_pool <= 0 || $current_tranche <= 0 ) {
				break;
			}

			// Scheduled interest on pool.
			$pool_interest = $current_pool * $pool_coupon / 12;

			// Defaults this month.
			$defaults_amount = $current_pool * $monthly_default;

			// Losses from defaults.
			$losses = $defaults_amount * $loss_severity;

			// Recoveries from defaults.
			$recoveries = $defaults_amount * ( 1 - $loss_severity );

			// Prepayments on performing balance.
			$performing  = $current_pool - $defaults_amount;
			$prepayments = $performing * $monthly_prepay;

			// Scheduled principal (simple amortization assumption).
			$scheduled_principal = $current_pool * ( $pool_coupon / 12 ) * 0.1;

			// Total principal paydown.
			$total_paydown = $scheduled_principal + $prepayments + $recoveries;

			// Reduce pool balance.
			$current_pool -= ( $total_paydown + $losses );
			$current_pool  = max( 0, $current_pool );

			// Allocate losses to subordinate tranches first.
			$loss_to_tranche = 0.0;
			if ( $sub_below > 0 ) {
				$sub_below -= $losses;
				if ( $sub_below < 0 ) {
					$loss_to_tranche = abs( $sub_below );
					$sub_below       = 0;
				}
			} else {
				$loss_to_tranche = $losses;
			}

			$loss_to_tranche = min( $loss_to_tranche, $current_tranche );

			// Tranche interest.
			$tranche_interest = $current_tranche * $coupon_rate / 12;

			// Tranche principal allocation (pro-rata simplified).
			$tranche_share = ( $pool_balance > 0 ) ? $tranche_balance / $pool_balance : 0;
			$tranche_prin  = $total_paydown * $tranche_share;
			$tranche_prin  = min( $tranche_prin, $current_tranche );

			// Apply losses.
			$current_tranche -= $loss_to_tranche;
			$current_tranche -= $tranche_prin;
			$current_tranche  = max( 0, $current_tranche );

			$net_cf = $tranche_interest + $tranche_prin - $loss_to_tranche;

			$total_interest  += $tranche_interest;
			$total_principal += $tranche_prin;
			$total_loss      += $loss_to_tranche;

			$row = array(
				'month'               => $month,
				'pool_balance'        => round( $current_pool, 2 ),
				'tranche_balance'     => round( $current_tranche, 2 ),
				'scheduled_principal' => round( $scheduled_principal * $tranche_share, 2 ),
				'prepayments'         => round( $prepayments * $tranche_share, 2 ),
				'defaults'            => round( $defaults_amount, 2 ),
				'losses'              => round( $loss_to_tranche, 2 ),
				'interest'            => round( $tranche_interest, 2 ),
				'net_cash_flow'       => round( $net_cf, 2 ),
			);

			// Include full detail for first 24 months and then every 12th month.
			if ( $month <= 24 || 0 === $month % 12 ) {
				$schedule[] = $row;
			}
		}

		// Weighted average life.
		$wal_numerator = 0.0;
		$wal_total_cf  = 0.0;
		foreach ( $schedule as $row ) {
			$prin           = $row['scheduled_principal'] + $row['prepayments'];
			$wal_numerator += $prin * ( $row['month'] / 12 );
			$wal_total_cf  += $prin;
		}
		$wal = ( $wal_total_cf > 0 ) ? $wal_numerator / $wal_total_cf : 0;

		$summary = array(
			'tranche_position'      => $position,
			'original_tranche'      => $calc::format_currency( $tranche_balance ),
			'remaining_tranche'     => $calc::format_currency( $current_tranche ),
			'total_interest_paid'   => $calc::format_currency( $total_interest ),
			'total_principal_paid'  => $calc::format_currency( $total_principal ),
			'total_losses_absorbed' => $calc::format_currency( $total_loss ),
			'loss_pct_of_tranche'   => $calc::format_percentage( ( $tranche_balance > 0 ) ? $total_loss / $tranche_balance : 0 ),
			'weighted_average_life' => round( $wal, 2 ) . ' years',
			'pool_factor'           => round( ( $pool_balance > 0 ) ? $current_pool / $pool_balance : 0, 4 ),
			'assumptions'           => array(
				'cdr'           => $calc::format_percentage( $cdr ),
				'cpr'           => $calc::format_percentage( $cpr ),
				'loss_severity' => $calc::format_percentage( $loss_severity ),
			),
		);

		return array(
			'success' => true,
			'message' => __( 'CMBS bond cash flow projection completed.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'summary'    => $summary,
				'schedule'   => $schedule,
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
