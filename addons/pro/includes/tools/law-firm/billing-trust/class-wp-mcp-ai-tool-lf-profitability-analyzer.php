<?php
/**
 * Profitability Analyzer Tool
 *
 * Analyzes matter profitability including revenue, costs, and margin.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes matter profitability with revenue, cost, and margin calculations.
 */
class WP_MCP_AI_Tool_LF_Profitability_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_profitability_analyzer'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Profitability Analyzer', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyzes matter profitability by calculating total revenue, costs (hours × cost rate), overhead, profit margin, and realization rate.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID to analyze.', 'mcp-ai-wpoos-pro' ),
				),
				'include_overhead' => array(
					'type'        => 'boolean',
					'description' => __( 'Include overhead in cost calculation (default true).', 'mcp-ai-wpoos-pro' ),
				),
				'overhead_rate'    => array(
					'type'        => 'number',
					'description' => __( 'Overhead rate as decimal (default 0.40 = 40%).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'matter_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

	/**
	 * {@inheritdoc}
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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$matter_id        = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$include_overhead = isset( $arguments['include_overhead'] ) ? (bool) $arguments['include_overhead'] : true;
		$overhead_rate    = isset( $arguments['overhead_rate'] ) ? floatval( $arguments['overhead_rate'] ) : 0.40;

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_profitability_analyzer', 0, 1000 ) : 1000,
				'meta_query'     => array(
					array(
						'key'   => '_lf_matter_id',
						'value' => $matter_id,
					),
				), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$total_revenue   = 0;
		$total_hours     = 0;
		$total_billed    = 0;
		$cost_rate_total = 0;

		foreach ( $entries as $entry ) {
			$hours  = (float) get_post_meta( $entry->ID, '_lf_hours', true );
			$rate   = (float) get_post_meta( $entry->ID, '_lf_rate', true );
			$amount = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$type   = get_post_meta( $entry->ID, '_lf_billing_type', true );

			$total_hours  += $hours;
			$total_billed += $hours * $rate;

			if ( 'billable' === $type ) {
				$total_revenue += $amount;
			}

			// Assume cost rate is 40% of billing rate as a default heuristic.
			$cost_rate_total += $hours * ( $rate * 0.4 );
		}

		// Add expenses to costs.
		$expenses      = get_post_meta( $matter_id, '_lf_expenses', true );
		$expense_total = 0;
		if ( is_array( $expenses ) ) {
			foreach ( $expenses as $exp ) {
				$expense_total += (float) ( $exp['amount'] ?? 0 );
			}
		}

		$total_cost = $cost_rate_total + $expense_total;
		if ( $include_overhead ) {
			$total_cost += $cost_rate_total * $overhead_rate;
		}

		$total_cost  = round( $total_cost, 2 );
		$profit      = round( $total_revenue - $total_cost, 2 );
		$margin      = $total_revenue > 0 ? round( ( $profit / $total_revenue ) * 100, 1 ) : 0;
		$realization = $total_billed > 0 ? round( ( $total_revenue / $total_billed ) * 100, 1 ) : 0;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %1$s: profit margin percentage, %2$s: total revenue */
				__( 'Profitability: %1$s margin on $%2$s revenue. ', 'mcp-ai-wpoos-pro' ),
				$margin . '%',
				number_format( $total_revenue, 2 )
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'        => $matter_id,
				'total_revenue'    => $total_revenue,
				'total_cost'       => $total_cost,
				'profit'           => $profit,
				'margin'           => $margin,
				'realization_rate' => $realization,
				'total_hours'      => round( $total_hours, 1 ),
				'expense_total'    => round( $expense_total, 2 ),
				'overhead_applied' => $include_overhead,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
