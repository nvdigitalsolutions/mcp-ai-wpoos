<?php
/**
 * CRE Debt Waterfall Modeler — Model GP/LP distribution waterfalls with catch-up and promote tiers
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
 * Models distribution waterfalls with return of capital, preferred return,
 * GP catch-up, and multiple promote tiers. Supports clawback provisions.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Debt_Waterfall_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_debt_waterfall_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Debt Waterfall Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Model a GP/LP distribution waterfall with return of capital, preferred return, GP catch-up, and promote tiers. Returns tier-by-tier breakdown with amounts distributed to GP and LP.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'distributable_amount' => array(
					'type'        => 'number',
					'description' => __( 'Total amount available for distribution.', 'mcp-ai-wpoos-pro' ),
				),
				'lp_commitment'        => array(
					'type'        => 'number',
					'description' => __( 'Total LP capital commitment.', 'mcp-ai-wpoos-pro' ),
				),
				'gp_commitment'        => array(
					'type'        => 'number',
					'description' => __( 'Total GP co-investment commitment.', 'mcp-ai-wpoos-pro' ),
				),
				'preferred_return_pct' => array(
					'type'        => 'number',
					'description' => __( 'Preferred return rate as decimal (e.g. 0.08 for 8%).', 'mcp-ai-wpoos-pro' ),
				),
				'promote_tiers'        => array(
					'type'        => 'array',
					'description' => __( 'Array of promote tier objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'hurdle_pct'   => array(
								'type'        => 'number',
								'description' => __( 'IRR hurdle as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'gp_share_pct' => array(
								'type'        => 'number',
								'description' => __( 'GP share of distributions in this tier as decimal.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'hurdle_pct', 'gp_share_pct' ),
					),
				),
				'gp_catchup_pct'       => array(
					'type'        => 'number',
					'description' => __( 'GP catch-up percentage (0-1). Portion of distributions above pref going to GP until GP reaches target split. 0 = no catch-up.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'clawback_provision'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a GP clawback provision applies.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'distributable_amount', 'lp_commitment', 'gp_commitment', 'preferred_return_pct' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$distributable  = (float) ( $arguments['distributable_amount'] ?? 0 );
		$lp_commitment  = (float) ( $arguments['lp_commitment'] ?? 0 );
		$gp_commitment  = (float) ( $arguments['gp_commitment'] ?? 0 );
		$pref_return    = (float) ( $arguments['preferred_return_pct'] ?? 0.08 );
		$promote_tiers  = $arguments['promote_tiers'] ?? array();
		$gp_catchup_pct = (float) ( $arguments['gp_catchup_pct'] ?? 0 );
		$clawback       = ! empty( $arguments['clawback_provision'] );

		if ( $distributable <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Distributable amount must be positive.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $lp_commitment <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'LP commitment must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc             = WP_MCP_AI_CRE_Debt_Calculator::class;
		$total_commitment = $lp_commitment + $gp_commitment;
		$lp_share         = ( $total_commitment > 0 ) ? $lp_commitment / $total_commitment : 1.0;
		$gp_share         = 1.0 - $lp_share;
		$remaining        = $distributable;
		$tiers            = array();
		$total_to_lp      = 0.0;
		$total_to_gp      = 0.0;

		// Tier 1: Return of Capital.
		$roc          = min( $remaining, $total_commitment );
		$roc_lp       = round( $roc * $lp_share, 2 );
		$roc_gp       = round( $roc * $gp_share, 2 );
		$remaining   -= $roc;
		$tiers[]      = array(
			'tier'      => __( 'Return of Capital', 'mcp-ai-wpoos-pro' ),
			'amount'    => round( $roc, 2 ),
			'lp_amount' => $roc_lp,
			'gp_amount' => $roc_gp,
		);
		$total_to_lp += $roc_lp;
		$total_to_gp += $roc_gp;

		// Tier 2: Preferred Return.
		$pref_amount  = $total_commitment * $pref_return;
		$pref_paid    = min( $remaining, $pref_amount );
		$pref_lp      = round( $pref_paid * $lp_share, 2 );
		$pref_gp      = round( $pref_paid * $gp_share, 2 );
		$remaining   -= $pref_paid;
		$tiers[]      = array(
			'tier'       => __( 'Preferred Return', 'mcp-ai-wpoos-pro' ),
			'rate'       => $calc::format_percentage( $pref_return ),
			'amount'     => round( $pref_paid, 2 ),
			'lp_amount'  => $pref_lp,
			'gp_amount'  => $pref_gp,
			'fully_paid' => ( $pref_paid >= $pref_amount ),
		);
		$total_to_lp += $pref_lp;
		$total_to_gp += $pref_gp;

		// Tier 3: GP Catch-up (if applicable).
		if ( $gp_catchup_pct > 0 && $remaining > 0 ) {
			// GP catch-up continues until GP has received gp_catchup_pct of total distributions.
			$target_gp_total = ( $total_to_lp + $total_to_gp + $remaining ) * $gp_catchup_pct;
			$catchup_needed  = max( 0, $target_gp_total - $total_to_gp );
			$catchup_paid    = min( $remaining, $catchup_needed );
			$remaining      -= $catchup_paid;
			$tiers[]         = array(
				'tier'      => __( 'GP Catch-Up', 'mcp-ai-wpoos-pro' ),
				'amount'    => round( $catchup_paid, 2 ),
				'lp_amount' => 0.0,
				'gp_amount' => round( $catchup_paid, 2 ),
			);
			$total_to_gp    += $catchup_paid;
		}

		// Tier 4+: Promote tiers.
		$prev_hurdle = $pref_return;
		if ( is_array( $promote_tiers ) ) {
			foreach ( $promote_tiers as $tier ) {
				if ( $remaining <= 0 ) {
					break;
				}
				$hurdle = (float) ( $tier['hurdle_pct'] ?? 0 );
				$gp_pct = (float) ( $tier['gp_share_pct'] ?? 0.20 );
				$lp_pct = 1.0 - $gp_pct;

				$tier_amount  = $total_commitment * max( 0, $hurdle - $prev_hurdle );
				$tier_paid    = min( $remaining, $tier_amount );
				$remaining   -= $tier_paid;
				$t_lp         = round( $tier_paid * $lp_pct, 2 );
				$t_gp         = round( $tier_paid * $gp_pct, 2 );
				$tiers[]      = array(
					'tier'      => sprintf(
						/* translators: 1: previous hurdle percentage, 2: current hurdle percentage */
						__( 'Promote (%1$s–%2$s IRR)', 'mcp-ai-wpoos-pro' ),
						$calc::format_percentage( $prev_hurdle ),
						$calc::format_percentage( $hurdle )
					),
					'gp_split'  => $calc::format_percentage( $gp_pct ),
					'amount'    => round( $tier_paid, 2 ),
					'lp_amount' => $t_lp,
					'gp_amount' => $t_gp,
				);
				$total_to_lp += $t_lp;
				$total_to_gp += $t_gp;
				$prev_hurdle  = $hurdle;
			}
		}

		// Residual.
		if ( $remaining > 0 ) {
			$final_gp_pct = ! empty( $promote_tiers ) ? (float) ( end( $promote_tiers )['gp_share_pct'] ?? 0.20 ) : $gp_share;
			$final_lp_pct = 1.0 - $final_gp_pct;
			$r_lp         = round( $remaining * $final_lp_pct, 2 );
			$r_gp         = round( $remaining * $final_gp_pct, 2 );
			$tiers[]      = array(
				'tier'      => __( 'Residual (above all hurdles)', 'mcp-ai-wpoos-pro' ),
				'amount'    => round( $remaining, 2 ),
				'lp_amount' => $r_lp,
				'gp_amount' => $r_gp,
			);
			$total_to_lp += $r_lp;
			$total_to_gp += $r_gp;
		}

		$lp_multiple = ( $lp_commitment > 0 ) ? round( $total_to_lp / $lp_commitment, 2 ) : 0;
		$gp_multiple = ( $gp_commitment > 0 ) ? round( $total_to_gp / $gp_commitment, 2 ) : 0;

		return array(
			'success' => true,
			'message' => __( 'Waterfall model complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'total_distributed'  => $calc::format_currency( $distributable ),
				'total_to_lp'        => $calc::format_currency( round( $total_to_lp, 2 ) ),
				'total_to_gp'        => $calc::format_currency( round( $total_to_gp, 2 ) ),
				'lp_multiple'        => $lp_multiple . 'x',
				'gp_multiple'        => $gp_multiple . 'x',
				'gp_catchup_applied' => ( $gp_catchup_pct > 0 ),
				'clawback_provision' => $clawback,
				'tiers'              => $tiers,
			),
		);
	}
}
