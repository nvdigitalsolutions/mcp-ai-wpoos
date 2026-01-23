<?php
/**
 * Rebalancing Analyzer Tool
 *
 * Analyze portfolio drift from target allocation and provide rebalancing
 * recommendations to maintain desired asset mix and risk profile.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for analyzing portfolio rebalancing needs.
 *
 * Supports:
 * - Drift detection from target allocation
 * - Rebalancing trade recommendations
 * - Tax-efficient rebalancing strategies
 * - Threshold-based alerts
 * - Historical drift tracking
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Rebalancing_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Rebalancing analyzer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'rebalancing_analyzer';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Rebalancing Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyze portfolio drift and generate rebalancing recommendations. Identifies assets that have drifted from target allocation and suggests trades to restore balance. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
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
				'current_allocation'  => array(
					'type'        => 'object',
					'description' => __( 'Current portfolio allocation by asset class', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'stocks'      => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'bonds'       => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'real_estate' => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'commodities' => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'cash'        => array(
							'type'    => 'number',
							'minimum' => 0,
						),
					),
				),
				'target_allocation'   => array(
					'type'        => 'object',
					'description' => __( 'Target portfolio allocation percentages', 'mcp-ai-wpoos-pro' ),
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
				'rebalance_threshold' => array(
					'type'        => 'number',
					'description' => __( 'Percentage drift threshold to trigger rebalancing', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'portfolio_value'     => array(
					'type'        => 'number',
					'description' => __( 'Total portfolio value', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'account_type'        => array(
					'type'        => 'string',
					'description' => __( 'Account type for tax considerations', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'taxable', 'tax_deferred', 'tax_free' ),
					'default'     => 'taxable',
				),
			),
			'required'   => array( 'current_allocation', 'target_allocation', 'portfolio_value' ),
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
				__( 'You do not have permission to use the rebalancing analyzer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$current_allocation  = isset( $arguments['current_allocation'] ) && is_array( $arguments['current_allocation'] ) ? $arguments['current_allocation'] : array();
		$target_allocation   = isset( $arguments['target_allocation'] ) && is_array( $arguments['target_allocation'] ) ? $arguments['target_allocation'] : array();
		$rebalance_threshold = isset( $arguments['rebalance_threshold'] ) ? floatval( $arguments['rebalance_threshold'] ) : 5;
		$portfolio_value     = isset( $arguments['portfolio_value'] ) ? floatval( $arguments['portfolio_value'] ) : 0;
		$account_type        = isset( $arguments['account_type'] ) ? sanitize_text_field( $arguments['account_type'] ) : 'taxable';

		if ( empty( $current_allocation ) || empty( $target_allocation ) ) {
			return new WP_Error( 'missing_allocation', __( 'Both current and target allocations are required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $portfolio_value <= 0 ) {
			return new WP_Error( 'invalid_value', __( 'Portfolio value must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$total_target = array_sum( $target_allocation );
		if ( abs( $total_target - 100 ) > 0.1 ) {
			return new WP_Error( 'invalid_target', __( 'Target allocation must sum to 100%.', 'mcp-ai-wpoos-pro' ) );
		}

		$total_current_value = array_sum( $current_allocation );
		$current_percentages = array();
		foreach ( $current_allocation as $asset => $value ) {
			$current_percentages[ $asset ] = $total_current_value > 0 ? ( $value / $total_current_value ) * 100 : 0;
		}

		$drift_analysis    = array();
		$needs_rebalancing = false;
		$trades            = array();

		foreach ( $target_allocation as $asset => $target_pct ) {
			$current_pct = isset( $current_percentages[ $asset ] ) ? $current_percentages[ $asset ] : 0;
			$drift       = $current_pct - $target_pct;
			$drift_abs   = abs( $drift );

			$target_value  = ( $target_pct / 100 ) * $portfolio_value;
			$current_value = isset( $current_allocation[ $asset ] ) ? $current_allocation[ $asset ] : 0;
			$trade_amount  = $target_value - $current_value;

			$drift_analysis[ $asset ] = array(
				'current_pct'   => round( $current_pct, 2 ),
				'target_pct'    => round( $target_pct, 2 ),
				'drift'         => round( $drift, 2 ),
				'drift_abs'     => round( $drift_abs, 2 ),
				'current_value' => round( $current_value, 2 ),
				'target_value'  => round( $target_value, 2 ),
			);

			if ( $drift_abs > $rebalance_threshold ) {
				$needs_rebalancing = true;
				$trades[ $asset ]  = array(
					'action' => $trade_amount > 0 ? 'buy' : 'sell',
					'amount' => round( abs( $trade_amount ), 2 ),
				);
			}
		}

		$recommendations = array();
		if ( $needs_rebalancing ) {
			$recommendations[] = __( 'Portfolio has drifted beyond threshold. Consider rebalancing.', 'mcp-ai-wpoos-pro' );

			if ( 'taxable' === $account_type ) {
				$recommendations[] = __( 'Taxable account: Consider tax-loss harvesting opportunities and long-term capital gains rates.', 'mcp-ai-wpoos-pro' );
			} else {
				$recommendations[] = __( 'Tax-advantaged account: Rebalancing can be done without tax consequences.', 'mcp-ai-wpoos-pro' );
			}
		} else {
			$recommendations[] = __( 'Portfolio is within target allocation. No rebalancing needed at this time.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'             => true,
			'needs_rebalancing'   => $needs_rebalancing,
			'portfolio_value'     => $portfolio_value,
			'drift_analysis'      => $drift_analysis,
			'trades'              => $trades,
			'rebalance_threshold' => $rebalance_threshold,
			'account_type'        => $account_type,
			'recommendations'     => $recommendations,
			'disclaimer'          => __( 'EDUCATIONAL ONLY. This analysis is for educational purposes only and does not constitute investment advice. Consider transaction costs, tax implications, and your individual circumstances. Consult a licensed financial advisor before making trades.', 'mcp-ai-wpoos-pro' ),
			'message'             => $needs_rebalancing
				? sprintf(
					/* translators: %d: Number of assets needing rebalancing */
					__( 'Rebalancing recommended: %d assets have drifted beyond threshold.', 'mcp-ai-wpoos-pro' ),
					count( $trades )
				)
				: __( 'Portfolio is well-balanced. No action needed.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
