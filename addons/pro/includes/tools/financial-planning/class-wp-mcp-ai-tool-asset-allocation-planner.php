<?php
/**
 * Asset Allocation Planner Tool
 *
 * Plan optimal asset allocation based on age, risk tolerance, investment
 * goals, and time horizon following modern portfolio theory principles.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for planning asset allocation strategies.
 *
 * Supports:
 * - Risk tolerance assessment
 * - Age-based allocation strategies
 * - Target date adjustments
 * - Diversification recommendations
 * - Rebalancing thresholds
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Asset_Allocation_Planner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Asset allocation planner tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'asset_allocation_planner';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Asset Allocation Planner', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Plan optimal asset allocation based on risk tolerance, age, and investment timeline. Recommends diversified portfolio mix across stocks, bonds, and other assets. Provides rebalancing guidance and age-based adjustments.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'age'                => array(
					'type'        => 'integer',
					'description' => __( 'Current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 18,
					'maximum'     => 100,
				),
				'risk_tolerance'     => array(
					'type'        => 'string',
					'description' => __( 'Risk tolerance level', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'conservative', 'moderate', 'aggressive' ),
				),
				'investment_goal'    => array(
					'type'        => 'string',
					'description' => __( 'Primary investment goal', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'retirement', 'wealth_building', 'income', 'preservation' ),
				),
				'time_horizon'       => array(
					'type'        => 'integer',
					'description' => __( 'Investment time horizon in years', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'current_allocation' => array(
					'type'        => 'object',
					'description' => __( 'Current asset allocation percentages', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'stocks'      => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 100,
						),
						'bonds'       => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 100,
						),
						'real_estate' => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 100,
						),
						'commodities' => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 100,
						),
						'cash'        => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 100,
						),
					),
				),
			),
			'required'   => array( 'age', 'risk_tolerance', 'time_horizon' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the asset allocation planner.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$age                = isset( $arguments['age'] ) ? absint( $arguments['age'] ) : 0;
		$risk_tolerance     = isset( $arguments['risk_tolerance'] ) ? sanitize_text_field( $arguments['risk_tolerance'] ) : 'moderate';
		$investment_goal    = isset( $arguments['investment_goal'] ) ? sanitize_text_field( $arguments['investment_goal'] ) : 'retirement';
		$time_horizon       = isset( $arguments['time_horizon'] ) ? absint( $arguments['time_horizon'] ) : 0;
		$current_allocation = isset( $arguments['current_allocation'] ) && is_array( $arguments['current_allocation'] ) ? $arguments['current_allocation'] : array();

		if ( $age < 18 || $age > 100 ) {
			return new WP_Error( 'invalid_age', __( 'Age must be between 18 and 100.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $time_horizon < 1 ) {
			return new WP_Error( 'invalid_horizon', __( 'Time horizon must be at least 1 year.', 'mcp-ai-wpoos-pro' ) );
		}

		$base_stock_allocation = 100 - $age;

		$risk_adjustments = array(
			'conservative' => -20,
			'moderate'     => 0,
			'aggressive'   => 20,
		);
		$risk_adjustment  = isset( $risk_adjustments[ $risk_tolerance ] ) ? $risk_adjustments[ $risk_tolerance ] : 0;

		$adjusted_stock_allocation = max( 20, min( 90, $base_stock_allocation + $risk_adjustment ) );
		$bond_allocation           = 100 - $adjusted_stock_allocation;

		$recommended_allocation = array(
			'stocks'      => round( $adjusted_stock_allocation * 0.85, 1 ),
			'bonds'       => round( $bond_allocation, 1 ),
			'real_estate' => round( $adjusted_stock_allocation * 0.10, 1 ),
			'commodities' => round( $adjusted_stock_allocation * 0.03, 1 ),
			'cash'        => round( $adjusted_stock_allocation * 0.02, 1 ),
		);

		$total = array_sum( $recommended_allocation );
		if ( 100.0 !== $total ) {
			$diff                             = 100.0 - $total;
			$recommended_allocation['stocks'] = round( $recommended_allocation['stocks'] + $diff, 1 );
		}

		$rebalancing_needed = false;
		$adjustments        = array();
		if ( ! empty( $current_allocation ) ) {
			foreach ( $recommended_allocation as $asset => $target_pct ) {
				$current_pct = isset( $current_allocation[ $asset ] ) ? floatval( $current_allocation[ $asset ] ) : 0;
				$diff        = $target_pct - $current_pct;
				if ( abs( $diff ) > 5 ) {
					$rebalancing_needed    = true;
					$adjustments[ $asset ] = round( $diff, 1 );
				}
			}
		}

		$strategy_notes = array();
		if ( $time_horizon <= 5 ) {
			$strategy_notes[] = __( 'Short time horizon: Focus on capital preservation with higher bond allocation.', 'mcp-ai-wpoos-pro' );
		} elseif ( $time_horizon >= 20 ) {
			$strategy_notes[] = __( 'Long time horizon: Can tolerate more volatility for higher potential returns.', 'mcp-ai-wpoos-pro' );
		}

		if ( 'conservative' === $risk_tolerance ) {
			$strategy_notes[] = __( 'Conservative profile: Prioritize stability and income over growth.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'aggressive' === $risk_tolerance ) {
			$strategy_notes[] = __( 'Aggressive profile: Higher allocation to equities for growth potential.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'                => true,
			'age'                    => $age,
			'risk_tolerance'         => $risk_tolerance,
			'time_horizon'           => $time_horizon,
			'recommended_allocation' => $recommended_allocation,
			'current_allocation'     => $current_allocation,
			'rebalancing_needed'     => $rebalancing_needed,
			'adjustments'            => $adjustments,
			'strategy_notes'         => $strategy_notes,
			'rebalance_threshold'    => 5,
			'disclaimer'             => __( 'EDUCATIONAL ONLY. This allocation recommendation is for educational purposes only and does not constitute investment advice. Individual circumstances vary. Consult a licensed financial advisor before making investment decisions.', 'mcp-ai-wpoos-pro' ),
			'message'                => sprintf(
				/* translators: 1: Stock percentage, 2: Bond percentage */
				__( 'Recommended allocation: %1$s%% stocks, %2$s%% bonds based on your profile.', 'mcp-ai-wpoos-pro' ),
				$recommended_allocation['stocks'],
				$recommended_allocation['bonds']
			),
		);
	}
}
