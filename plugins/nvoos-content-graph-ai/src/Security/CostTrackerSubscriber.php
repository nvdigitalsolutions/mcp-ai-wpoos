<?php
/**
 * Cost Tracker Hook Subscriber for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-cost-tracker-subscriber.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Hook priorities (2) and the estimate/check/record
 * lifecycle keep their base semantics.
 *
 * Decoupling (documented, additive):
 * - `register()` is registered standalone-only by `Plugin.php` — in
 *   monolith installs the base plugin owns the same tool-execution hooks.
 *
 * Priority 2: after DestructiveOpsGate (0) and CoSAI boundary (1),
 * before ConcurrencyGuard (3).
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

use NvoosContentGraphAi\Security\Exceptions\CostBudgetExceeded;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscriber that enforces cost budget limits during tool execution.
 *
 * @since 1.1.0
 */
class CostTrackerSubscriber {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
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
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return void
	 *
	 * @throws CostBudgetExceeded When execution would exceed budget.
	 */
	public static function on_before( $tool_slug, $arguments, $context ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return;
		}

		$estimate = CostTracker::estimate( $tool_slug, $arguments );
		$check    = CostTracker::check_budget( $assistant_id, $estimate );

		if ( is_wp_error( $check ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception constructor parameters, not direct output.
			throw new CostBudgetExceeded( $assistant_id, $check->get_error_message() );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Record the estimated cost after successful tool execution.
	 *
	 * Only records successful executions (not WP_Error results).
	 * Uses the same estimation function as on_before() for consistency.
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

		$estimate = CostTracker::estimate( $tool_slug, $arguments );
		CostTracker::record( $assistant_id, $estimate );
	}
}
