<?php
/**
 * Harness Trace Capture — hook-based execution trace subscriber.
 *
 * Subscribes to the existing lifecycle hooks and writes structured trace
 * artifacts to the Harness Trace Store for every chat request where trace
 * capture is enabled. This class is a passive observer — it never blocks
 * or modifies the request; it only records.
 *
 * Activation: gated by the harness profile key `trace_capture.enabled`
 * (default: false). When disabled, this subscriber is a complete no-op.
 *
 * Artifacts captured per run:
 *   - meta.json            — started via `wp_mcp_ai_before_chat_request`
 *   - profile.json         — resolved harness profile
 *   - reasoning_trace.json — from `ReasoningTrace`
 *   - retrieval.json       — from `wp_mcp_ai_retrieval_passages` filter
 *   - tool_calls.jsonl     — per-call via `wp_mcp_ai_after_tool_execution`
 *   - self_refine.json     — from `wp_mcp_ai_self_refine_loop_completed`
 *   - cost.json            — from `wp_mcp_ai_cost_calculated`
 *   - model_response.txt   — from `wp_mcp_ai_after_chat_response`
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness Trace Capture subscriber.
 *
 * @since 1.9.0
 */
class HarnessTraceCapture {

	/**
	 * The active run ID for the current request, or null if not capturing.
	 *
	 * @since 1.9.0
	 * @var string|null
	 */
	private static $active_run_id = null;

	/**
	 * Accumulated tool call sequence counter.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	private static $tool_seq = 0;

	/**
	 * Accumulated cost data across all tool calls and chat requests.
	 *
	 * @since 1.9.0
	 * @var array
	 */
	private static $accumulated_cost = array(
		'total_tokens'       => 0,
		'prompt_tokens'      => 0,
		'completion_tokens'  => 0,
		'estimated_cost_usd' => 0.0,
	);

	/**
	 * Accumulated DSpark metrics for the current run.
	 *
	 * @since 1.9.0
	 * @var array
	 */
	private static $dspark_data = array(
		'depth_tier_used'         => '',
		'speculative_blocks'      => 0,
		'speculative_accepted'    => 0,
		'draft_tier_calls'        => 0,
		'verification_tier_calls' => 0,
		'estimated_savings_usd'   => 0.0,
	);

	/**
	 * Self-refine iterations recorded during the request.
	 *
	 * @since 1.9.0
	 * @var array
	 */
	private static $refine_iterations = array();

	/**
	 * Register all hook subscriptions.
	 *
	 * This is a static wiring method — it hooks into WordPress actions
	 * and filters but does nothing until trace capture is triggered for
	 * a specific request.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register() {
		// Chat lifecycle — start/finish trace runs.
		add_action( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'on_before_chat_request' ), 100, 4 );
		add_action( 'wp_mcp_ai_after_chat_response', array( __CLASS__, 'on_after_chat_response' ), 100, 3 );

		// Tool execution — record per-call artifacts.
		add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'on_after_tool_execution' ), 100, 6 );

		// Cost tracking.
		add_action( 'wp_mcp_ai_cost_calculated', array( __CLASS__, 'on_cost_calculated' ), 100, 5 );

		// Retrieval — capture passages as they're filtered.
		add_filter( 'wp_mcp_ai_retrieval_passages', array( __CLASS__, 'on_retrieval_passages' ), 100, 4 );
	}

	/**
	 * Fires before a chat request. Starts trace capture if the assistant's
	 * harness profile has `trace_capture.enabled` set.
	 *
	 * @since 1.9.0
	 *
	 * @param int             $assistant_id Assistant post ID.
	 * @param array           $messages     Chat messages.
	 * @param array           $options      Chat options.
	 * @param WP_REST_Request $request      REST request instance.
	 * @return void
	 */
	public static function on_before_chat_request( $assistant_id, $messages, $options, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $messages, $options, $request required by hook.
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return;
		}

		if ( ! self::is_capture_enabled( $assistant_id ) ) {
			return;
		}

		// Reset per-request state.
		self::$active_run_id     = null;
		self::$tool_seq          = 0;
		self::$accumulated_cost  = array(
			'total_tokens'       => 0,
			'prompt_tokens'      => 0,
			'completion_tokens'  => 0,
			'estimated_cost_usd' => 0.0,
		);
		self::$refine_iterations = array();
		self::$dspark_data       = array(
			'depth_tier_used'         => '',
			'speculative_blocks'      => 0,
			'speculative_accepted'    => 0,
			'draft_tier_calls'        => 0,
			'verification_tier_calls' => 0,
			'estimated_savings_usd'   => 0.0,
		);

		$model = isset( $options['model'] ) ? (string) $options['model'] : '';

