<?php
/**
 * Evolved Prompt Resolver — opt-in consumption of evolved system prompts.
 *
 * Subscribes to the `wp_mcp_ai_resolved_system_prompt` filter and swaps in
 * the harness-evolved system prompt (`_wp_mcp_ai_evolved_system_prompt`) only
 * when the site opts in via the `wp_mcp_ai_harness_use_evolved_prompt` filter.
 * By default the resolver is a complete no-op — evolved prompts are never
 * applied automatically (CoSAI Principle 1: human oversight).
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @subpackage NvoosContentGraphAiPlatform/src/Agents
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @see AgentHarnessEvolver Core evolution engine that produces evolved prompts.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evolved Prompt Resolver class.
 *
 * @since 1.9.0
 */
class EvolvedPromptResolver {

	/**
	 * Whether the resolver has registered its filter.
	 *
	 * @since 1.9.0
	 * @var   bool
	 */
	private static $registered = false;

	/**
	 * Register the resolver on the system-prompt resolution filter.
	 *
	 * Priority 15 sits between the harness prompt injector (10) and the
	 * guardrail instruction layer (20), so an evolved prompt still receives
	 * both cue injection and guardrail screening.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'wp_mcp_ai_resolved_system_prompt', array( __CLASS__, 'filter' ), 15, 3 );
	}

	/**
	 * Swap in the evolved system prompt when opted in.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prompt       Resolved system prompt.
	 * @param int    $assistant_id Assistant post ID.
	 * @param array  $context      Resolution context (e.g. surface).
	 * @return string Possibly-evolved system prompt.
	 */
	public static function filter( $prompt, $assistant_id, $context = array() ) {
		$assistant_id = (int) $assistant_id;

		// Shadow serving (Phase F): when shadow mode is enabled for this
		// assistant and a candidate is registered, deterministically bucketed
		// sessions receive the candidate WITHOUT it being deployed. Every serve
		// decision is recorded for later comparison via the Trace Store.
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Shadow' ) ) && \WP_MCP_AI_Artifact_Shadow::is_enabled( $assistant_id, 'prompt' ) ) {
			$shadow = \WP_MCP_AI_Artifact_Shadow::get_candidate( $assistant_id, 'prompt' );
			$hash   = isset( $shadow['hash'] ) ? (string) $shadow['hash'] : '';
			$serve  = \WP_MCP_AI_Artifact_Shadow::should_serve_candidate( $assistant_id, 'prompt', $hash, (array) $context );

			\WP_MCP_AI_Artifact_Shadow::record_serve( $assistant_id, 'prompt', $hash, $serve );

			if ( $serve && isset( $shadow['payload'] ) && is_string( $shadow['payload'] ) && '' !== trim( $shadow['payload'] ) ) {
				return $shadow['payload'];
			}
		}

		/**
		 * Filters whether the evolved system prompt should be used.
		 *
		 * Default false — evolved prompts are never applied automatically.
		 *
		 * @since 1.9.0
		 *
		 * @param bool  $use_evolved  Whether to use the evolved prompt. Default false.
		 * @param int   $assistant_id Assistant post ID.
		 * @param array $context      Resolution context.
		 */
		$use_evolved = apply_filters( 'wp_mcp_ai_harness_use_evolved_prompt', false, (int) $assistant_id, (array) $context );
		if ( ! $use_evolved ) {
			return $prompt;
		}

		$evolved = get_post_meta( (int) $assistant_id, '_wp_mcp_ai_evolved_system_prompt', true );
		if ( is_string( $evolved ) && '' !== trim( $evolved ) ) {
			return $evolved;
		}

		return $prompt;
	}
}
