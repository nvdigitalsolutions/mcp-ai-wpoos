<?php
/**
 * Hooks bridge that wires the CoSAI capability boundary and approval gate
 * into the existing `wp_mcp_ai_before_tool_execution` action.
 *
 * This is the sole integration point between the CoSAI agent infrastructure
 * and the production tool-execution path (REST handler, chat loop, schedule
 * manager). It runs at priority 1 — before any other listener — so that
 * blocked tools never reach measurement observers or metric collectors.
 *
 * ## Design Intent (CoSAI Integration)
 *
 * - Priority 1 on `wp_mcp_ai_before_tool_execution` ensures the boundary
 *   check is the first thing that happens before any tool executes.
 * - The boundary is resolved from a transient (`wp_mcp_ai_boundary_current`),
 *   which the chat handler sets at the start of an agentic session.
 * - When no boundary is active, all tools are allowed (backward compatible).
 *
 * Extracted from `AgentCapabilityBoundary.php` so the PSR-4 autoloader can
 * resolve the class (one class per file).
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks bridge for capability boundary and approval gate.
 *
 * @since 1.2.0
 */
class AgentCapabilityBoundaryHooks {

	/**
	 * Register the CoSAI gate hooks.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before_tool_execution' ), 1, 3 );
	}

	/**
	 * Pre-execution gate: enforce capability boundary and approval gate.
	 *
	 * Hooked at priority 1 on `wp_mcp_ai_before_tool_execution`.
	 *
	 * If a boundary is active for the current session, this method:
	 * 1. Checks the tool is in the immutable allow-list (`can_execute`)
	 * 2. Checks the tool hasn't exceeded its rate limit (`check_rate_limit`)
	 * 3. Checks the session budget isn't exhausted (`is_budget_exhausted`)
	 * 4. Checks the Agent Approval Gate for human-governed actions
	 *
	 * Violations throw a `WP_Error` via `wp_die()` to halt the request.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_slug  Tool identifier.
	 * @param array  $arguments  Sanitised tool arguments.
	 * @param array  $context    Execution context (assistant_id, user_id, etc.).
	 * @return void
	 */
	public static function on_before_tool_execution( $tool_slug, $arguments, $context ) {
		$boundary = self::get_current_boundary();

		if ( ! $boundary instanceof AgentCapabilityBoundary ) {
			return; // No boundary active — allow all tools (backward compatible).
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- WP_Error objects passed to wp_die() are safe.
		// Gate 1: Allow-list.
		if ( ! $boundary->can_execute( $tool_slug ) ) {
			wp_die(
				new \WP_Error(
					'wp_mcp_ai_boundary_blocked',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" is not allowed in this agent session.', 'nvoos-content-graph-ai-platform' ),
						esc_html( $tool_slug )
					),
					array( 'status' => 403 )
				),
				403
			);
		}

		// Gate 2: Rate limit.
		if ( ! $boundary->check_rate_limit( $tool_slug ) ) {
			wp_die(
				new \WP_Error(
					'wp_mcp_ai_boundary_rate_limited',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" has exceeded its rate limit for this session.', 'nvoos-content-graph-ai-platform' ),
						esc_html( $tool_slug )
					),
					array( 'status' => 429 )
				),
				429
			);
		}

		// Gate 3: Budget exhaustion.
		if ( $boundary->is_budget_exhausted() ) {
			wp_die(
				new \WP_Error(
					'wp_mcp_ai_boundary_budget_exhausted',
					__( 'Agent session budget exhausted — maximum iterations reached.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 429 )
				),
				429
			);
		}

		// Gate 4: Human approval (CoSAI Principle 1).
		$session_id   = $boundary->get_session_id();
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		$approval = AgentApprovalGate::request_approval(
			$session_id,
			'tool_execution',
			array(
				'tool_slug'    => $tool_slug,
				'arguments'    => $arguments,
				'assistant_id' => $assistant_id,
			),
			'low' // Tool execution defaults to low risk; individual tools can escalate via filter.
		);

		if ( is_wp_error( $approval ) ) {
			wp_die( $approval, 403 );
		}

		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Retrieve the current capability boundary from the session transient.
	 *
	 * The chat handler is responsible for calling `set_current_boundary()`
	 * at the start of an agentic loop and `clear_current_boundary()` when
	 * the loop ends. If no boundary was set, this returns null.
	 *
	 * @since 1.2.0
	 *
	 * @return AgentCapabilityBoundary|null
	 */
	private static function get_current_boundary() {
		$data = get_transient( 'wp_mcp_ai_boundary_current' );

		if ( ! is_array( $data ) || empty( $data['session_id'] ) ) {
			return null;
		}

		$session_id = sanitize_key( $data['session_id'] );
		$tool_slugs = isset( $data['allowed_tool_slugs'] ) ? (array) $data['allowed_tool_slugs'] : array();

		return new AgentCapabilityBoundary( $session_id, $tool_slugs );
	}

	/**
	 * Set the active capability boundary for the current request.
	 *
	 * Call this at the start of an agentic chat loop.
	 *
	 * @since 1.2.0
	 *
	 * @param string        $session_id        Session identifier.
	 * @param array<string> $allowed_tool_slugs Tool slugs allowed in this session.
	 * @return void
	 */
	public static function set_current_boundary( $session_id, array $allowed_tool_slugs ) {
		set_transient(
			'wp_mcp_ai_boundary_current',
			array(
				'session_id'         => sanitize_key( $session_id ),
				'allowed_tool_slugs' => array_map( 'sanitize_key', $allowed_tool_slugs ),
				'created_at'         => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Clear the active capability boundary after the agentic loop ends.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function clear_current_boundary() {
		delete_transient( 'wp_mcp_ai_boundary_current' );
	}
}
