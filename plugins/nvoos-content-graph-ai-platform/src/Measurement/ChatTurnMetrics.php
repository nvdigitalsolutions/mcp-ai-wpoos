<?php
/**
 * Chat-Turn Stock Metric Definitions
 *
 * Registers the baseline set of metrics emitted for each chat turn
 * processed through the plugin's REST chat endpoint. PR 6 instrumented
 * individual tool calls; this PR instruments the turn that orchestrates
 * them — token usage, realised cost, wall-clock duration, and
 * success/error outcome.
 *
 * Design notes:
 *   - Every metric declares a `counter_metric` (Goodhart pairing). The
 *     measurement registry's `metrics_without_counter()` audit is part
 *     of the base rubric.
 *   - All definitions stay in the `internal` privacy tier. Metrics
 *     intentionally carry no prompt/response content — only provider,
 *     model, assistant_id and user_id (numeric). Anything richer has to
 *     move to `sensitive` or `restricted` per the privacy matrix.
 *   - Metrics are registered under `wp_mcp_ai_register_metrics` at
 *     priority 20 so third-party registrations at priority 10 can
 *     pre-empt a stock metric by id (first registration wins — see
 *     `MeasurementRegistry::register()`).
 *   - `chat.agentic.iterations` is emitted by the observer once per
 *     chat turn, carrying the total iteration count seen for that
 *     turn via the `wp_mcp_ai_agentic_iteration_complete` hook that
 *     the REST agentic loop fires after each iteration (PR 7.1).
 *     Sites can suppress emission by either disabling the observer
 *     (`wp_mcp_ai_chat_turn_observer_enabled`) or by removing the
 *     base-plugin hook.
 *
 * Opt-out: sites that want to disable stock chat-turn metric emission
 * entirely can return an empty array from the
 * `wp_mcp_ai_chat_turn_metrics_definitions` filter. The observer is
 * opt-out separately via `wp_mcp_ai_chat_turn_observer_enabled`.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat-turn stock metric registrar.
 */
class ChatTurnMetrics {

	/**
	 * Metric id: prompt tokens consumed per turn.
	 */
	const TOKEN_USAGE_PROMPT = 'token_usage.prompt_tokens';

	/**
	 * Metric id: completion tokens generated per turn.
	 */
	const TOKEN_USAGE_COMPLETION = 'token_usage.completion_tokens';

	/**
	 * Metric id: realised cost (USD) per turn.
	 */
	const TOKEN_USAGE_TOTAL_COST_USD = 'token_usage.total_cost_usd';

	/**
	 * Metric id: wall-clock chat-turn duration.
	 */
	const CHAT_TURN_DURATION_MS = 'chat.turn.duration_ms';

	/**
	 * Metric id: chat turns (total).
	 */
	const CHAT_TURN_COUNT = 'chat.turn.count';

	/**
	 * Metric id: chat turns that returned WP_Error or a provider-error response.
	 */
	const CHAT_TURN_ERROR_COUNT = 'chat.turn.error.count';

	/**
	 * Metric id: reserved for agentic-loop iteration count.
	 *
	 * Registered here so the id is claimed, but intentionally not
	 * emitted by the shipped observer — see class docblock.
	 */
	const CHAT_AGENTIC_ITERATIONS = 'chat.agentic.iterations';

