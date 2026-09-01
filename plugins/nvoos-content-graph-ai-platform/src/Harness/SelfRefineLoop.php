<?php
/**
 * Self-Refine Loop — Layer E synchronous primitive.
 *
 * Implements Self-Refine (Madaan et al. 2023) and the verbal-reflection
 * portion of Reflexion (Shinn et al. 2023) as a synchronous, bounded loop:
 *
 *   1. Generate an initial candidate (caller supplies a generator callable).
 *   2. Critique the candidate (caller supplies a critic callable; the
 *      existing `Agent_Role_Critic` is the canonical option).
 *   3. If the critic returns no actionable feedback, stop early.
 *   4. Otherwise, ask the generator to revise using the critique.
 *   5. Repeat until `max_iters` (hard-capped at
 *      `HarnessProfile::MAX_REFINE_ITERATIONS`) or until the
 *      cost ceiling is reached.
 *   6. Optionally persist a verbal reflection ("Next time, check Y before
 *      answering") into agent memory after PII scrubbing.
 *
 * The loop is intentionally provider-agnostic — generator and critic are
 * plain callables — so it can be unit-tested without an HTTP roundtrip.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
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
 * Synchronous Self-Refine loop primitive.
 */
class SelfRefineLoop {

	/**
	 * Run the loop.
	 *
	 * @param string   $task      The task / prompt being solved.
	 * @param callable $generator function( string $task, ?string $previous_answer = null, ?string $critique = null ): string|WP_Error
	 *                            Called once for the initial draft and once per revision.
	 * @param callable $critic    function( string $task, string $candidate ): array{verdict:string,feedback:string}
	 *                            Verdict must be one of: 'accept' (stop), 'revise' (continue), 'reject' (stop, surface failure).
	 *                            Feedback should be a short, actionable critique.
	 * @param array    $opts      Options:
	 *   - max_iters (int)        : hard cap on iterations (clamped).
	 *   - cost_per_iter (float)  : estimated USD cost per iteration; loop bails if cumulative cost would exceed cost_ceiling.
	 *   - cost_ceiling (float)   : USD cap.
	 * @return array{
	 *   answer:string,
	 *   iterations:int,
	 *   verdicts:array<int,string>,
	 *   feedback:array<int,string>,
	 *   stopped_reason:string,
	 *   estimated_cost_usd:float
	 * }|WP_Error
	 */
	public static function run( $task, callable $generator, callable $critic, array $opts = array() ) {
		$task = (string) $task;
		if ( '' === trim( $task ) ) {
			return new \WP_Error( 'wp_mcp_ai_self_refine_empty_task', __( 'Self-Refine requires a non-empty task.', 'nvoos-content-graph-ai-platform' ) );
		}

		$max_iters = isset( $opts['max_iters'] ) ? (int) $opts['max_iters'] : 2;
		if ( $max_iters < 1 ) {
			$max_iters = 1;
		}
		if ( $max_iters > HarnessProfile::MAX_REFINE_ITERATIONS ) {
			$max_iters = HarnessProfile::MAX_REFINE_ITERATIONS;
		}

		$cost_per_iter = isset( $opts['cost_per_iter'] ) ? (float) $opts['cost_per_iter'] : 0.0;
		$cost_ceiling  = isset( $opts['cost_ceiling'] ) ? (float) $opts['cost_ceiling'] : HarnessProfile::DEFAULT_COST_CEILING_USD;

		$verdicts = array();
		$feedback = array();
		$cost     = 0.0;

		// Iteration 1: initial draft.
		$cost += $cost_per_iter;
		if ( $cost_ceiling > 0 && $cost > $cost_ceiling ) {
			return new \WP_Error( 'wp_mcp_ai_self_refine_cost_exceeded', __( 'Self-Refine aborted: estimated cost exceeds ceiling.', 'nvoos-content-graph-ai-platform' ) );
		}
		$candidate = call_user_func( $generator, $task, null, null );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}
		$candidate = (string) $candidate;

