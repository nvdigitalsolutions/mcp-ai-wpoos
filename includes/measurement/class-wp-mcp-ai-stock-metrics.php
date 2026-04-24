<?php
/**
 * Stock Metric Definitions
 *
 * Registers the baseline set of metrics that the plugin emits from its
 * own code paths. These are the first metrics the measurement registry
 * actually receives — PRs 1–5 defined the infrastructure, and this PR
 * gives that infrastructure its first real inputs.
 *
 * Design notes:
 *   - Every metric declares a `counter_metric` (Goodhart pairing). The
 *     measurement registry's `metrics_without_counter()` audit is part
 *     of the base rubric; leaving counters blank would be a regression.
 *   - All definitions stay in the `internal` privacy tier. Any future
 *     metric that touches user content MUST move to `sensitive` or
 *     `restricted` (see `docs/measurement/privacy-matrix.md`).
 *   - Metrics are registered under the canonical
 *     `wp_mcp_ai_register_metrics` hook at priority 20 so third-party
 *     registrations at priority 10 can pre-empt a stock metric by
 *     id (the first registration wins — see
 *     `WP_MCP_AI_Measurement_Registry::register()`).
 *
 * Opt-out: sites that want to disable stock metric emission entirely
 * can return an empty array from the
 * `wp_mcp_ai_stock_metrics_definitions` filter.
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
 * Stock metric registrar.
 */
class WP_MCP_AI_Stock_Metrics {

	/**
	 * Metric id: tool execution count.
	 */
	const TOOL_EXECUTION_COUNT = 'tool.execution.count';

	/**
	 * Metric id: tool execution duration histogram.
	 */
	const TOOL_EXECUTION_DURATION_MS = 'tool.execution.duration_ms';

	/**
	 * Metric id: successful tool executions.
	 */
	const TOOL_EXECUTION_SUCCESS_COUNT = 'tool.execution.success.count';

	/**
	 * Metric id: failed tool executions (WP_Error returns or thrown exceptions).
	 */
	const TOOL_EXECUTION_ERROR_COUNT = 'tool.execution.error.count';

	/**
	 * Metric id: in-flight tool executions (gauge).
	 */
	const TOOL_EXECUTION_IN_FLIGHT = 'tool.execution.in_flight';

	/**
	 * Return the canonical stock metric definitions.
	 *
	 * Exposed as a static method (rather than a constant) so docstrings
	 * and i18n calls evaluate at runtime — translators expect this
	 * layer to be on, not frozen at class-load time.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions() {
		$definitions = array(
			array(
				'id'             => self::TOOL_EXECUTION_COUNT,
				'label'          => __( 'Tool executions (total)', 'mcp-ai-wpoos' ),
				'description'    => __( 'Total number of tool executions dispatched via the plugin, regardless of outcome.', 'mcp-ai-wpoos' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'           => 'calls',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_NEUTRAL,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::TOOL_EXECUTION_ERROR_COUNT,
				'goodhart_note'  => 'Paired with tool.execution.error.count so "more calls" alone does not look like improvement.',
				'otel_attribute' => 'mcp_ai.tool.execution.count',
			),
			array(
				'id'             => self::TOOL_EXECUTION_DURATION_MS,
				'label'          => __( 'Tool execution duration (ms)', 'mcp-ai-wpoos' ),
				'description'    => __( 'Wall-clock duration of a single tool execution, measured between the before/after hooks.', 'mcp-ai-wpoos' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_HISTOGRAM,
				'unit'           => 'ms',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::TOOL_EXECUTION_SUCCESS_COUNT,
				'goodhart_note'  => 'Faster-is-better can be gamed by short-circuiting work; always read alongside tool.execution.success.count to catch "fast failures".',
				'otel_attribute' => 'mcp_ai.tool.execution.duration_ms',
			),
			array(
				'id'             => self::TOOL_EXECUTION_SUCCESS_COUNT,
				'label'          => __( 'Tool executions (success)', 'mcp-ai-wpoos' ),
				'description'    => __( 'Tool executions that returned a non-WP_Error result without raising an exception.', 'mcp-ai-wpoos' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'           => 'calls',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_HIGHER_IS_BETTER,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::TOOL_EXECUTION_ERROR_COUNT,
				'goodhart_note'  => 'Higher success counts can be gamed by no-op or auto-approving tools. Pair with error count and with verifier pass rate from the eval harness.',
				'otel_attribute' => 'mcp_ai.tool.execution.success.count',
			),
			array(
				'id'             => self::TOOL_EXECUTION_ERROR_COUNT,
				'label'          => __( 'Tool executions (error)', 'mcp-ai-wpoos' ),
				'description'    => __( 'Tool executions whose result was a WP_Error or which raised an exception.', 'mcp-ai-wpoos' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'           => 'calls',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::TOOL_EXECUTION_SUCCESS_COUNT,
				'goodhart_note'  => 'Lower-is-better can be gamed by swallowing errors. Pair with success count and with verifier pass rate.',
				'owasp_llm_risk' => 'LLM07', // Insecure plugin design — high error rate can indicate tool-contract drift.
				'otel_attribute' => 'mcp_ai.tool.execution.error.count',
			),
			array(
				'id'             => self::TOOL_EXECUTION_IN_FLIGHT,
				'label'          => __( 'Tool executions (in flight)', 'mcp-ai-wpoos' ),
				'description'    => __( 'Snapshot of currently-executing tool invocations at record time.', 'mcp-ai-wpoos' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_GAUGE,
				'unit'           => 'calls',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_NEUTRAL,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::TOOL_EXECUTION_DURATION_MS,
				'goodhart_note'  => 'Saturation signal. Pair with duration to distinguish "many fast calls" from "a pile-up".',
				'otel_attribute' => 'mcp_ai.tool.execution.in_flight',
			),
		);

		/**
		 * Filters the stock metric definitions before registration.
		 *
		 * Return an empty array to disable the entire stock metric
		 * pack. Return a subset to register only specific metrics.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,array<string,mixed>> $definitions Stock metric definitions.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_stock_metrics_definitions', $definitions );
		return is_array( $filtered ) ? $filtered : $definitions;
	}

	/**
	 * Register stock metrics on the measurement registry.
	 *
	 * Attached to `wp_mcp_ai_register_metrics` at priority 20 (leaving
	 * priority 10 as the standard override slot).
	 *
	 * @param WP_MCP_AI_Measurement_Registry $registry Registry.
	 * @return int Count of metrics that were newly registered.
	 */
	public static function register( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Measurement_Registry ) {
			return 0;
		}
		return $registry->register_many( self::definitions() );
	}
}
