<?php
/**
 * Tool Router Harness — Layer C scoring façade.
 *
 * Score and rank candidate tools for a given task class. Extends — does not
 * replace — the existing `Tool_Chain_Predictor`. The score is a transparent
 * weighted sum over capability flags, recent reliability (when the
 * measurement subsystem has data for the slug), and the assistant's declared
 * preferences.
 *
 * The score is exposed through the `wp_mcp_ai_harness_tool_score` filter so
 * the Pro addon can swap in a learned model (e.g. logistic regression over
 * eval-harness outcomes) without touching the base implementation.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Router Harness.
 */
class WP_MCP_AI_Tool_Router_Harness {

	/**
	 * Coarse mapping from task class to the capability flags that tend to
	 * help. Intentionally conservative — better to miss a useful tool than to
	 * recommend a write tool for a read-only task.
	 *
	 * @return array<string,array<string,float>>
	 */
	private static function task_flag_weights() {
		return array(
			'general'  => array(
				'read-only' => 1.0,
			),
			'qa'       => array(
				'read-only' => 1.5,
				'cacheable' => 0.5,
			),
			'research' => array(
				'read-only'    => 1.5,
				'external-api' => 1.0,
				'cacheable'    => 0.5,
			),
			'rag'      => array(
				'read-only' => 2.0,
			),
			'math'     => array(
				'local-only' => 1.5,
				'idempotent' => 1.0,
			),
			'code'     => array(
				'read-only'  => 1.0,
				'idempotent' => 1.0,
			),
			'agentic'  => array(
				'read-only'   => 1.0,
				'reversible'  => 0.8,
				'idempotent'  => 0.5,
			),
		);
	}

	/**
	 * Score a single tool for a task class.
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool         Tool instance.
	 * @param string                   $task_class   Task class slug.
	 * @param array                    $assistant_prefs Per-assistant preferences (slugs prioritized → weight).
	 * @return float Score; higher is better.
	 */
	public static function score_tool( $tool, $task_class, array $assistant_prefs = array() ) {
		$task_class = sanitize_key( (string) $task_class );
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$score = 1.0;

		$flags = array();
		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = (array) $tool->get_capability_flags();
		}

		$weights_table = self::task_flag_weights();
		$weights       = isset( $weights_table[ $task_class ] ) ? $weights_table[ $task_class ] : $weights_table['general'];
		foreach ( $weights as $flag => $weight ) {
			if ( in_array( $flag , $flags, true ) ) {
				$score += (float) $weight;
			}
		}

		// Penalize state-changing or write tools for read-leaning task classes.
		if ( in_array( $task_class, array( 'qa', 'research', 'rag', 'math' ), true ) ) {
			if ( in_array( 'state-changing', $flags, true ) || in_array( 'write', $flags, true ) ) {
				$score -= 1.5;
			}
		}

		// Apply explicit assistant preference (highest signal).
		$slug = method_exists( $tool, 'get_slug' ) ? sanitize_key( $tool->get_slug() ) : '';
		if ( '' !== $slug && isset( $assistant_prefs[ $slug ] ) ) {
			$score += (float) $assistant_prefs[ $slug ];
		}

		/**
		 * Filter the harness score for a tool.
		 *
		 * @param float                    $score             Default score from the base scoring rules.
		 * @param WP_MCP_AI_Tool_Interface $tool              Tool instance.
		 * @param string                   $task_class        Task class slug.
		 * @param array                    $assistant_prefs   Per-assistant preferences.
		 */
		$score = (float) apply_filters( 'wp_mcp_ai_harness_tool_score', $score, $tool, $task_class, $assistant_prefs );

		return $score;
	}

	/**
	 * Rank an iterable of tools for a task class. Returns slug => score
	 * sorted descending.
	 *
	 * @param iterable $tools           Tool instances.
	 * @param string   $task_class      Task class.
	 * @param array    $assistant_prefs Optional per-assistant preferences.
	 * @return array<string,float>
	 */
	public static function rank( $tools, $task_class, array $assistant_prefs = array() ) {
		$scored = array();
		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}
			$slug = sanitize_key( $tool->get_slug() );
			if ( '' === $slug ) {
				continue;
			}
			$scored[ $slug ] = self::score_tool( $tool, $task_class, $assistant_prefs );
		}
		arsort( $scored, SORT_NUMERIC );
		return $scored;
	}
}