		$stopped_reason = 'max_iters';
		for ( $i = 1; $i <= $max_iters; ++$i ) {
			$verdict_raw = call_user_func( $critic, $task, $candidate );
			if ( is_wp_error( $verdict_raw ) ) {
				return $verdict_raw;
			}
			$verdict    = self::sanitize_verdict( $verdict_raw );
			$verdicts[] = $verdict['verdict'];
			$feedback[] = $verdict['feedback'];

			// Record the iteration in the harness trace capture if active.
			if ( class_exists( __NAMESPACE__ . '\\HarnessTraceCapture' ) ) {
				HarnessTraceCapture::record_refine_iteration(
					array(
						'iteration'        => $i,
						'verdict'          => $verdict['verdict'],
						'feedback'         => $verdict['feedback'],
						'candidate_length' => strlen( $candidate ),
					)
				);
			}

			if ( 'accept' === $verdict['verdict'] || '' === trim( $verdict['feedback'] ) ) {
				$stopped_reason = 'accepted';
				break;
			}

			if ( 'reject' === $verdict['verdict'] ) {
				$stopped_reason = 'rejected';
				break;
			}

			if ( $i === $max_iters ) {
				$stopped_reason = 'max_iters';
				break;
			}

			$cost += $cost_per_iter;
			if ( $cost_ceiling > 0 && $cost > $cost_ceiling ) {
				$stopped_reason = 'cost_ceiling';
				break;
			}

			$revised = call_user_func( $generator, $task, $candidate, $verdict['feedback'] );
			if ( is_wp_error( $revised ) ) {
				return $revised;
			}
			$candidate = (string) $revised;
		}

		return array(
			'answer'             => $candidate,
			'iterations'         => count( $verdicts ),
			'verdicts'           => $verdicts,
			'feedback'           => $feedback,
			'stopped_reason'     => $stopped_reason,
			'estimated_cost_usd' => round( $cost, 6 ),
		);
	}

	/**
	 * Sanitize a critic verdict to the contracted shape.
	 *
	 * @param mixed $raw Raw verdict.
	 * @return array{verdict:string,feedback:string}
	 */
	private static function sanitize_verdict( $raw ) {
		$verdict  = 'revise';
		$feedback = '';

		if ( is_array( $raw ) ) {
			if ( isset( $raw['verdict'] ) ) {
				$candidate = sanitize_key( (string) $raw['verdict'] );
				if ( in_array( $candidate, array( 'accept', 'revise', 'reject' ), true ) ) {
					$verdict = $candidate;
				}
			}
			if ( isset( $raw['feedback'] ) ) {
				$feedback = (string) $raw['feedback'];
			}
		} elseif ( is_string( $raw ) ) {
			$feedback = $raw;
		}

		return array(
			'verdict'  => $verdict,
			'feedback' => $feedback,
		);
	}

	/**
	 * Persist a verbal reflection (Reflexion-style) for future few-shot use.
	 * Reflections pass through the PII filter before being stored. The
	 * actual storage is delegated to the existing `store_agent_context`
	 * tool when available, which keeps the harness loosely coupled to the
	 * memory backend.
	 *
	 * @param string $reflection  Verbal reflection text.
	 * @param string $task_class  Task class to scope the memory to.
	 * @param array  $context     Tool execution context.
	 * @return array|WP_Error Result from the underlying tool, or WP_Error if storage is unavailable.
	 */
	public static function record_reflection( $reflection, $task_class = 'general', array $context = array() ) {
		$reflection = (string) $reflection;
		if ( '' === trim( $reflection ) ) {
			return new \WP_Error( 'wp_mcp_ai_self_refine_empty_reflection', __( 'Reflection text is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$task_class = sanitize_key( (string) $task_class );
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$scrub           = PiiFilter::scrub( $reflection );
		$cleaned         = $scrub['text'];
		$redaction_count = $scrub['redactions'];

		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new \WP_Error( 'wp_mcp_ai_self_refine_no_registry', __( 'Tool registry unavailable.', 'nvoos-content-graph-ai-platform' ) );
		}

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'store_agent_context' );
		if ( ! $tool instanceof \WP_MCP_AI_Tool_Interface ) {
			return new \WP_Error( 'wp_mcp_ai_self_refine_no_store_tool', __( 'store_agent_context tool not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		$args = array(
			'content' => $cleaned,
			'tags'    => array( 'reflection', 'task_class:' . $task_class ),
			'kind'    => 'reflection',
		);

		try {
			$result = $tool->execute( $args, $context );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wp_mcp_ai_self_refine_store_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			$result['pii_redactions'] = $redaction_count;
		}
		return $result;
	}
}