		$run_id = HarnessTraceStore::start_run(
			$assistant_id,
			array(
				'model'    => $model,
				'provider' => self::detect_provider( $model ),
			)
		);

		if ( is_wp_error( $run_id ) ) {
			return;
		}

		self::$active_run_id = $run_id;

		// Write profile.json immediately so it's available even if the
		// request fails early.
		if ( class_exists( __NAMESPACE__ . '\\HarnessProfile' ) ) {
			$profile = HarnessProfile::get( $assistant_id );
			HarnessTraceStore::write_artifact( $run_id, 'profile.json', $profile );
		}
	}

	/**
	 * Fires after a chat response. Finalizes the trace run.
	 *
	 * @since 1.9.0
	 *
	 * @param int             $assistant_id Assistant post ID.
	 * @param array           $response     Response data.
	 * @param WP_REST_Request $request      REST request instance.
	 * @return void
	 */
	public static function on_after_chat_response( $assistant_id, $response, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $request required by hook.
		if ( null === self::$active_run_id ) {
			return;
		}

		$run_id = self::$active_run_id;

		// Write model response text.
		$response_text = self::extract_response_text( $response );
		if ( '' !== $response_text ) {
			HarnessTraceStore::write_text( $run_id, 'model_response.txt', $response_text );
		}

		// Write self-refine data if any was captured.
		if ( ! empty( self::$refine_iterations ) ) {
			HarnessTraceStore::write_artifact(
				$run_id,
				'self_refine.json',
				array(
					'iterations'  => self::$refine_iterations,
					'total_iters' => count( self::$refine_iterations ),
				)
			);
		}

		// Write accumulated cost.
		HarnessTraceStore::write_artifact( $run_id, 'cost.json', self::$accumulated_cost );

		// Write DSpark metrics if available.
		self::capture_dspark_metrics( $run_id );

		// Finish the run.
		HarnessTraceStore::finish_run( $run_id );

		self::$active_run_id = null;
	}

	/**
	 * Fires after each tool execution. Records the call in tool_calls.jsonl.
	 *
	 * @since 1.9.0
	 *
	 * @param string                                   $tool_slug  Tool slug.
	 * @param array                                    $arguments  Tool arguments.
	 * @param array                                    $context    Execution context.
	 * @param mixed                                    $result     Tool result.
	 * @param WP_MCP_AI_Tool_Lifecycle_Descriptor|null $descriptor Lifecycle descriptor (may be null in legacy paths).
	 * @return void
	 */
	public static function on_after_tool_execution( $tool_slug, $arguments, $context, $result, $descriptor = null ) {
		if ( null === self::$active_run_id ) {
			return;
		}

		++self::$tool_seq;

		$duration_ms = 0;
		if ( $descriptor instanceof \WP_MCP_AI_Tool_Lifecycle_Descriptor ) {
			$duration_ms = $descriptor->get_duration_ms();
		}

		$result_success = ! is_wp_error( $result );
		$result_type    = '';
		$result_summary = '';

		if ( $result_success ) {
			if ( is_array( $result ) ) {
				$result_type    = 'array';
				$result_summary = sprintf( '%d keys', count( $result ) );
			} elseif ( is_string( $result ) ) {
				$result_type    = 'string';
				$result_summary = self::truncate_summary( $result, 100 );
			} elseif ( is_object( $result ) ) {
				$result_type    = 'object:' . get_class( $result );
				$result_summary = 'object instance';
			} elseif ( is_bool( $result ) ) {
				$result_type    = 'bool';
				$result_summary = $result ? 'true' : 'false';
			} elseif ( is_int( $result ) || is_float( $result ) ) {
				$result_type    = 'scalar';
				$result_summary = (string) $result;
			} elseif ( is_null( $result ) ) {
				$result_type    = 'null';
				$result_summary = 'null';
			}
		} else {
			$result_type    = 'wp_error';
			$result_summary = $result->get_error_code();
		}

		$record = array(
			'seq'            => self::$tool_seq,
			'slug'           => (string) $tool_slug,
			'args_summary'   => self::summarize_args( $arguments ),
			'result_success' => $result_success,
			'result_type'    => $result_type,
			'result_summary' => $result_summary,
			'duration_ms'    => $duration_ms,
			'timestamp'      => time(),
		);

		HarnessTraceStore::append_jsonl( self::$active_run_id, 'tool_calls.jsonl', $record );
	}

	/**
	 * Fires when cost is calculated. Accumulates cost for the run.
	 *
	 * @since 1.9.0
	 *
	 * @param float  $cost_usd         Estimated cost in USD.
	 * @param int    $total_tokens     Total tokens used.
	 * @param int    $prompt_tokens    Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @param string $model            Model identifier.
	 * @return void
	 */
	public static function on_cost_calculated( $cost_usd, $total_tokens, $prompt_tokens, $completion_tokens, $model ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $model required by hook.
		self::$accumulated_cost['total_tokens']       += (int) $total_tokens;
		self::$accumulated_cost['prompt_tokens']      += (int) $prompt_tokens;
		self::$accumulated_cost['completion_tokens']  += (int) $completion_tokens;
		self::$accumulated_cost['estimated_cost_usd'] += (float) $cost_usd;
	}

	/**
	 * Filters retrieval passages. Captures the retrieval result for the trace.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $passages Retrieval passages.
	 * @param string $query    Query string.
	 * @param array  $scope    Scope hash.
	 * @param array  $context  Tool execution context.
	 * @return array Unmodified passages.
	 */
	public static function on_retrieval_passages( $passages, $query, $scope, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $scope, $context required by filter.
		// This filter passes through — we never modify the passages.
		if ( null === self::$active_run_id ) {
			return $passages;
		}

		$retrieval_data = array(
			'query'    => (string) $query,
			'passages' => array(),
		);

		// Sanitize passage data: extract only the fields we want to store.
		// Full passage text can be large; store summaries.
		foreach ( $passages as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$retrieval_data['passages'][] = array(
				'text_preview' => self::truncate_summary( isset( $entry['text'] ) ? (string) $entry['text'] : '', 200 ),
				'source'       => isset( $entry['source'] ) ? (string) $entry['source'] : '',
				'score'        => isset( $entry['score'] ) ? (float) $entry['score'] : 0.0,
				'freshness'    => isset( $entry['freshness'] ) ? (float) $entry['freshness'] : 0.0,
				'ranked_score' => isset( $entry['ranked_score'] ) ? (float) $entry['ranked_score'] : 0.0,
				'citation'     => isset( $entry['citation'] ) ? $entry['citation'] : array(),
			);
		}

		HarnessTraceStore::write_artifact(
			self::$active_run_id,
			'retrieval.json',
			$retrieval_data
		);

		return $passages;
	}

	/**
	 * Record a self-refine iteration for the current run.
	 *
	 * Called externally from `SelfRefineLoop` or its callers.
	 *
	 * @since 1.9.0
	 *
	 * @param array $iteration Iteration data: {iteration:int, verdict:string, feedback:string}.
	 * @return void
	 */
	public static function record_refine_iteration( array $iteration ) {
		self::$refine_iterations[] = array(
			'iteration'        => isset( $iteration['iteration'] ) ? (int) $iteration['iteration'] : count( self::$refine_iterations ) + 1,
			'verdict'          => isset( $iteration['verdict'] ) ? (string) $iteration['verdict'] : '',
			'feedback'         => isset( $iteration['feedback'] ) ? (string) $iteration['feedback'] : '',
			'candidate_length' => isset( $iteration['candidate_length'] ) ? (int) $iteration['candidate_length'] : 0,
		);
	}

	/**
	 * Record a reasoning trace for the current run.
	 *
	 * Called externally when a reasoning trace is available.
	 *
	 * @since 1.9.0
	 *
	 * @param array $trace Reasoning trace array (from ReasoningTrace).
	 * @return void
	 */
	public static function record_reasoning_trace( array $trace ) {
		if ( null === self::$active_run_id ) {
			return;
		}

		HarnessTraceStore::write_artifact(
			self::$active_run_id,
			'reasoning_trace.json',
			$trace
		);
	}

	/**
	 * Capture DSpark speculative execution metrics for the trace.
	 *
	 * Reads DSpark data from the options and transients maintained by
	 * the DSpark data collectors (`WP_MCP_AI_DSpark_Hooks`). If DSpark
	 * is not active, writes an empty artifact to signal that.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id Run ID.
	 * @return void
	 */
	private static function capture_dspark_metrics( $run_id ) {
		$dspark = self::$dspark_data;

		// Read from DSpark data collectors if available.
		$tier_counts = get_option( 'wp_mcp_ai_depth_tier_counts', array() );
		if ( is_array( $tier_counts ) ) {
			$dspark['tier_counts'] = $tier_counts;
		}

		$routing_savings = get_transient( 'wp_mcp_ai_routing_cost_data' );
		if ( is_array( $routing_savings ) ) {
			$dspark['routing_savings'] = $routing_savings;
		}

		// Read chain acceptance metrics.
		$acceptance = get_option( 'wp_mcp_ai_chain_acceptance_metrics', array() );
		if ( is_array( $acceptance ) ) {
			$dspark['chain_acceptance'] = $acceptance;
		}

		$dspark['captured_at'] = time();

		HarnessTraceStore::write_artifact( $run_id, 'dspark.json', $dspark );
	}

	/**
	 * Check whether trace capture is enabled for an assistant.
	 *
	 * Reads the harness profile and checks for `trace_capture.enabled`.
	 * Also respects a global override filter for emergency disable.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool
	 */
	public static function is_capture_enabled( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return false;
		}

		/**
		 * Master kill switch for trace capture. Return false to disable
		 * trace capture globally, regardless of per-assistant settings.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $enabled      Whether trace capture is allowed.
		 * @param int  $assistant_id Assistant post ID.
		 */
		$global_enabled = apply_filters( 'wp_mcp_ai_harness_trace_capture_enabled', true, $assistant_id );
		if ( ! $global_enabled ) {
			return false;
		}

		if ( ! class_exists( __NAMESPACE__ . '\\HarnessProfile' ) ) {
			return false;
		}

		$profile = HarnessProfile::get( $assistant_id );

		if ( empty( $profile['enabled'] ) ) {
			return false;
		}

		// Check for the trace_capture key on the profile.
		// This key is not yet in the default profile schema; it's
		// additive and will be formalized when the admin UI ships.
		if ( isset( $profile['trace_capture'] ) && is_array( $profile['trace_capture'] ) ) {
			return ! empty( $profile['trace_capture']['enabled'] );
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract response text from the chat response array.
	 *
	 * @since 1.9.0
	 *
	 * @param array $response Response data.
	 * @return string
	 */
	private static function extract_response_text( $response ) {
		if ( ! is_array( $response ) ) {
			return '';
		}

		// Try common response shapes.
		if ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
			return $response['content'];
		}

		if ( isset( $response['message'] ) && is_string( $response['message'] ) ) {
			return $response['message'];
		}

		if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
			return $response['data'];
		}

		// Try choices[0].message.content (OpenAI shape).
		if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
			$choice = reset( $response['choices'] );
			if ( is_array( $choice ) && isset( $choice['message']['content'] ) ) {
				return (string) $choice['message']['content'];
			}
		}

		return '';
	}

	/**
	 * Truncate a string to a maximum length with ellipsis indicator.
	 *
	 * @since 1.9.0
	 *
	 * @param string $text    Input text.
	 * @param int    $max_len Maximum length in characters.
	 * @return string
	 */
	private static function truncate_summary( $text, $max_len ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max_len ) {
			return $text;
		}
		return substr( $text, 0, $max_len - 3 ) . '...';
	}

	/**
	 * Create a compact summary of tool arguments for trace storage.
	 *
	 * @since 1.9.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string JSON-encoded summary (max 500 chars).
	 */
	private static function summarize_args( $arguments ) {
		if ( ! is_array( $arguments ) || empty( $arguments ) ) {
			return '{}';
		}

		// For large argument arrays, summarize key count and top-level keys.
		$summary = array(
			'_arg_count' => count( $arguments ),
			'_arg_keys'  => array_keys( $arguments ),
		);

		// Include scalar values for small args (for searchability).
		foreach ( $arguments as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$val_str = (string) $value;
				if ( strlen( $val_str ) <= 200 ) {
					$summary[ $key ] = $value;
				} else {
					$summary[ $key ] = self::truncate_summary( $val_str, 200 );
				}
			} elseif ( is_array( $value ) ) {
				$summary[ $key ] = sprintf( '[array:%d]', count( $value ) );
			}
		}

		$encoded = wp_json_encode( $summary, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return '{}';
		}

		return self::truncate_summary( $encoded, 500 );
	}

	/**
	 * Detect the AI provider from a model identifier.
	 *
	 * @since 1.9.0
	 *
	 * @param string $model Model identifier.
	 * @return string Provider slug (openai, anthropic, gemini, deepseek, ollama, or '').
	 */
	private static function detect_provider( $model ) {
		$model = strtolower( (string) $model );

		if ( strpos( $model, 'gpt-' ) === 0 || strpos( $model, 'o1' ) === 0 || strpos( $model, 'o3' ) === 0 || strpos( $model, 'o4' ) === 0 ) {
			return 'openai';
		}
		if ( strpos( $model, 'claude-' ) === 0 ) {
			return 'anthropic';
		}
		if ( strpos( $model, 'gemini-' ) === 0 ) {
			return 'gemini';
		}
		if ( strpos( $model, 'deepseek-' ) === 0 ) {
			return 'deepseek';
		}
		if ( strpos( $model, 'llama' ) !== false || strpos( $model, 'mistral' ) !== false || strpos( $model, 'mixtral' ) !== false ) {
			return 'ollama';
		}

		return '';
	}
}
