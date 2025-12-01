<?php
/**
 * Orchestration Budget Enforcement Service
 *
 * Applies orchestration layer settings to control budget management features.
 * This service hooks into resource manager filters to enforce orchestration policies.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces orchestration budget policies based on settings.
 */
class WP_MCP_AI_Orchestration_Budget_Enforcement_Service {

	/**
	 * Initialize the service and register hooks.
	 */
	public static function init() {
		// Hook into resource manager filters.
		add_filter( 'wp_mcp_ai_resource_max_tokens', array( __CLASS__, 'apply_budget_management_to_max_tokens' ), 5, 2 );
		add_filter( 'wp_mcp_ai_resource_request_timeout', array( __CLASS__, 'apply_budget_management_to_timeout' ), 5, 4 );
	}

	/**
	 * Apply budget management setting to max tokens.
	 *
	 * If budget management is disabled, returns a high default value instead of tier-based limits.
	 *
	 * @param int    $max_tokens The tier-based max tokens.
	 * @param string $tier       The current workload tier.
	 * @return int Modified max tokens.
	 */
	public static function apply_budget_management_to_max_tokens( $max_tokens, $tier ) {
		// Check if budget management is enabled.
		$budget_enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_budget_management', true );

		// If budget management is disabled, return a high default value.
		if ( ! $budget_enabled ) {
			/**
			 * Filter the maximum tokens when budget management is disabled.
			 *
			 * @param int $max_tokens The maximum tokens (default: 128000).
			 */
			return apply_filters( 'wp_mcp_ai_resource_max_tokens_unlimited', 128000 );
		}

		// Budget management is enabled, return the tier-based value.
		return $max_tokens;
	}

	/**
	 * Apply budget management setting to request timeout.
	 *
	 * If budget management is disabled, returns high tier timeout unconditionally.
	 *
	 * @param int    $timeout               The tier-based timeout.
	 * @param string $tier                  The current workload tier.
	 * @param int    $max_execution_time    The PHP max_execution_time setting.
	 * @param bool   $ignore_execution_time Whether execution time constraint was ignored.
	 * @return int Modified timeout.
	 */
	public static function apply_budget_management_to_timeout( $timeout, $tier, $max_execution_time, $ignore_execution_time ) {
		// Check if budget management is enabled.
		$budget_enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_budget_management', true );

		// If budget management is disabled, return high tier timeout unconditionally.
		// This removes all budget constraints including max_execution_time caps.
		if ( ! $budget_enabled ) {
			$timeout_map = array(
				'low'    => 30,
				'medium' => 60,
				'high'   => 120,
			);

			/**
			 * Filter the timeout when budget management is disabled.
			 *
			 * @param int $timeout The timeout value (default: 120 seconds).
			 */
			return apply_filters( 'wp_mcp_ai_resource_request_timeout_unlimited', $timeout_map['high'] );
		}

		// Budget management is enabled, return the tier-based value.
		return $timeout;
	}

	/**
	 * Check if capability gating is enabled.
	 *
	 * @return bool True if capability gating is enabled.
	 */
	public static function is_capability_gating_enabled() {
		return WP_MCP_AI_Settings_Registry::get_setting( 'enable_capability_gating', true );
	}

	/**
	 * Check if cron orchestration is enabled.
	 *
	 * @return bool True if cron orchestration is enabled.
	 */
	public static function is_cron_orchestration_enabled() {
		return WP_MCP_AI_Settings_Registry::get_setting( 'enable_cron_orchestration', true );
	}

	/**
	 * Check if predictive optimization is enabled.
	 *
	 * @return bool True if predictive optimization is enabled.
	 */
	public static function is_predictive_optimization_enabled() {
		return WP_MCP_AI_Settings_Registry::get_setting( 'enable_predictive_optimization', true );
	}
}
