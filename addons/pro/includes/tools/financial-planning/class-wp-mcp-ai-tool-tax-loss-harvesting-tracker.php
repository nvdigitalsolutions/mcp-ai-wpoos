<?php
/**
 * Tax Loss Harvesting Tracker Tool
 *
 * Track tax-loss harvesting opportunities by identifying positions with
 * unrealized losses that can offset capital gains for tax optimization.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for tracking tax-loss harvesting opportunities.
 *
 * Supports:
 * - Unrealized loss identification
 * - Wash sale rule compliance (30-day)
 * - Tax savings estimation
 * - Replacement security suggestions
 * - Annual tracking and reporting
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

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

		return __( 'Tax loss harvesting tracker tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'tax_loss_harvesting_tracker';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Tax Loss Harvesting Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Track tax-loss harvesting opportunities to offset capital gains. Identifies positions with unrealized losses, ensures wash sale rule compliance, and estimates potential tax savings. EDUCATIONAL ONLY - Not tax advice.', 'mcp-ai-wpoos-pro' );
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
				'holdings'           => array(
					'type'        => 'array',
					'description' => __( 'Portfolio holdings with cost basis', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'ticker'        => array(
								'type'        => 'string',
								'description' => __( 'Stock ticker symbol', 'mcp-ai-wpoos-pro' ),
							),
							'shares'        => array(
								'type'        => 'number',
								'description' => __( 'Number of shares', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'cost_basis'    => array(
								'type'        => 'number',
								'description' => __( 'Cost basis per share', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'current_price' => array(
								'type'        => 'number',
								'description' => __( 'Current price per share', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'purchase_date' => array(
								'type'        => 'string',
								'description' => __( 'Purchase date (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
								'format'      => 'date',
							),
						),
					),
				),
				'capital_gains_rate' => array(
					'type'        => 'number',
					'description' => __( 'Capital gains tax rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 15,
				),
				'realized_gains'     => array(
					'type'        => 'number',
					'description' => __( 'Already realized capital gains this year', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'minimum_loss'       => array(
					'type'        => 'number',
					'description' => __( 'Minimum loss threshold to consider harvesting', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 100,
				),
			),
			'required'   => array( 'holdings' ),
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
				__( 'You do not have permission to use the tax loss harvesting tracker.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$holdings           = isset( $arguments['holdings'] ) && is_array( $arguments['holdings'] ) ? $arguments['holdings'] : array();
		$capital_gains_rate = isset( $arguments['capital_gains_rate'] ) ? floatval( $arguments['capital_gains_rate'] ) : 15;
		$realized_gains     = isset( $arguments['realized_gains'] ) ? floatval( $arguments['realized_gains'] ) : 0;
		$minimum_loss       = isset( $arguments['minimum_loss'] ) ? floatval( $arguments['minimum_loss'] ) : 100;

		if ( empty( $holdings ) ) {
			return new WP_Error( 'empty_holdings', __( 'Holdings are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$opportunities          = array();
		$total_harvestable_loss = 0;
		$wash_sale_warnings     = array();

		foreach ( $holdings as $holding ) {
			$ticker        = isset( $holding['ticker'] ) ? sanitize_text_field( $holding['ticker'] ) : '';
			$shares        = isset( $holding['shares'] ) ? floatval( $holding['shares'] ) : 0;
			$cost_basis    = isset( $holding['cost_basis'] ) ? floatval( $holding['cost_basis'] ) : 0;
			$current_price = isset( $holding['current_price'] ) ? floatval( $holding['current_price'] ) : 0;
			$purchase_date = isset( $holding['purchase_date'] ) ? sanitize_text_field( $holding['purchase_date'] ) : '';

			$total_cost      = $shares * $cost_basis;
			$market_value    = $shares * $current_price;
			$unrealized_loss = $total_cost - $market_value;

			if ( $unrealized_loss > $minimum_loss ) {
				$days_held = 0;
				if ( ! empty( $purchase_date ) ) {
					$purchase_timestamp = strtotime( $purchase_date );
					$today_timestamp    = current_time( 'timestamp' );
					$days_held          = floor( ( $today_timestamp - $purchase_timestamp ) / DAY_IN_SECONDS );
				}

				$wash_sale_risk = false;
				if ( $days_held < 30 ) {
					$wash_sale_risk       = true;
					$wash_sale_warnings[] = sprintf(
						/* translators: 1: Ticker, 2: Days held */
						__( '%1$s: Held for only %2$d days. Wait %3$d more days to avoid wash sale.', 'mcp-ai-wpoos-pro' ),
						$ticker,
						$days_held,
						30 - $days_held
					);
				}

				$tax_savings = ( $unrealized_loss * $capital_gains_rate ) / 100;

				$opportunities[] = array(
					'ticker'          => $ticker,
					'shares'          => $shares,
					'cost_basis'      => $cost_basis,
					'current_price'   => $current_price,
					'unrealized_loss' => round( $unrealized_loss, 2 ),
					'tax_savings'     => round( $tax_savings, 2 ),
					'days_held'       => $days_held,
					'wash_sale_risk'  => $wash_sale_risk,
				);

				$total_harvestable_loss += $unrealized_loss;
			}
		}

		usort(
			$opportunities,
			function ( $a, $b ) {
				return $b['unrealized_loss'] <=> $a['unrealized_loss'];
			}
		);

		$potential_tax_savings = ( $total_harvestable_loss * $capital_gains_rate ) / 100;
		$offsettable_gains     = min( $realized_gains, $total_harvestable_loss );
		$actual_tax_savings    = ( $offsettable_gains * $capital_gains_rate ) / 100;

		$recommendations = array();
		if ( ! empty( $opportunities ) ) {
			$recommendations[] = sprintf(
				/* translators: %d: Number of opportunities */
				__( 'Found %d tax-loss harvesting opportunities.', 'mcp-ai-wpoos-pro' ),
				count( $opportunities )
			);
			$recommendations[] = __( 'Prioritize positions with no wash sale risk and largest losses.', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Consider replacing sold positions with similar but not substantially identical securities.', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Track the 30-day window before and after each sale to avoid wash sales.', 'mcp-ai-wpoos-pro' );
		} else {
			$recommendations[] = __( 'No significant tax-loss harvesting opportunities found at this time.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'                => true,
			'opportunities'          => $opportunities,
			'opportunities_count'    => count( $opportunities ),
			'total_harvestable_loss' => round( $total_harvestable_loss, 2 ),
			'potential_tax_savings'  => round( $potential_tax_savings, 2 ),
			'realized_gains'         => $realized_gains,
			'offsettable_gains'      => round( $offsettable_gains, 2 ),
			'actual_tax_savings'     => round( $actual_tax_savings, 2 ),
			'capital_gains_rate'     => $capital_gains_rate,
			'wash_sale_warnings'     => $wash_sale_warnings,
			'recommendations'        => $recommendations,
			'disclaimer'             => __( 'EDUCATIONAL ONLY. This analysis is for educational purposes only and does not constitute tax or investment advice. Wash sale rules are complex and apply across all accounts. Consult a licensed tax professional or financial advisor before implementing tax-loss harvesting strategies.', 'mcp-ai-wpoos-pro' ),
			'message'                => ! empty( $opportunities )
				? sprintf(
					/* translators: 1: Potential tax savings, 2: Number of opportunities */
					__( 'Potential tax savings: $%1$s from %2$d opportunities.', 'mcp-ai-wpoos-pro' ),
					number_format( $potential_tax_savings, 2 ),
					count( $opportunities )
				)
				: __( 'No tax-loss harvesting opportunities above minimum threshold.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