	/**
	 * Return the canonical chat-turn metric definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions() {
		$definitions = array(
			array(
				'id'             => self::CHAT_TURN_COUNT,
				'label'          => __( 'Chat turns (total)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Total number of chat turns dispatched through the REST chat endpoint, regardless of outcome.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_COUNTER,
				'unit'           => 'turns',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::CHAT_TURN_ERROR_COUNT,
				'goodhart_note'  => 'Paired with chat.turn.error.count so "more turns" alone does not look like improvement.',
				'otel_attribute' => 'mcp_ai.chat.turn.count',
			),
			array(
				'id'             => self::CHAT_TURN_ERROR_COUNT,
				'label'          => __( 'Chat turns (error)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Chat turns that returned a WP_Error or whose provider response carried an error payload.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_COUNTER,
				'unit'           => 'turns',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::CHAT_TURN_COUNT,
				'goodhart_note'  => 'Lower-is-better can be gamed by swallowing errors upstream. Pair with chat.turn.count and verifier pass rate.',
				'owasp_llm_risk' => 'LLM07',
				'otel_attribute' => 'mcp_ai.chat.turn.error.count',
			),
			array(
				'id'             => self::CHAT_TURN_DURATION_MS,
				'label'          => __( 'Chat turn duration (ms)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Wall-clock duration between the before_chat_request and after_chat_response hooks for a single turn.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'ms',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::CHAT_TURN_ERROR_COUNT,
				'goodhart_note'  => 'Faster-is-better can be gamed by short-circuiting tool calls or truncating responses; always read alongside chat.turn.error.count and verifier pass rate.',
				'otel_attribute' => 'mcp_ai.chat.turn.duration_ms',
			),
			array(
				'id'             => self::TOKEN_USAGE_PROMPT,
				'label'          => __( 'Prompt tokens per turn', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Prompt-side tokens reported by the provider for a single chat turn.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'tokens',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::CHAT_TURN_COUNT,
				'goodhart_note'  => 'Minimising prompt tokens can be gamed by truncating context; pair with chat.turn.error.count and verifier pass rate to catch quality regressions.',
				'otel_attribute' => 'mcp_ai.token_usage.prompt_tokens',
			),
			array(
				'id'             => self::TOKEN_USAGE_COMPLETION,
				'label'          => __( 'Completion tokens per turn', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Completion-side tokens reported by the provider for a single chat turn.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'tokens',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::TOKEN_USAGE_TOTAL_COST_USD,
				'goodhart_note'  => 'Neither direction is strictly desirable — terse answers can hide under-answering. Always review paired with realised cost and verifier outcome.',
				'otel_attribute' => 'mcp_ai.token_usage.completion_tokens',
			),
			array(
				'id'             => self::TOKEN_USAGE_TOTAL_COST_USD,
				'label'          => __( 'Realised cost per turn (USD)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'USD cost reported by the plugin cost calculator for a single chat turn, sourced from the wp_mcp_ai_cost_calculated action.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'usd',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::CHAT_TURN_ERROR_COUNT,
				'goodhart_note'  => 'Cost reduction can be gamed by switching to cheaper models that fail verifier suites; pair with chat.turn.error.count and rubric pass rate.',
				'otel_attribute' => 'mcp_ai.token_usage.total_cost_usd',
			),
			array(
				'id'             => self::CHAT_AGENTIC_ITERATIONS,
				'label'          => __( 'Agentic iterations per turn', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Number of agentic tool-call rounds inside a single chat turn. Reserved definition — not emitted by the shipped observer; see docs/reference/measurement/chat-turn.md.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'iterations',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::CHAT_TURN_ERROR_COUNT,
				'goodhart_note'  => 'Neither direction is desirable — too few may indicate the model gave up, too many may indicate thrashing. Pair with chat.turn.error.count and verifier pass rate.',
				'otel_attribute' => 'mcp_ai.chat.agentic.iterations',
			),
		);

		/**
		 * Filters the chat-turn stock metric definitions before registration.
		 *
		 * Return an empty array to disable the entire chat-turn stock
		 * metric pack. Return a subset to register only specific metrics.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,array<string,mixed>> $definitions Chat-turn metric definitions.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_chat_turn_metrics_definitions', $definitions );
		return is_array( $filtered ) ? $filtered : $definitions;
	}

	/**
	 * Register chat-turn metrics on the measurement registry.
	 *
	 * Attached to `wp_mcp_ai_register_metrics` at priority 20 (leaving
	 * priority 10 as the standard override slot).
	 *
	 * @param MeasurementRegistry $registry Registry.
	 * @return int Count of metrics that were newly registered.
	 */
	public static function register( $registry ) {
		if ( ! $registry instanceof MeasurementRegistry ) {
			return 0;
		}
		return $registry->register_many( self::definitions() );
	}
}
