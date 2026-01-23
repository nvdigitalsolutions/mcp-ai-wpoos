<?php
/**
 * Cash Flow Analyzer Tool - Analyze income vs expenses with forecasting
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cash Flow Analyzer Tool class.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Cash_Flow_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if the tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if available, false otherwise.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the unavailable reason.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'Financial planner toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'cash_flow_analyzer';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Cash Flow Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Analyze income vs expenses with monthly forecasting. Track cash flow trends, identify surplus/deficit months, and project future cash positions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'monthly_income'         => array(
					'type'        => 'number',
					'description' => __( 'Monthly income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'monthly_expenses'       => array(
					'type'        => 'number',
					'description' => __( 'Monthly expenses', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'forecast_months'        => array(
					'type'        => 'integer',
					'description' => __( 'Months to forecast', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 36,
					'default'     => 12,
				),
				'income_growth_rate'     => array(
					'type'        => 'number',
					'description' => __( 'Annual income growth rate (%)', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'expense_inflation_rate' => array(
					'type'        => 'number',
					'description' => __( 'Annual expense inflation rate (%)', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
				),
			),
			'required'   => array( 'monthly_income', 'monthly_expenses' ),
		);
	}

	/**
	 * Get the capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array Capability flags.
	 */
	public function get_capability_flags() {
		return array( 'pro', 'computation' );
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Context data.
	 *
	 * @return array|WP_Error Analysis result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$monthly_income    = floatval( $arguments['monthly_income'] ?? 0 );
		$monthly_expenses  = floatval( $arguments['monthly_expenses'] ?? 0 );
		$forecast_months   = absint( $arguments['forecast_months'] ?? 12 );
		$income_growth     = floatval( $arguments['income_growth_rate'] ?? 0 ) / 100 / 12;
		$expense_inflation = floatval( $arguments['expense_inflation_rate'] ?? 3 ) / 100 / 12;

		$monthly_cash_flow = $monthly_income - $monthly_expenses;
		$projections       = array();
		$income            = $monthly_income;
		$expenses          = $monthly_expenses;

		for ( $month = 1; $month <= $forecast_months; $month++ ) {
			$income       *= ( 1 + $income_growth );
			$expenses     *= ( 1 + $expense_inflation );
			$cash_flow     = $income - $expenses;
			$projections[] = array(
				'month'     => $month,
				'income'    => round( $income, 2 ),
				'expenses'  => round( $expenses, 2 ),
				'cash_flow' => round( $cash_flow, 2 ),
			);
		}

		return array(
			'success'                   => true,
			'current_monthly_cash_flow' => round( $monthly_cash_flow, 2 ),
			'is_positive'               => $monthly_cash_flow > 0,
			'projections'               => $projections,
			/* translators: %s: formatted currency amount */
			'message'                   => sprintf( __( 'Current monthly cash flow: $%s', 'mcp-ai-wpoos-pro' ), number_format( $monthly_cash_flow, 2 ) ),
		);
	}
}
