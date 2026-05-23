<?php
/**
 * CMBS Deal Structurer — Size and structure CMBS tranches with credit enhancement
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
 * Structures a CMBS deal by sizing tranches from AAA down to equity based on
 * subordination levels. Calculates credit enhancement, weighted average spread,
 * excess spread, and overall deal economics.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Deal_Structurer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default subordination levels by rating (percentage of pool that sits below).
	 *
	 * @var array
	 */
	private static $default_subordination = array(
		'AAA' => 0.30,
		'AA'  => 0.22,
		'A'   => 0.16,
		'BBB' => 0.11,
		'BB'  => 0.07,
		'B'   => 0.04,
	);

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
		return 'cmbs_deal_structurer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Deal Structurer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Structure a CMBS securitization by sizing tranches from AAA to equity. Calculates credit enhancement per tranche, weighted average spread, excess spread, and deal economics.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pool_balance'             => array(
					'type'        => 'number',
					'description' => __( 'Total pool balance (collateral UPB).', 'mcp-ai-wpoos-pro' ),
				),
				'num_tranches'             => array(
					'type'        => 'integer',
					'description' => __( 'Number of rated tranches (2-8). Equity tranche is always added.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 2,
					'maximum'     => 8,
				),
				'target_subordination_pct' => array(
					'type'        => 'number',
					'description' => __( 'Target total subordination below AAA as decimal (e.g. 0.30 for 30%).', 'mcp-ai-wpoos-pro' ),
				),
				'aaa_spread_bps'           => array(
					'type'        => 'number',
					'description' => __( 'AAA tranche spread in basis points.', 'mcp-ai-wpoos-pro' ),
				),
				'aa_spread_bps'            => array(
					'type'        => 'number',
					'description' => __( 'AA tranche spread in basis points.', 'mcp-ai-wpoos-pro' ),
				),
				'a_spread_bps'             => array(
					'type'        => 'number',
					'description' => __( 'A tranche spread in basis points.', 'mcp-ai-wpoos-pro' ),
				),
				'bbb_spread_bps'           => array(
					'type'        => 'number',
					'description' => __( 'BBB tranche spread in basis points.', 'mcp-ai-wpoos-pro' ),
				),
				'bb_spread_bps'            => array(
					'type'        => 'number',
					'description' => __( 'BB tranche spread in basis points.', 'mcp-ai-wpoos-pro' ),
				),
				'b_spread_bps'             => array(
					'type'        => 'number',
					'description' => __( 'B tranche spread in basis points.', 'mcp-ai-wpoos-pro' ),
				),
				'equity_yield_pct'         => array(
					'type'        => 'number',
					'description' => __( 'Target equity yield as decimal (e.g. 0.15 for 15%).', 'mcp-ai-wpoos-pro' ),
				),
				'weighted_avg_coupon'      => array(
					'type'        => 'number',
					'description' => __( 'Weighted average coupon of underlying pool as decimal (e.g. 0.065).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'pool_balance', 'num_tranches', 'target_subordination_pct', 'aaa_spread_bps', 'weighted_avg_coupon' ),
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

		$pool_balance = (float) ( $arguments['pool_balance'] ?? 0 );
		$num_tranches = (int) ( $arguments['num_tranches'] ?? 4 );
		$target_sub   = (float) ( $arguments['target_subordination_pct'] ?? 0.30 );
		$aaa_spread   = (float) ( $arguments['aaa_spread_bps'] ?? 80 );
		$aa_spread    = (float) ( $arguments['aa_spread_bps'] ?? 120 );
		$a_spread     = (float) ( $arguments['a_spread_bps'] ?? 175 );
		$bbb_spread   = (float) ( $arguments['bbb_spread_bps'] ?? 300 );
		$bb_spread    = (float) ( $arguments['bb_spread_bps'] ?? 475 );
		$b_spread     = (float) ( $arguments['b_spread_bps'] ?? 650 );
		$equity_yield = (float) ( $arguments['equity_yield_pct'] ?? 0.15 );
		$wac          = (float) ( $arguments['weighted_avg_coupon'] ?? 0 );

		if ( $pool_balance <= 0 || $wac <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Pool balance and weighted average coupon must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $num_tranches < 2 || $num_tranches > 8 ) {
			return new WP_Error( 'invalid_input', __( 'Number of tranches must be between 2 and 8.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $target_sub <= 0 || $target_sub >= 1 ) {
			return new WP_Error( 'invalid_input', __( 'Target subordination must be between 0 and 1 (exclusive).', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Build rated tranche definitions in seniority order.
		$tranche_defs = array(
			array(
				'rating'     => 'AAA',
				'spread_bps' => $aaa_spread,
			),
			array(
				'rating'     => 'AA',
				'spread_bps' => $aa_spread,
			),
			array(
				'rating'     => 'A',
				'spread_bps' => $a_spread,
			),
			array(
				'rating'     => 'BBB',
				'spread_bps' => $bbb_spread,
			),
			array(
				'rating'     => 'BB',
				'spread_bps' => $bb_spread,
			),
			array(
				'rating'     => 'B',
				'spread_bps' => $b_spread,
			),
		);

		// Limit to requested number of rated tranches.
		$tranche_defs = array_slice( $tranche_defs, 0, min( $num_tranches, 6 ) );

		// Derive subordination levels linearly between target_sub (AAA) and 0 (equity).
		$tranche_count = count( $tranche_defs );
		$tranches      = array();
		$prev_attach   = 0.0;
		$total_spread  = 0.0;
		$total_rated   = 0.0;

		for ( $i = $tranche_count - 1; $i >= 0; $i-- ) {
			$def = $tranche_defs[ $i ];

			if ( 0 === $i ) {
				// Most senior tranche (AAA): everything above target subordination.
				$detach_pct = 1.0;
				$attach_pct = $target_sub;
			} else {
				// Interpolate subordination levels linearly.
				$detach_pct = isset( $tranches[ $i + 1 ] ) ? $tranches[ $i + 1 ]['attach_pct'] : $target_sub;
				$fraction   = ( $tranche_count - $i ) / $tranche_count;
				$attach_pct = $target_sub * ( 1 - $fraction );
			}

			$tranche_pct     = $detach_pct - $attach_pct;
			$tranche_balance = $pool_balance * $tranche_pct;
			$spread_decimal  = $def['spread_bps'] / 10000;

			$tranches[ $i ] = array(
				'rating'             => $def['rating'],
				'attach_pct'         => round( $attach_pct, 4 ),
				'detach_pct'         => round( $detach_pct, 4 ),
				'credit_enhancement' => $calc::format_percentage( $attach_pct ),
				'tranche_pct'        => $calc::format_percentage( $tranche_pct ),
				'balance'            => $calc::format_currency( $tranche_balance ),
				'balance_raw'        => round( $tranche_balance, 2 ),
				'spread_bps'         => $def['spread_bps'],
				'coupon'             => $calc::format_percentage( $spread_decimal ),
			);

			$total_spread += $spread_decimal * $tranche_pct;
			$total_rated  += $tranche_balance;
		}

		// Sort by seniority (AAA first).
		ksort( $tranches );
		$tranches = array_values( $tranches );

		// Equity tranche.
		$equity_balance = $pool_balance - $total_rated;
		$equity_pct     = $equity_balance / $pool_balance;

		$tranches[] = array(
			'rating'             => 'Equity',
			'attach_pct'         => 0.0,
			'detach_pct'         => round( $tranches[ count( $tranches ) - 1 ]['attach_pct'], 4 ),
			'credit_enhancement' => 'N/A (First Loss)',
			'tranche_pct'        => $calc::format_percentage( $equity_pct ),
			'balance'            => $calc::format_currency( $equity_balance ),
			'balance_raw'        => round( $equity_balance, 2 ),
			'spread_bps'         => 'N/A',
			'target_yield'       => $calc::format_percentage( $equity_yield ),
		);

		// Deal economics.
		$wa_spread          = $total_spread;
		$pool_annual_income = $pool_balance * $wac;
		$rated_annual_cost  = 0.0;
		foreach ( $tranches as $t ) {
			if ( 'Equity' !== $t['rating'] ) {
				$rated_annual_cost += $t['balance_raw'] * ( $t['spread_bps'] / 10000 );
			}
		}

		$excess_spread     = $pool_annual_income - $rated_annual_cost - ( $equity_balance * $equity_yield );
		$excess_spread_pct = ( $pool_balance > 0 ) ? $excess_spread / $pool_balance : 0;

		$economics = array(
			'pool_balance'         => $calc::format_currency( $pool_balance ),
			'total_rated_balance'  => $calc::format_currency( $total_rated ),
			'equity_balance'       => $calc::format_currency( $equity_balance ),
			'weighted_avg_coupon'  => $calc::format_percentage( $wac ),
			'weighted_avg_spread'  => round( $wa_spread * 10000, 1 ) . ' bps',
			'pool_annual_income'   => $calc::format_currency( $pool_annual_income ),
			'rated_annual_cost'    => $calc::format_currency( $rated_annual_cost ),
			'annual_excess_spread' => $calc::format_currency( $excess_spread ),
			'excess_spread_pct'    => $calc::format_percentage( $excess_spread_pct ),
			'advance_rate'         => $calc::format_percentage( $total_rated / $pool_balance ),
		);

		return array(
			'success' => true,
			'message' => __( 'CMBS deal structure generated.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'tranches'   => $tranches,
				'economics'  => $economics,
				'summary'    => sprintf(
					/* translators: 1: number of rated tranches, 2: AAA credit enhancement, 3: excess spread */
					__( '%1$d rated tranches structured. AAA credit enhancement: %2$s. Annual excess spread: %3$s.', 'mcp-ai-wpoos-pro' ),
					count( $tranches ) - 1,
					$tranches[0]['credit_enhancement'],
					$economics['annual_excess_spread']
				),
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
