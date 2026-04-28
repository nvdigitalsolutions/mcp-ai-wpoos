<?php
/**
 * Reference Reward Functions
 *
 * Ships three composable reward functions that autonomous loops can combine.
 * All three are registered under `wp_mcp_ai_register_reward_functions` by
 * default. Sites that want to opt out can deregister them via
 * `WP_MCP_AI_Reward_Function_Registry::unregister()` inside the same hook.
 *
 * Each function has a documented anti-gaming safeguard, as required by the
 * reward function registry.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static holder for the reference reward functions.
 */
class WP_MCP_AI_Reference_Rewards {

	/**
	 * Register all reference reward functions.
	 *
	 * @param WP_MCP_AI_Reward_Function_Registry $registry Registry.
	 * @return void
	 */
	public static function register( WP_MCP_AI_Reward_Function_Registry $registry ) {
		$registry->register(
			array(
				'slug'           => 'verified_success',
				'label'          => __( 'Verified Success', 'mcp-ai-wpoos' ),
				'description'    => __( 'Returns 1.0 only when the verifier passed with confidence above a threshold. Paired with cost_adjusted_success.', 'mcp-ai-wpoos' ),
				'callback'       => array( __CLASS__, 'verified_success' ),
				'inputs'         => array( 'verifier_passed', 'verifier_confidence' ),
				'output_min'     => 0.0,
				'output_max'     => 1.0,
				'anti_gaming'    => __( 'Requires both passed=true AND confidence >= 0.5; paired with cost_adjusted_success so cheap-but-wrong answers do not earn reward.', 'mcp-ai-wpoos' ),
				'counter_metric' => 'cost.per_verified_success',
			)
		);

		$registry->register(
			array(
				'slug'           => 'cost_adjusted_success',
				'label'          => __( 'Cost-Adjusted Success', 'mcp-ai-wpoos' ),
				'description'    => __( 'verified_success divided by 1 + cost_usd / budget_usd. Penalizes expensive successes.', 'mcp-ai-wpoos' ),
				'callback'       => array( __CLASS__, 'cost_adjusted_success' ),
				'inputs'         => array( 'verifier_passed', 'verifier_confidence', 'cost_usd', 'budget_usd' ),
				'output_min'     => 0.0,
				'output_max'     => 1.0,
				'anti_gaming'    => __( 'Bounded by [0, 1] after cost penalty; cannot grow unbounded with more tool calls.', 'mcp-ai-wpoos' ),
				'counter_metric' => 'agent.abstention.rate',
			)
		);

		$registry->register(
			array(
				'slug'           => 'calibration_brier',
				'label'          => __( 'Calibration (Brier Score)', 'mcp-ai-wpoos' ),
				'description'    => __( 'Measures how well a stated confidence matches the verifier outcome. Lower is better; inverted and clamped so 1.0 is perfect calibration.', 'mcp-ai-wpoos' ),
				'callback'       => array( __CLASS__, 'calibration_brier' ),
				'inputs'         => array( 'stated_confidence', 'verifier_passed' ),
				'output_min'     => 0.0,
				'output_max'     => 1.0,
				'anti_gaming'    => __( 'Bounded Brier score; agents cannot inflate by claiming uniform high confidence because they lose points on misses.', 'mcp-ai-wpoos' ),
				'counter_metric' => 'agent.unjustified_confidence',
			)
		);
	}

	/**
	 * Verified_success reward.
	 *
	 * @param array $inputs  Inputs.
	 * @param array $context Context.
	 * @return float
	 */
	public static function verified_success( array $inputs, array $context = array() ) {
		unset( $context );
		$passed     = ! empty( $inputs['verifier_passed'] );
		$confidence = isset( $inputs['verifier_confidence'] ) ? (float) $inputs['verifier_confidence'] : 0.0;
		return ( $passed && $confidence >= 0.5 ) ? 1.0 : 0.0;
	}

	/**
	 * Cost_adjusted_success reward.
	 *
	 * @param array $inputs  Inputs.
	 * @param array $context Context.
	 * @return float
	 */
	public static function cost_adjusted_success( array $inputs, array $context = array() ) {
		unset( $context );
		$base   = self::verified_success( $inputs, array() );
		$cost   = isset( $inputs['cost_usd'] ) ? max( 0.0, (float) $inputs['cost_usd'] ) : 0.0;
		$budget = isset( $inputs['budget_usd'] ) ? max( 0.000001, (float) $inputs['budget_usd'] ) : 1.0;
		$ratio  = $cost / $budget;
		// Smooth penalty: divide by (1 + ratio). At ratio=1 the reward is halved.
		return $base / ( 1.0 + $ratio );
	}

	/**
	 * Calibration_brier reward.
	 *
	 * Computes 1 - (confidence - outcome)^2, clamped to [0, 1]. Because the
	 * outcome is in {0,1} and the confidence is in [0,1], the squared error
	 * is in [0,1] so the inverted value is always in [0,1] — no clamping
	 * surprises.
	 *
	 * @param array $inputs  Inputs.
	 * @param array $context Context.
	 * @return float
	 */
	public static function calibration_brier( array $inputs, array $context = array() ) {
		unset( $context );
		$confidence = isset( $inputs['stated_confidence'] ) ? (float) $inputs['stated_confidence'] : 0.0;
		if ( $confidence < 0.0 ) {
			$confidence = 0.0;
		} elseif ( $confidence > 1.0 ) {
			$confidence = 1.0;
		}
		$outcome = ! empty( $inputs['verifier_passed'] ) ? 1.0 : 0.0;
		$err     = $confidence - $outcome;
		return 1.0 - ( $err * $err );
	}
}
