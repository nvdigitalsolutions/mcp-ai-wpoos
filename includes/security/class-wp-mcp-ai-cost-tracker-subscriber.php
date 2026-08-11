<?php
/**
 * Cost Tracker Hook Subscriber
 *
 * Wires WP_MCP_AI_Cost_Tracker into the tool execution lifecycle
 * via wp_mcp_ai_before_tool_execution / wp_mcp_ai_after_tool_execution.
 *
 * Estimates API cost before execution, checks the assistant's budget,
 * and records actual spend after successful completion.
 *
 * Priority 2: after DestructiveOpsGate (0) and CoSAI boundary (1),
 * before ConcurrencyGuard (3).
 *
 * @package WP_MCP_AI
 * @since   1.1.44
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscriber that enforces cost budget limits during tool execution.
 */
class WP_MCP_AI_Cost_Tracker_Subscriber {

	/**
	 * Register hooks.
	 *
	 * Safe no-op when WP_MCP_AI_Cost_Tracker is not loaded.
	 *
	 * @since 1.1.44
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( 'WP_MCP_AI_Cost_Tracker' ) ) {
			return;
		}
		add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before' ), 2, 3 );
		add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'on_after' ), 2, 4 );
	}

	/**
	 * Check budget before executing a tool.
	 *
	 * Estimates the cost of the upcoming tool execution and checks whether
	 * the assistant's configured budget would be exceeded. Throws an
	 * exception when over budget so the REST handler can return a 429
	 * response with budget details.
	 *
	 * @since 1.1.44
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return void
	 *
	 * @throws WP_MCP_AI_Cost_Budget_Exceeded When execution would exceed budget.
	 */
	public static function on_before( $tool_slug, $arguments, $context ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return;
		}

		$estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $arguments );
		$check    = WP_MCP_AI_Cost_Tracker::check_budget( $assistant_id, $estimate );

		if ( is_wp_error( $check ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception constructor parameters, not direct output.
			throw new WP_MCP_AI_Cost_Budget_Exceeded( $assistant_id, $check->get_error_message() );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Record the estimated cost after successful tool execution.
	 *
	 * Only records successful executions (not WP_Error results).
	 * Uses the same estimation function as on_before() for consistency.
	 *
	 * @since 1.1.44
	 *
	 * @param string $tool_slug  Tool identifier.
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param mixed  $result     Tool result (may be array or WP_Error).
	 * @return void
	 */
	public static function on_after( $tool_slug, $arguments, $context, $result ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return;
		}

		// Only record successful executions.
		// Budget-rejected calls never reach this point (exception thrown in on_before).
		if ( is_wp_error( $result ) ) {
			return;
		}

		$estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $arguments );
		WP_MCP_AI_Cost_Tracker::record( $assistant_id, $estimate );
	}
}
